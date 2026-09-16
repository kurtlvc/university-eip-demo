package com.university.registrar;

import org.apache.camel.Exchange;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Component;

/**
 * Consumer-side half of the Error Handling pattern: persists whatever lands
 * on the enrollment.errors queue (the error channel) so failed messages are
 * inspectable instead of just vanishing into a log line.
 *
 * See enrollment_errors table in mysql/init.sql.
 */
@Component
public class EnrollmentErrorProcessor {

    @Autowired
    private JdbcTemplate jdbcTemplate;

    public void logError(Exchange exchange) {
        String rawMessage = exchange.getIn().getBody(String.class);
        String errorMessage = exchange.getIn().getHeader("X-Error-Message", String.class);

        jdbcTemplate.update(
            "INSERT INTO enrollment_errors (raw_message, error_message) VALUES (?, ?)",
            rawMessage,
            errorMessage != null ? errorMessage : "Unknown error"
        );
    }
}
