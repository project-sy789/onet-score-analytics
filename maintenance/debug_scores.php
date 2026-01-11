<?php
/**
 * Debug script to check student scores and segmentation calculation
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$student_id = '03948'; // The student showing 421.82%
$subject = 'วิทยาศาสตร์';

echo "<h1>Debug: Student Score Calculation</h1>";
echo "<p>Student ID: <strong>$student_id</strong></p>";
echo "<p>Subject: <strong>$subject</strong></p>";

// Get all questions for this subject
$q_stmt = $pdo->prepare("SELECT question_number, max_score FROM questions WHERE subject = ? ORDER BY question_number");
$q_stmt->execute([$subject]);
$questions = $q_stmt->fetchAll();

echo "<h2>Questions in Subject</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Question #</th><th>Max Score</th></tr>";
$total_possible = 0;
foreach ($questions as $q) {
    echo "<tr><td>{$q['question_number']}</td><td>{$q['max_score']}</td></tr>";
    $total_possible += $q['max_score'];
}
echo "</table>";
echo "<p><strong>Total Possible: $total_possible</strong></p>";

// Get student's scores
$s_stmt = $pdo->prepare("
    SELECT s.question_number, s.score_obtained, q.max_score, q.subject
    FROM scores s
    INNER JOIN questions q ON s.question_number = q.question_number
    WHERE s.student_id = ?
    ORDER BY s.question_number
");
$s_stmt->execute([$student_id]);
$scores = $s_stmt->fetchAll();

echo "<h2>Student's Scores (All Subjects)</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Question #</th><th>Subject</th><th>Score</th><th>Max Score</th></tr>";
$total_score_all = 0;
$total_score_subject = 0;
foreach ($scores as $s) {
    $highlight = ($s['subject'] === $subject) ? "style='background-color: yellow'" : "";
    echo "<tr $highlight>";
    echo "<td>{$s['question_number']}</td>";
    echo "<td>{$s['subject']}</td>";
    echo "<td>{$s['score_obtained']}</td>";
    echo "<td>{$s['max_score']}</td>";
    echo "</tr>";
    $total_score_all += $s['score_obtained'];
    if ($s['subject'] === $subject) {
        $total_score_subject += $s['score_obtained'];
    }
}
echo "</table>";
echo "<p><strong>Total Score (All Subjects): $total_score_all</strong></p>";
echo "<p><strong>Total Score (Subject Only): $total_score_subject</strong></p>";

// Calculate percentage
$percentage = ($total_possible > 0) ? ($total_score_subject / $total_possible * 100) : 0;
echo "<h2>Calculation</h2>";
echo "<p>Percentage = ($total_score_subject / $total_possible) × 100 = <strong>" . number_format($percentage, 2) . "%</strong></p>";

// Test the actual function
echo "<h2>Function Result</h2>";
$result = segmentStudentsBySubject($pdo, $subject, 'ม.6', null);
foreach ($result as $student) {
    if ($student['student_id'] === $student_id) {
        echo "<p>Student: {$student['name']}</p>";
        echo "<p>Score from function: <strong>{$student['score']}%</strong></p>";
        echo "<p>Segment: {$student['segment']}</p>";
        break;
    }
}
?>
