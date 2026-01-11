<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';

// Adjust these values to match your actual data on server
$subject = 'วิทยาศาสตร์';
$grade_level = 'ม.6';
$indicator_pass_threshold = 50;

echo "<h1>Debug O-NET Analysis</h1>";
echo "Testing with: Subject=$subject, Grade=$grade_level<br>";

// 1. Check basic connection
try {
    echo "<h3>1. Database Connection</h3>";
    $stmt = $pdo->query("SELECT 1");
    echo "Connection OK<br>";
} catch (Exception $e) {
    die("Connection Failed: " . $e->getMessage());
}

// 2. Check if students exist
echo "<h3>2. Check Students</h3>";
$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM students WHERE grade_level = ?");
$stmt->execute([$grade_level]);
$count = $stmt->fetchColumn();
echo "Students in $grade_level: $count<br>";

// 3. Check if scores exist for this subject
echo "<h3>3. Check Scores</h3>";
try {
    $sql = "SELECT COUNT(*) as cnt 
            FROM scores s 
            INNER JOIN questions q ON s.question_number = q.question_number AND s.exam_set = q.exam_set 
            WHERE q.subject = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$subject]);
    $count = $stmt->fetchColumn();
    echo "Scores for $subject: $count<br>";
} catch (Exception $e) {
    echo "Error checking scores: " . $e->getMessage() . "<br>";
}

// 4. Test the COMPLEX Query part by part
echo "<h3>4. Test Complex Query (SegmentStudentsBySubject)</h3>";

$sql = "
    SELECT 
        st.student_id,
        st.name,
        (SELECT COALESCE(SUM(s2.score_obtained), 0)
            FROM scores s2
            INNER JOIN questions q2 ON s2.question_number = q2.question_number AND s2.exam_set = q2.exam_set
            WHERE s2.student_id = st.student_id AND q2.subject = ?) as total_score,
        (SELECT SUM(max_score) 
            FROM questions 
            WHERE subject = ?) as total_possible,
        (SELECT COUNT(DISTINCT qi.indicator_id)
            FROM scores s_ind
            INNER JOIN questions q_ind ON s_ind.question_number = q_ind.question_number AND s_ind.exam_set = q_ind.exam_set
            INNER JOIN question_indicators qi ON q_ind.id = qi.question_id
            WHERE s_ind.student_id = st.student_id AND q_ind.subject = ?) as indicators_total,
        (SELECT COUNT(DISTINCT qi_pass.indicator_id)
            FROM (
                SELECT qi2.indicator_id
                FROM scores s_pass
                INNER JOIN questions q_pass ON s_pass.question_number = q_pass.question_number AND s_pass.exam_set = q_pass.exam_set
                INNER JOIN question_indicators qi2 ON q_pass.id = qi2.question_id
                WHERE s_pass.student_id = st.student_id AND q_pass.subject = ?
                GROUP BY qi2.indicator_id
                HAVING (SUM(s_pass.score_obtained) / SUM(q_pass.max_score) * 100) >= " . $indicator_pass_threshold . "
            ) qi_pass) as indicators_passed
    FROM students st
    WHERE st.grade_level = ?
    AND EXISTS (
        SELECT 1 FROM scores s3
        INNER JOIN questions q3 ON s3.question_number = q3.question_number AND s3.exam_set = q3.exam_set
        WHERE s3.student_id = st.student_id AND q3.subject = ?
    )
    LIMIT 5
";

try {
    echo "<textarea style='width:100%;height:300px'>$sql</textarea><br>";
    
    $stmt = $pdo->prepare($sql);
    // params: subject, subject, subject, subject, grade, subject
    $stmt->execute([$subject, $subject, $subject, $subject, $grade_level, $subject]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Query Result Count: " . count($results) . "<br>";
    if (count($results) > 0) {
        echo "<pre>";
        print_r($results[0]);
        echo "</pre>";
    } else {
        echo "No data returned from main query.<br>";
    }
    
} catch (Exception $e) {
    echo "<div style='color:red;border:1px solid red;padding:10px'>";
    echo "<strong>SQL ERROR:</strong> " . $e->getMessage();
    echo "</div>";
}
