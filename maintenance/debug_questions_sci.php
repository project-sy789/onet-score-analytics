<?php
require_once __DIR__ . '/../db.php';

// Check DB connection type
echo "DB Type: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

echo "--- EXAM SET SUMMARY ---\n";
// Summary of all exam sets
$stmt = $pdo->query("SELECT exam_set, COUNT(*) as q_count, SUM(max_score) as total_max FROM questions GROUP BY exam_set");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No questions found in database.\n";
} else {
    foreach ($rows as $row) {
        echo "Exam Set: " . $row['exam_set'] . " | Questions: " . $row['q_count'] . " | Total Max: " . $row['total_max'] . "\n";
    }
}

echo "\n--- SCI SPECIFIC CHECK ---\n";
// Inspect duplicates for Science
$stmt = $pdo->query("SELECT id, exam_set, question_number, max_score, subject FROM questions WHERE exam_set LIKE '%SCI%' OR subject LIKE '%วิทยาศาสตร์%' ORDER BY exam_set, question_number LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo "ID: " . $row['id'] . " | Set: " . $row['exam_set'] . " | Q: " . $row['question_number'] . " | Max: " . $row['max_score'] . "\n";
}
