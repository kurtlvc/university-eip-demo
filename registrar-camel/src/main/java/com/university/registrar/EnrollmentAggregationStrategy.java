package com.university.registrar;

import org.apache.camel.AggregationStrategy;
import org.apache.camel.Exchange;

import java.util.ArrayList;
import java.util.List;

/**
 * Aggregator (EIP).
 *
 * Consolidates individual ENROLL messages that share the same correlation
 * key (course_id + term, set in EnrollmentRoute) into a single List so they
 * can be processed together as one batch by EnrollmentProcessor#processBatch.
 *
 * Why batch enrollments at all: seat allocation for a given course is a
 * point of write contention (see EnrollmentProcessor's atomic UPDATE ...
 * WHERE seats_taken < capacity). Grouping requests for the same course into
 * one transaction reduces the number of separate row-lock acquisitions on
 * that course's row, instead of one transaction per student.
 *
 * The batch is released (a "completion") when either:
 *  - completionSize is reached (5 requests for the same course/term), or
 *  - completionTimeout elapses (3s) since the first message in the batch,
 *    whichever happens first - see the .aggregate(...) configuration in
 *    EnrollmentRoute.
 */
public class EnrollmentAggregationStrategy implements AggregationStrategy {

    @Override
    @SuppressWarnings("unchecked")
    public Exchange aggregate(Exchange oldExchange, Exchange newExchange) {
        EnrollmentRequest incoming = newExchange.getIn().getBody(EnrollmentRequest.class);

        if (oldExchange == null) {
            List<EnrollmentRequest> batch = new ArrayList<>();
            batch.add(incoming);
            newExchange.getIn().setBody(batch);
            return newExchange;
        }

        List<EnrollmentRequest> batch = oldExchange.getIn().getBody(List.class);
        batch.add(incoming);
        oldExchange.getIn().setBody(batch);
        return oldExchange;
    }
}
