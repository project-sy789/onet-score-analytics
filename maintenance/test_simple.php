<?php
/**
 * Simple Import Test - Minimal Version
 * Test if basic form submission works
 */

// Enable error display
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Simple Import Test</title></head><body>";
echo "<h1>Simple Import Test</h1>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h2>POST Data Received</h2>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    echo "<h2>FILES Data</h2>";
    echo "<pre>";
    print_r($_FILES);
    echo "</pre>";
    
    try {
        require_once __DIR__ . '/db.php';
        echo "<p style='color:green'>✅ db.php loaded</p>";
        
        require_once __DIR__ . '/functions.php';
        echo "<p style='color:green'>✅ functions.php loaded</p>";
        
        $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        echo "<p>Database driver: <strong>$db_driver</strong></p>";
        
        $is_mysql = ($db_driver === 'mysql');
        echo "<p>Is MySQL: <strong>" . ($is_mysql ? 'YES' : 'NO') . "</strong></p>";
        
        if (isset($_POST['test_import'])) {
            echo "<h2>Testing Import</h2>";
            
            // Create test data
            $test_data = "40001,นาย,ทดสอบ,ม.4,1";
            $parts = explode(',', $test_data);
            
            if ($is_mysql) {
                $stmt = $pdo->prepare("
                    INSERT INTO students (student_id, prefix, name, grade_level, room_number)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE name = ?
                ");
                $stmt->execute([$parts[0], $parts[1], $parts[2], $parts[3], $parts[4], $parts[2]]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT OR REPLACE INTO students (student_id, prefix, name, grade_level, room_number)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute($parts);
            }
            
            echo "<p style='color:green'>✅ Test student inserted/updated!</p>";
            
            // Clean up
            $pdo->exec("DELETE FROM students WHERE student_id = '40001'");
            echo "<p>✅ Test data cleaned</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
} else {
    echo "<p>No POST data received. Showing form...</p>";
}

?>

<h2>Test Form</h2>
<form method="POST">
    <button type="submit" name="test_import" value="1">Test Import</button>
</form>

<hr>
<p><a href="import.php">← Back to Real Import</a></p>

</body></html>
