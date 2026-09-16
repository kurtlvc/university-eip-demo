<?php

require __DIR__ . '/../vendor/autoload.php';

use App\RabbitMQPublisher;

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = trim($_POST['student_id'] ?? '');
    $courseId  = trim($_POST['course_id'] ?? '');
    $term      = trim($_POST['term'] ?? '');
    $action    = trim($_POST['action'] ?? 'ENROLL');

    if ($studentId === '' || $courseId === '' || $term === '') {
        $error = 'All fields are required.';
    } else {
        try {
            $publisher = new RabbitMQPublisher();
            $publisher->publishEnrollmentRequest([
                'student_id'   => $studentId,
                'course_id'    => $courseId,
                'term'         => $term,
                'action'       => $action,
                'requested_at' => date('c'),
            ]);
            $verb = ($action === 'DROP') ? 'drop' : 'enroll';
            $message = "Request submitted: student {$studentId} -> {$verb} {$courseId} ({$term}). "
                     . "The Registrar System will process it asynchronously.";
        } catch (\Throwable $e) {
            $error = 'Could not submit request: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enrollment System</title>
    <style>
        body { font-family: sans-serif; max-width: 480px; margin: 60px auto; }
        label { display: block; margin-top: 12px; }
        input { width: 100%; padding: 6px; box-sizing: border-box; }
        button { margin-top: 16px; padding: 8px 16px; }
        .ok { color: #2e7d32; }
        .err { color: #c62828; }
        .note { font-size: 0.85em; color: #666; margin-top: 24px; }
    </style>
</head>
<body>
    <h1>Enrollment System</h1>
    <p>This system only <em>publishes</em> requests. It never talks to MySQL directly -
       the Registrar System is the sole authority for enrollment records.</p>

    <?php if ($message): ?><p class="ok"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <form method="post">
        <label>Student ID
            <input name="student_id" required placeholder="e.g. 12345">
        </label>
        <label>Course ID
            <input name="course_id" required placeholder="e.g. CS301 (capacity: 3, for easy testing)">
        </label>
        <label>Term
            <input name="term" required value="Fall2026">
        </label>
        <label>Action
            <select name="action">
                <option value="ENROLL">Enroll</option>
                <option value="DROP">Drop</option>
            </select>
        </label>
        <button type="submit">Submit Request</button>
    </form>

    <p class="note">
        Try submitting CS301 for 4+ different student IDs to see the Registrar
        reject requests once the course (capacity 3) fills up.
        Check RabbitMQ's management UI at <code>localhost:15672</code> (guest/guest)
        to watch the queue, and query MySQL directly to see results land.
    </p>
</body>
</html>
