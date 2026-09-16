package com.university.registrar;

import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Component;
import org.springframework.transaction.annotation.Transactional;

import java.util.List;

/**
 * The actual "Registrar" business logic: the single authority that decides
 * whether an enrollment request is honored. Runs inside a transaction so the
 * seat-count check-and-increment is atomic, even if multiple messages arrive
 * concurrently.
 */
@Component
public class EnrollmentProcessor {

    @Autowired
    private JdbcTemplate jdbcTemplate;

    /**
     * Entry point for the Aggregator: processes a whole batch of ENROLL
     * requests for the same course/term in a single transaction. If any one
     * enrollment in the batch throws, the whole batch rolls back and the
     * batch is routed to the error channel (see EnrollmentRoute) rather than
     * silently processing some students but not others.
     */
    @Transactional
    public void processBatch(List<EnrollmentRequest> batch) {
        for (EnrollmentRequest request : batch) {
            validateAndEnroll(request);
        }
    }

    /**
     * Handles the DROP branch of the Content-Based Router. Not batched -
     * drops free up a seat immediately, and there's no write-contention
     * reason to delay them the way there is for competing ENROLL requests.
     */
    @Transactional
    public void processDrop(EnrollmentRequest request) {
        String studentId = request.getStudentId();
        String courseId = request.getCourseId();
        String term = request.getTerm();

        jdbcTemplate.update(
            "UPDATE courses SET seats_taken = GREATEST(seats_taken - 1, 0) " +
            "WHERE course_id = ? AND term = ?",
            courseId, term
        );

        int updated = jdbcTemplate.update(
            "UPDATE enrollments SET status = 'DROPPED' " +
            "WHERE student_id = ? AND course_id = ? AND term = ? AND status = 'ENROLLED'",
            studentId, courseId, term
        );

        if (updated == 0) {
            // Nothing to drop - record the attempt anyway so it's visible.
            upsertStatus(studentId, courseId, term, "DROP_NO_MATCH");
        }
    }

    public void validateAndEnroll(EnrollmentRequest request) {
        String studentId = request.getStudentId();
        String courseId = request.getCourseId();
        String term = request.getTerm();

        Integer alreadyEnrolled = jdbcTemplate.queryForObject(
            "SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND course_id = ? AND term = ? AND status = 'ENROLLED'",
            Integer.class, studentId, courseId, term
        );

        if (alreadyEnrolled != null && alreadyEnrolled > 0) {
            upsertStatus(studentId, courseId, term, "DUPLICATE");
            return;
        }

        // Atomic seat check: only increments if a seat is actually available.
        int updated = jdbcTemplate.update(
            "UPDATE courses SET seats_taken = seats_taken + 1 " +
            "WHERE course_id = ? AND term = ? AND seats_taken < capacity",
            courseId, term
        );

        String status = (updated > 0) ? "ENROLLED" : "REJECTED_NO_SEATS";
        upsertStatus(studentId, courseId, term, status);
    }

    private void upsertStatus(String studentId, String courseId, String term, String status) {
        jdbcTemplate.update(
            "INSERT INTO enrollments (student_id, course_id, term, status) VALUES (?, ?, ?, ?) " +
            "ON DUPLICATE KEY UPDATE status = VALUES(status)",
            studentId, courseId, term, status
        );
    }
}
