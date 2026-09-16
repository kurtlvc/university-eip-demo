package com.university.registrar;

import org.springframework.stereotype.Component;

import java.time.Instant;

/**
 * Message Transformer (EIP).
 *
 * Takes the raw, loosely-shaped message coming off the wire and turns it
 * into a normalized, validated, enriched form before anything downstream
 * (router, aggregator, processor) has to deal with it:
 *
 *  - Trims whitespace and uppercases course_id, so "cs301" and " CS301 "
 *    and "CS301" are all treated as the same course.
 *  - Defaults a missing "action" to ENROLL, so older/simpler producers
 *    (like the current PHP form) that don't send an action field still work.
 *  - Stamps a processedAt timestamp, recording when the message entered
 *    the registrar pipeline (distinct from requested_at, which is when the
 *    student submitted the form).
 *  - Rejects structurally invalid messages by throwing IllegalArgumentException.
 *    This is treated as a non-retryable error by EnrollmentRoute - retrying a
 *    message that's missing required fields will never succeed, so it goes
 *    straight to the error channel instead of being redelivered.
 */
@Component
public class EnrollmentTransformer {

    public EnrollmentRequest transform(EnrollmentRequest request) {
        if (isBlank(request.getStudentId()) || isBlank(request.getCourseId()) || isBlank(request.getTerm())) {
            throw new IllegalArgumentException(
                "Missing required field(s) (student_id/course_id/term) in enrollment message: " + request);
        }

        request.setStudentId(request.getStudentId().trim());
        request.setCourseId(request.getCourseId().trim().toUpperCase());
        request.setTerm(request.getTerm().trim());

        String action = request.getAction();
        request.setAction(isBlank(action) ? "ENROLL" : action.trim().toUpperCase());

        request.setProcessedAt(Instant.now().toString());

        return request;
    }

    private boolean isBlank(String s) {
        return s == null || s.trim().isEmpty();
    }
}
