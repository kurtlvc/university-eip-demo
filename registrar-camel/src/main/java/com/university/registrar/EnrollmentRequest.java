package com.university.registrar;

import com.fasterxml.jackson.annotation.JsonProperty;

/**
 * Maps the JSON message the PHP Enrollment System publishes onto the
 * EnrollmentRequestChannel (enrollment.requests queue).
 */
public class EnrollmentRequest {

    @JsonProperty("student_id")
    private String studentId;

    @JsonProperty("course_id")
    private String courseId;

    @JsonProperty("term")
    private String term;

    @JsonProperty("requested_at")
    private String requestedAt;

    /** ENROLL or DROP. Drives the Content-Based Router. Defaults to ENROLL if absent (see EnrollmentTransformer). */
    @JsonProperty("action")
    private String action;

    /** Stamped by EnrollmentTransformer - demonstrates the Message Transformer enriching the message. */
    private String processedAt;

    public String getStudentId() { return studentId; }
    public void setStudentId(String studentId) { this.studentId = studentId; }

    public String getCourseId() { return courseId; }
    public void setCourseId(String courseId) { this.courseId = courseId; }

    public String getTerm() { return term; }
    public void setTerm(String term) { this.term = term; }

    public String getRequestedAt() { return requestedAt; }
    public void setRequestedAt(String requestedAt) { this.requestedAt = requestedAt; }

    public String getAction() { return action; }
    public void setAction(String action) { this.action = action; }

    public String getProcessedAt() { return processedAt; }
    public void setProcessedAt(String processedAt) { this.processedAt = processedAt; }

    @Override
    public String toString() {
        return "EnrollmentRequest{studentId=" + studentId + ", courseId=" + courseId
             + ", term=" + term + ", action=" + action + "}";
    }
}
