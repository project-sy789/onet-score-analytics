<?php
/**
 * API Helper to get student scores for a specific exam set
 * Returns JSON array of questions with current scores
 */
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$student_id = $_GET['student_id'] ?? '';
$exam_set = $_GET['exam_set'] ?? '';

if (empty($student_id) || empty($exam_set)) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

try {
    // Get all questions for this exam set
    // Left join with scores for this student
    $sql = "
        SELECT 
            q.question_number,
            q.max_score,
            q.subject,
            s.score_obtained as score
        FROM questions q
        LEFT JOIN scores s ON q.question_number = s.question_number 
                           AND q.exam_set = s.exam_set 
                           AND s.student_id = ?
        WHERE q.exam_set = ?
        ORDER BY CAST(q.question_number AS UNSIGNED), q.question_number
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id, $exam_set]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($data);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
