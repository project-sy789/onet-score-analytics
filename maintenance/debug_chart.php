<?php
require_once 'db.php';
require_once 'functions.php';

$exam_set = 'Pre-ONET-2568-R2-SCI';
$grade = 'ม.3'; // From URL

echo "Testing getScoreDistribution for $exam_set / Grade $grade\n";

$dist = getScoreDistribution($pdo, $exam_set, $grade);

print_r($dist);

// Also check raw scores
$sql = "SELECT SUM(s.score_obtained) as total_score 
        FROM scores s 
        JOIN students st ON s.student_id = st.student_id
        WHERE s.exam_set = ? AND st.grade_level = ?
        GROUP BY s.student_id
        ORDER BY total_score ASC";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([$exam_set, $grade]);
$scores = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "\nRaw Scores Sample (First 5):\n";
print_r(array_slice($scores, 0, 5));
echo "Total Count: " . count($scores) . "\n";
echo "Min: " . min($scores) . "\n";
echo "Max: " . max($scores) . "\n";
