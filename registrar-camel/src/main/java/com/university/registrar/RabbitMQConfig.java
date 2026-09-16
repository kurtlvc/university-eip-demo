package com.university.registrar;

import org.springframework.amqp.core.Queue;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

/**
 * Declares the two durable queues this app depends on, using Spring AMQP's
 * auto-configured RabbitAdmin.
 *
 * Why this exists instead of relying on Camel's autoDeclare=true: both
 * queues live on RabbitMQ's default (nameless) exchange, matching how the
 * PHP publisher writes to it (basic_publish to exchange ''). AMQP forbids
 * *explicit* bindings to the default exchange - every queue is implicitly
 * bound to it already, by queue name. Camel's spring-rabbitmq consumer with
 * autoDeclare=true doesn't know that and tries to bind anyway, which
 * RabbitMQ refuses with "access_refused: operation not permitted on the
 * default exchange" - killing the channel before the consumer ever starts.
 *
 * A plain Queue bean (no Binding bean) only issues queue.declare, never
 * queue.bind, so it's safe on the default exchange. Spring Boot's
 * RabbitAutoConfiguration declares any Queue @Bean automatically at startup
 * via its RabbitAdmin.
 */
@Configuration
public class RabbitMQConfig {

    @Bean
    public Queue enrollmentRequestsQueue() {
        return new Queue("enrollment.requests", true);
    }

    @Bean
    public Queue enrollmentErrorsQueue() {
        return new Queue("enrollment.errors", true);
    }
}
