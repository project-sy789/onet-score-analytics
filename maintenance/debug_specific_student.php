<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

$student_id = '04830';
$indicator_code_fragment = 'ค'; // Search for 'ค 1.1 ป.4/10' (normalized or not)

echo "<h1>Debug Student $student_id</h1>";

// 1. Find the indicator ID (Widen search)
$stmt = $pdo->prepare("SELECT * FROM indicators WHERE code LIKE '%ป.4/10%' OR code LIKE '%4/10%' LIMIT 5");
$stmt->execute();
$indicators = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$indicators) {
    echo "No indicators found searching for '%4/10%'.<br>";
    echo "Listing FIRST 10 indicators in DB:<br>";
    $all = $pdo->query("SELECT * FROM indicators LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach($all as $a) echo "Example: {$a['code']}<br>";
    die();
}

foreach ($indicators as $ind) {
    echo "<h2>Indicator: {$ind['code']} (ID: {$ind['id']})</h2>";
    
    // 2. Find questions for this indicator
    $q_stmt = $pdo->prepare("
        SELECT q.* 
        FROM questions q
        JOIN question_indicators qi ON q.id = qi.question_id
        WHERE qi.indicator_id = ?
    ");
    $q_stmt->execute([$ind['id']]);
    $questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'><tr><th>Q Num</th><th>Exam Set</th><th>Max Score</th><th>Student Score</th><th>Calculated Logic</th></tr>";
    
    foreach ($questions as $q) {
        $s_stmt = $pdo->prepare("SELECT * FROM scores WHERE student_id = ? AND question_number = ? AND exam_set = ?");
        $s_stmt->execute([$student_id, $q['question_number'], $q['exam_set']]);
        $score = $s_stmt->fetch(PDO::FETCH_ASSOC);
        
        $obtained = $score ? $score['score_obtained'] : '-';
        
        // Simulate the Logic
        $calc = $obtained;
        if ($obtained == 1 && $q['max_score'] > 1) {
            $calc = "<strong>" . $q['max_score'] . " (Auto-Max)</strong>";
        }
        
        echo "<tr>";
        echo "<td>{$q['question_number']}</td>";
        echo "<td>{$q['exam_set']}</td>";
        echo "<td>{$q['max_score']}</td>";
        echo "<td>$obtained</td>";
        echo "<td>$calc</td>";
        echo "</tr>";
    }
    echo "</table>";
}
