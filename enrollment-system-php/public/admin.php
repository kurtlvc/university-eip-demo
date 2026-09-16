<?php

/**
 * Registrar Admin Dashboard — single-file administrator tool
 * tool for interacting with the whole system while testing/demoing the EIP
 * patterns.
 *
 * This is deliberately kept separate from index.php: the Enrollment System's
 * own form still never touches MySQL (the Registrar remains the sole writer
 * of enrollment data). Enrollment state changes still go through RabbitMQ;
 * course catalogue administration is the dashboard's explicit exception.
 *
 * Three things live here:
 *   1. A form that publishes well-formed ENROLL/DROP requests (same as
 *      index.php) — convenience so you don't need two tabs open.
 *   2. A "raw message" publisher that sends whatever text you type,
 *      unvalidated — the easiest way to trigger the error-handling patterns
 *      (missing fields, malformed JSON) without using RabbitMQ's own UI.
 *   3. Registrar-only course creation and capacity/name management.
 *   4. Read-only views of courses, recent enrollments, and enrollment_errors.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\RabbitMQPublisher;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$message = null;
$error = null;

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('MYSQL_HOST') ?: 'mysql';
        $port = getenv('MYSQL_PORT') ?: '3306';
        $db   = getenv('MYSQL_DB') ?: 'registrar';
        $user = getenv('MYSQL_USER') ?: 'registrar_user';
        $pass = getenv('MYSQL_PASS') ?: 'registrar_pass';

        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

/** Publishes a raw string body to enrollment.requests, bypassing all validation. */
function publishRaw(string $body): void
{
    $host = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
    $port = (int) (getenv('RABBITMQ_PORT') ?: 5672);
    $user = getenv('RABBITMQ_USER') ?: 'guest';
    $pass = getenv('RABBITMQ_PASS') ?: 'guest';

    $connection = new AMQPStreamConnection($host, $port, $user, $pass);
    $channel = $connection->channel();
    $channel->queue_declare('enrollment.requests', false, true, false, false);

    $message = new AMQPMessage($body, [
        'content_type'  => 'application/json',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
    ]);
    $channel->basic_publish($message, '', 'enrollment.requests');

    $channel->close();
    $connection->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'structured') {
        $studentId = trim($_POST['student_id'] ?? '');
        $courseId  = trim($_POST['course_id'] ?? '');
        $term      = trim($_POST['term'] ?? '');
        $action    = trim($_POST['action'] ?? 'ENROLL');

        if ($studentId === '' || $courseId === '' || $term === '') {
            $error = 'All fields are required for a structured request.';
        } else {
            try {
                (new RabbitMQPublisher())->publishEnrollmentRequest([
                    'student_id'   => $studentId,
                    'course_id'    => $courseId,
                    'term'         => $term,
                    'action'       => $action,
                    'requested_at' => date('c'),
                ]);
                $message = "Published: {$action} {$studentId} -> {$courseId} ({$term}).";
            } catch (\Throwable $e) {
                $error = 'Publish failed: ' . $e->getMessage();
            }
        }
    } elseif ($formType === 'raw') {
        $raw = $_POST['raw_body'] ?? '';
        if (trim($raw) === '') {
            $error = 'Raw message body cannot be empty.';
        } else {
            try {
                publishRaw($raw);
                $message = 'Raw message published as-is (no validation applied on this end).';
            } catch (\Throwable $e) {
                $error = 'Publish failed: ' . $e->getMessage();
            }
        }
    } elseif ($formType === 'create_course') {
        $courseId = strtoupper(trim($_POST['course_id'] ?? ''));
        $term = trim($_POST['term'] ?? '');
        $courseName = trim($_POST['course_name'] ?? '');
        $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($courseId === '' || $term === '' || $courseName === '' || $capacity === false) {
            $error = 'Course ID, term, name, and a non-negative capacity are required.';
        } elseif (strlen($courseId) > 20 || strlen($term) > 20 || strlen($courseName) > 100) {
            $error = 'One or more course fields exceed the database length limit.';
        } else {
            try {
                pdo()->prepare(
                    'INSERT INTO courses (course_id, term, course_name, capacity) VALUES (?, ?, ?, ?)'
                )->execute([$courseId, $term, $courseName, $capacity]);
                $message = "Course {$courseId} ({$term}) created.";
            } catch (\PDOException $e) {
                $error = $e->getCode() === '23000'
                    ? 'That course and term already exist.'
                    : 'Could not create the course: ' . $e->getMessage();
            }
        }
    } elseif ($formType === 'update_course') {
        $courseId = trim($_POST['course_id'] ?? '');
        $term = trim($_POST['term'] ?? '');
        $courseName = trim($_POST['course_name'] ?? '');
        $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($courseId === '' || $term === '' || $courseName === '' || $capacity === false) {
            $error = 'A course name and non-negative capacity are required.';
        } elseif (strlen($courseName) > 100) {
            $error = 'Course name exceeds the database length limit.';
        } else {
            try {
                $updated = pdo()->prepare(
                    'UPDATE courses SET course_name = ?, capacity = ? '
                    . 'WHERE course_id = ? AND term = ? AND seats_taken <= ?'
                );
                $updated->execute([$courseName, $capacity, $courseId, $term, $capacity]);
                $message = $updated->rowCount() > 0
                    ? "Course {$courseId} ({$term}) updated."
                    : 'Course was not updated: it may not exist, already have these values, or have more enrolled students than the requested capacity.';
            } catch (\Throwable $e) {
                $error = 'Could not update the course: ' . $e->getMessage();
            }
        }
    }
}

