<?php
if (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
} else {
    die("Error: db.php not found.");
}

$student_id = $_GET['id'] ?? '05204'; // Default to the student in question

echo "<h2>Debug: Student Scores for ID: $student_id</h2>";

try {
    $stmt = $pdo->prepare("
        SELECT s.student_id, s.question_number, s.score_obtained, s.exam_set, q.max_score
        FROM scores s
        LEFT JOIN questions q ON s.question_number = q.question_number AND s.exam_set = q.exam_set
        WHERE s.student_id = ?
        ORDER BY s.exam_set, s.question_number
    ");
    $stmt->execute([$student_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Q#</th><th>Set</th><th>Score Obtained (DB)</th><th>Max Score (DB)</th></tr>";
    
    $total_score = 0;
    $count = 0;
    
    foreach ($rows as $row) {
        echo "<tr>";
        echo "<td>{$row['question_number']}</td>";
        echo "<td>{$row['exam_set']}</td>";
        echo "<td>" . var_export($row['score_obtained'], true) . "</td>";
        echo "<td>" . var_export($row['max_score'], true) . "</td>";
        echo "</tr>";
        
        $total_score += (float)$row['score_obtained'];
        $count++;
    }
    echo "</table>";
    
    echo "<h3>Summary</h3>";
    echo "Total Items: $count<br>";
    echo "Sum of Score Obtained: $total_score<br>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
