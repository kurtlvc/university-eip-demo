package com.university.registrar;

import org.apache.camel.LoggingLevel;
import org.apache.camel.builder.RouteBuilder;
import org.springframework.stereotype.Component;

/**
 * Error Handling (EIP) - receiving end of the error channel.
 *
 * enrollment.errors is a separate, durable queue that EnrollmentRoute routes
 * to whenever a message can't be processed (after retries are exhausted for
 * transient failures, or immediately for permanent/validation failures).
 * This keeps failed messages from being silently dropped or stuck retrying
 * forever, and gives you a queue you can watch in the RabbitMQ management UI
 * (localhost:15672) separately from the healthy request flow.
 */
@Component
public class EnrollmentErrorRoute extends RouteBuilder {

    @Override
    public void configure() {
        // autoDeclare defaults to true on this component and MUST be explicitly
        // set to false here - see RabbitMQConfig for why, and where this queue
        // actually gets declared (queue-only, no binding).
        from("spring-rabbitmq:default?queues=enrollment.errors&autoDeclare=false")
            .routeId("enrollment-error-consumer")
            .log(LoggingLevel.ERROR, "Message landed on error channel: ${body}")
            .bean(EnrollmentErrorProcessor.class, "logError");
    }
}