$dbError = null;
$courses = $enrollments = $enrollmentErrors = [];
try {
    $courses = pdo()->query(
        'SELECT course_id, term, course_name, capacity, seats_taken FROM courses ORDER BY course_id, term'
    )->fetchAll(PDO::FETCH_ASSOC);

    $enrollments = pdo()->query(
        'SELECT student_id, course_id, term, status, created_at FROM enrollments ORDER BY created_at DESC LIMIT 20'
    )->fetchAll(PDO::FETCH_ASSOC);

    $enrollmentErrors = pdo()->query(
        'SELECT raw_message, error_message, failed_at FROM enrollment_errors ORDER BY failed_at DESC LIMIT 20'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $dbError = 'Could not read from MySQL: ' . $e->getMessage()
        . ' (if this is a fresh checkout, make sure you ran "docker compose down -v && docker compose up --build" '
        . 'so init.sql creates the enrollment_errors table).';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>I-Enroll Registrar Admin Dashboard</title>
    <style>
        body { font-family: sans-serif; max-width: 960px; margin: 40px auto; padding: 0 16px; color: #222; }
        h1 { margin-bottom: 4px; }
        h2 { margin-top: 40px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
        .panels { display: flex; gap: 24px; flex-wrap: wrap; }
        .panel { flex: 1; min-width: 300px; background: #fafafa; border: 1px solid #ddd; border-radius: 6px; padding: 16px; }
        label { display: block; margin-top: 10px; font-size: 0.9em; }
        input, select, textarea { width: 100%; padding: 6px; box-sizing: border-box; font-family: inherit; }
        textarea { font-family: monospace; font-size: 0.85em; height: 90px; }
        button { margin-top: 12px; padding: 8px 16px; cursor: pointer; }
        table { border-collapse: collapse; width: 100%; margin-top: 8px; font-size: 0.85em; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f0f0f0; }
        .ok { color: #2e7d32; }
        .err { color: #c62828; }
        .status-ENROLLED { color: #2e7d32; }
        .status-REJECTED_NO_SEATS, .status-DUPLICATE { color: #c62828; }
        .status-DROPPED, .status-DROP_NO_MATCH { color: #6a1b9a; }
        .note { font-size: 0.85em; color: #666; }
        .refresh { float: right; font-size: 0.8em; }
    </style>
</head>
<body>
    <h1>Registrar Admin Dashboard</h1>
    <p class="note">
        Enrollment requests go through RabbitMQ. Course catalogue changes below
        are registrar-admin actions and write directly to MySQL.
    </p>

    <?php if ($message): ?><p class="ok"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <?php if ($dbError): ?><p class="err"><?= htmlspecialchars($dbError) ?></p><?php endif; ?>

    <div class="panels">
        <div class="panel">
            <h3>Publish a request</h3>
            <p class="note">Exercises the Message Transformer + Content-Based Router + Aggregator.</p>
            <form method="post">
                <input type="hidden" name="form_type" value="structured">
                <label>Student ID <input name="student_id" required placeholder="e.g. 12345"></label>
                <label>Course ID <input name="course_id" required placeholder="e.g. CS301"></label>
                <label>Term <input name="term" required value="Fall2026"></label>
                <label>Action
                    <select name="action">
                        <option value="ENROLL">Enroll</option>
                        <option value="DROP">Drop</option>
                    </select>
                </label>
                <button type="submit">Publish</button>
            </form>
        </div>

        <div class="panel">
            <h3>Add a course</h3>
            <p class="note">Creates a new course offering with zero seats taken.</p>
            <form method="post">
                <input type="hidden" name="form_type" value="create_course">
                <label>Course ID <input name="course_id" required maxlength="20" placeholder="e.g. ITP103"></label>
                <label>Term <input name="term" required maxlength="20" value="Second Sem"></label>
                <label>Course name <input name="course_name" required maxlength="100" placeholder="e.g. System Integration and Architecture"></label>
                <label>Capacity <input name="capacity" required type="number" min="0" value="30"></label>
                <button type="submit">Create course</button>
            </form>
        </div>

        <!-- <div class="panel">
            <h3>Publish a raw message</h3>
            <p class="note">
                Sent unvalidated — use this to trigger the Error Handling pattern.
                Try: <code>{"course_id":"CS301","term":"Fall2026"}</code> (missing student_id)
                or malformed JSON like <code>{not valid json</code>.
            </p>
            <form method="post">
                <input type="hidden" name="form_type" value="raw">
                <textarea name="raw_body" placeholder='{"student_id":"999","course_id":"CS301","term":"Fall2026"}'></textarea>
                <button type="submit">Publish Raw</button>
            </form>
        </div> -->
    </div>

    <h2>Courses <a class="refresh" href="">refresh</a></h2>
    <table>
        <tr><th>Course</th><th>Term</th><th>Name</th><th>Capacity</th><th>Seats Taken</th><th>Manage</th></tr>
        <?php foreach ($courses as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['course_id']) ?></td>
            <td><?= htmlspecialchars($c['term']) ?></td>
            <td><?= htmlspecialchars($c['course_name']) ?></td>
            <td><?= htmlspecialchars($c['capacity']) ?></td>
            <td><?= htmlspecialchars($c['seats_taken']) ?></td>
            <td>
                <form method="post">
                    <input type="hidden" name="form_type" value="update_course">
                    <input type="hidden" name="course_id" value="<?= htmlspecialchars($c['course_id']) ?>">
                    <input type="hidden" name="term" value="<?= htmlspecialchars($c['term']) ?>">
                    <label>Name <input name="course_name" required maxlength="100" value="<?= htmlspecialchars($c['course_name']) ?>"></label>
                    <label>Capacity <input name="capacity" required type="number" min="<?= htmlspecialchars($c['seats_taken']) ?>" value="<?= htmlspecialchars($c['capacity']) ?>"></label>
                    <button type="submit">Save</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$courses): ?><tr><td colspan="6">No courses found.</td></tr><?php endif; ?>
    </table>

    <h2>Recent Enrollments (last 20)</h2>
    <table>
        <tr><th>Student</th><th>Course</th><th>Term</th><th>Status</th><th>Created At</th></tr>
        <?php foreach ($enrollments as $e): ?>
        <tr>
            <td><?= htmlspecialchars($e['student_id']) ?></td>
            <td><?= htmlspecialchars($e['course_id']) ?></td>
            <td><?= htmlspecialchars($e['term']) ?></td>
            <td class="status-<?= htmlspecialchars($e['status']) ?>"><?= htmlspecialchars($e['status']) ?></td>
            <td><?= htmlspecialchars($e['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$enrollments): ?><tr><td colspan="5">No enrollments yet.</td></tr><?php endif; ?>
    </table>

    <h2>Error Channel (enrollment_errors, last 20)</h2>
    <table>
        <tr><th>Raw Message</th><th>Error</th><th>Failed At</th></tr>
        <?php foreach ($enrollmentErrors as $e): ?>
        <tr>
            <td><code><?= htmlspecialchars($e['raw_message']) ?></code></td>
            <td><?= htmlspecialchars($e['error_message']) ?></td>
            <td><?= htmlspecialchars($e['failed_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$enrollmentErrors): ?><tr><td colspan="3">No errors recorded — nothing has failed yet.</td></tr><?php endif; ?>
    </table>

    <p class="note">
        <a href="index.php">&larr; Back to student enrollment form</a> ·
        RabbitMQ UI: <a href="http://localhost:15672" target="_blank">localhost:15672</a> (guest/guest)
    </p>
</body>
</html>
