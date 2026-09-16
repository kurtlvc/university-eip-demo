package com.university.registrar;

import com.fasterxml.jackson.core.JsonProcessingException;
import org.apache.camel.LoggingLevel;
import org.apache.camel.builder.RouteBuilder;
import org.apache.camel.model.dataformat.JsonLibrary;
import org.springframework.dao.DataAccessException;
import org.springframework.stereotype.Component;

import java.sql.SQLException;

/**
 * Receiver-side endpoint of the EnrollmentRequestChannel, and home of most
 * of the EIP wiring for this demo:
 *
 *  1. Message Channel   - the spring-rabbitmq consumer below (Point-to-Point:
 *                          RabbitMQ guarantees only one instance of this
 *                          service ever consumes a given message).
 *  2. Message Transformer - EnrollmentTransformer normalizes/validates/enriches.
 *  3. Content-Based Router - the .choice() block branches ENROLL vs DROP.
 *  4. Aggregator           - direct:enrollAggregator batches ENROLL requests
 *                            per course/term (see EnrollmentAggregationStrategy).
 *  5. Error Handling       - errorHandler()/onException() below classify
 *                            failures as retryable vs permanent and route
 *                            both to the enrollment.errors channel, consumed
 *                            by EnrollmentErrorRoute.
 */
@Component
public class EnrollmentRoute extends RouteBuilder {

    // autoDeclare is deliberately left off (false): these queues are
    // declared once, queue-only (no binding), by RabbitMQConfig. See its
    // Javadoc for why Camel's own autoDeclare can't be used here - it tries
    // to bind to the default exchange, which AMQP forbids.
    private static final String ERROR_QUEUE = "spring-rabbitmq:default?queues=enrollment.errors";

    @Override
    public void configure() {

        // ---- Error Handling: default/fallback policy ----
        // Anything not matched by a more specific onException() below gets
        // retried up to 3 times with exponential backoff, then dead-lettered
        // to the error queue with the ORIGINAL (pre-processing) message body,
        // so nothing is silently lost.
        errorHandler(deadLetterChannel(ERROR_QUEUE)
            .maximumRedeliveries(3)
            .redeliveryDelay(1000)
            .backOffMultiplier(2)
            .useExponentialBackOff()
            .retryAttemptedLogLevel(LoggingLevel.WARN)
            .logExhausted(true)
            .useOriginalMessage());

        // Permanent failures: malformed JSON or missing required fields.
        // Retrying these can never succeed, so skip straight to the error
        // channel with zero redeliveries instead of wasting 3 retry cycles.
        onException(IllegalArgumentException.class, JsonProcessingException.class)
            .maximumRedeliveries(0)
            .handled(true)
            .log(LoggingLevel.ERROR, "Non-retryable error, routing to error channel: ${exception.message}")
            .setHeader("X-Error-Message", simple("${exception.message}"))
            .convertBodyTo(String.class)
            .to(ERROR_QUEUE);

        // Transient failures: the database hiccupping, a lock timeout, a
        // dropped connection. Worth a few retries with backoff before giving
        // up, since the same message would likely succeed a moment later.
        onException(DataAccessException.class, SQLException.class)
            .maximumRedeliveries(3)
            .redeliveryDelay(1000)
            .backOffMultiplier(2)
            .useExponentialBackOff()
            .retryAttemptedLogLevel(LoggingLevel.WARN)
            .handled(true)
            .log(LoggingLevel.ERROR, "DB error after retries exhausted, routing to error channel: ${exception.message}")
            .setHeader("X-Error-Message", simple("${exception.message}"))
            .convertBodyTo(String.class)
            .to(ERROR_QUEUE);

        // ---- Message Channel (Point-to-Point) ----
        // "default" = RabbitMQ's nameless default exchange, where routing key == queue
        // name. This matches how the PHP side publishes (basic_publish to exchange '').
        // autoDeclare defaults to true on this component, and MUST be explicitly
        // set to false here: the queue is declared once, queue-only (no bind), by
        // RabbitMQConfig - see its Javadoc for why Camel's own autoDeclare can't be
        // used against the default exchange (it tries to bind, which AMQP forbids).
        from("spring-rabbitmq:default?queues=enrollment.requests&autoDeclare=false")
            .routeId("enrollment-request-consumer")
            .log("Received raw enrollment message: ${body}")
            .unmarshal().json(JsonLibrary.Jackson, EnrollmentRequest.class)

            // ---- Message Transformer ----
            .bean(EnrollmentTransformer.class, "transform")
            .log("Transformed request: ${body}")

            // ---- Content-Based Router ----
            .choice()
                .when(simple("${body.action} == 'DROP'"))
                    .to("direct:processDrop")
                .when(simple("${body.action} == 'ENROLL'"))
                    .to("direct:enrollAggregator")
                .otherwise()
                    .throwException(new IllegalArgumentException("Unknown action type"))
            .end();

        // ---- Aggregator ----
        // Batches ENROLL requests that share a course_id+term correlation key.
        // Releases the batch once either 5 requests have accumulated or 3s
        // have elapsed since the first one arrived, whichever comes first.
        from("direct:enrollAggregator")
            .routeId("enrollment-aggregator")
            .aggregate(simple("${body.courseId}-${body.term}"), new EnrollmentAggregationStrategy())
                .completionSize(5)
                .completionTimeout(3000)
            .log("Releasing aggregated batch of ${body.size} ENROLL request(s)")
            .bean(EnrollmentProcessor.class, "processBatch")
            .log("Finished processing aggregated batch");

        // ---- DROP handling ----
        // Not aggregated - drops free a seat immediately and shouldn't wait
        // on a batch window the way competing ENROLLs benefit from.
        from("direct:processDrop")
            .routeId("enrollment-drop-handler")
            .bean(EnrollmentProcessor.class, "processDrop")
            .log("Finished processing drop request");
    }
}
