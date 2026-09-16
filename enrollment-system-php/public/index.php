<?php

require __DIR__ . '/../vendor/autoload.php';

use App\RabbitMQPublisher;

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = trim($_POST['student_id'] ?? '');
    $courseId  = trim($_POST['course_id'] ?? '');
    $term      = trim($_POST['term'] ?? '');

    if ($studentId === '' || $courseId === '' || $term === '') {
        $error = 'All fields are required.'; 
    } else {
        try {
            $publisher = new RabbitMQPublisher();
            $publisher->publishEnrollmentRequest([
                'student_id'   => $studentId,
                'course_id'    => $courseId,
                'term'         => $term,
                'requested_at' => date('c'),
            ]);
            $message = "Request submitted: student {$studentId} -> {$courseId} ({$term}). "
                     . "Please wait for the Registrar system to process it.";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        input:focus {
            border-color: #1e3a8a !important;
            background-color: #ffffff !important;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.15) !important;
            transition: box-shadow 0.15s ease, border-color 0.15s ease, background-color 0.15s ease;
        }
        input {
            transition: box-shadow 0.15s ease, border-color 0.15s ease, background-color 0.15s ease;
        }
    </style>
</head>

<body style="
    margin: 0;
    min-height: 100vh;
    font-family: 'Poppins', Arial, Helvetica, sans-serif;
    background: linear-gradient(135deg, #eaf2ff, #f8f5ff);
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 40px 20px;
    box-sizing: border-box;">

    <div style="
        width: 100%;
        max-width: 500px;
        background: #ffffff;
        border-radius: 20px;
        padding: 40px;
        box-sizing: border-box;
        box-shadow: 0 15px 45px rgba(30, 58, 138, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.8);">

        <nav style="
            text-align: center;
            color: #1e3a8a;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 22px;">
            Sapphire Institute of Technology
        </nav>

        <header style="
            text-align: center;
            margin-bottom: 30px;">

            <div style="
                width: 60px;
                height: 60px;
                margin: 0 auto 18px;
                background: #1e3a8a;
                border-radius: 16px;
                display: flex;
                justify-content: center;
                align-items: center;
                color: #ffffff;
                box-shadow: 0 8px 20px rgba(30, 58, 138, 0.3);">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 3L1 8L12 13L21 9.18V16H23V8L12 3Z" fill="#ffffff"/>
                    <path d="M5 11.18V16.5C5 16.5 6.5 19 12 19C17.5 19 19 16.5 19 16.5V11.18L12 14.5L5 11.18Z" fill="#ffffff"/>
                </svg>
            </div>

            <h1 style="
                margin: 0 0 10px;
                color: #1f2937;
                font-size: 30px;
                font-weight: 700;
                letter-spacing: -0.5px; ">
                Enrollment System
            </h1>

            <p style="
                margin: 0;
                color: #6b7280;
                font-size: 14px;
                line-height: 1.6;">
                Submit a request to enroll a student in a course.
            </p>

        </header>

        <?php if ($message): ?>
            <div style="
                padding: 14px 16px;
                margin-bottom: 22px;
                background-color: #ecfdf5;
                border: 1px solid #a7f3d0;
                border-radius: 10px;
                color: #047857;
                font-size: 13px;
                line-height: 1.5;
                display: flex;
                gap: 10px;
                align-items: flex-start;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0; margin-top: 2px;">
                    <circle cx="12" cy="12" r="10" fill="#047857"/>
                    <path d="M7 12.5L10.5 16L17 9" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span><strong>Success</strong><br>
                <?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="
                padding: 14px 16px;
                margin-bottom: 22px;
                background-color: #fef2f2;
                border: 1px solid #fecaca;
                border-radius: 10px;
                color: #b91c1c;
                font-size: 13px;
                line-height: 1.5;
                display: flex;
                gap: 10px;
                align-items: flex-start;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0; margin-top: 2px;">
                    <path d="M12 2L1 21H23L12 2Z" fill="#b91c1c"/>
                    <path d="M12 9V14" stroke="#ffffff" stroke-width="2" stroke-linecap="round"/>
                    <circle cx="12" cy="17.5" r="1" fill="#ffffff"/>
                </svg>
                <span><strong>Error</strong><br>
                <?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post">
            <label style="
                display: block;
                margin-bottom: 20px;
                color: #374151;
                font-size: 14px;
                font-weight: 600;">
            Student ID
                <input
                    type="text"
                    name="student_id"
                    placeholder="e.g. 12345"
                    style="
                        width: 100%;
                        padding: 13px 15px;
                        margin-top: 8px;
                        box-sizing: border-box;
                        border: 1px solid #d1d5db;
                        border-radius: 10px;
                        background-color: #f9fafb;
                        color: #111827;
                        font-size: 14px;
                        outline: none;
                        font-family: 'Poppins', Arial, Helvetica, sans-serif;">
            </label>

            <label style="
                display: block;
                margin-bottom: 20px;
                color: #374151;
                font-size: 14px;
                font-weight: 600;">
            Course ID
                <input
                    type="text"
                    name="course_id"
                    placeholder="e.g. CS301"
                    style="
                        width: 100%;
                        padding: 13px 15px;
                        margin-top: 8px;
                        box-sizing: border-box;
                        border: 1px solid #d1d5db;
                        border-radius: 10px;
                        background-color: #f9fafb;
                        color: #111827;
                        font-size: 14px;
                        outline: none;
                        font-family: 'Poppins', Arial, Helvetica, sans-serif;">

                <span style="
                    display: block;
                    margin-top: 7px;
                    color: #9ca3af;
                    font-size: 12px;
                    font-weight: normal;">
                Example: CS301
                </span>
            </label>

            <label style="
                display: block;
                margin-bottom: 25px;
                color: #374151;
                font-size: 14px;
                font-weight: 600;">
            Term
                <input
                    type="text"
                    name="term"
                    required
                    value="Fall2026"
                    style="
                        width: 100%;
                        padding: 13px 15px;
                        margin-top: 8px;
                        box-sizing: border-box;
                        border: 1px solid #d1d5db;
                        border-radius: 10px;
                        background-color: #f9fafb;
                        color: #111827;
                        font-size: 14px;
                        outline: none;
                        font-family: 'Poppins', Arial, Helvetica, sans-serif;">
            </label>

            <button
                type="submit"
                style="
                    width: 100%;
                    padding: 14px 20px;
                    background: #1e3a8a;
                    color: #ffffff;
                    border: none;
                    border-radius: 10px;
                    font-size: 15px;
                    font-weight: 700;
                    letter-spacing: 0.2px;
                    cursor: pointer;
                    box-shadow: 0 8px 18px rgba(30, 58, 138, 0.3);
                    font-family: 'Poppins', Arial, Helvetica, sans-serif;">
                Submit Enrollment Request
            </button>

        </form>
        <div style="
            margin-top: 30px;
            padding: 18px;
            background-color: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;">

            <h5 style="
                margin: 0 0 8px;
                color: #374151;
                font-size: 13px;
                font-weight: 700;
                display: flex;
                align-items: center;
                gap: 6px;">
                Testing
            </h5>

            <p style="
                margin: 0;
                color: #6b7280;
                font-size: 12px;
                line-height: 1.6;">
            Submit CS301 for 4+ different student IDs to test if the Registrar rejects requests once the course capacity of 3 students is reached.
            </p>
        </div>
    </div>
</body>
</html>