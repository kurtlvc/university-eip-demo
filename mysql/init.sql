CREATE TABLE IF NOT EXISTS courses (
  course_id     VARCHAR(20) NOT NULL,
  term          VARCHAR(20) NOT NULL,
  course_name   VARCHAR(100) NOT NULL,
  capacity      INT NOT NULL,
  seats_taken   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (course_id, term)
);

CREATE TABLE IF NOT EXISTS enrollments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  student_id    VARCHAR(20) NOT NULL,
  course_id     VARCHAR(20) NOT NULL,
  term          VARCHAR(20) NOT NULL,
  status        VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_enrollment (student_id, course_id, term)
);

CREATE TABLE IF NOT EXISTS enrollment_errors (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  raw_message   TEXT,
  error_message VARCHAR(500),
  failed_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO courses (course_id, term, course_name, capacity, seats_taken) VALUES
  ('ITP103',   'Second Sem', 'System Integration and Architecture', 3, 0),
  ('ITP104', 'Second Sem', 'Information Management',  40, 0),
  ('ITEW3', 'Second Sem', 'Mobile Programming',   50, 0)
ON DUPLICATE KEY UPDATE course_name = VALUES(course_name);
