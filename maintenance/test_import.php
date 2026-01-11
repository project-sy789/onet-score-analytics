<?php
/**
 * Import Test Script
 * Upload this to hosting to test import functionality
 */

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Import Function Test</h1>";

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

echo "<h2>Database Driver</h2>";
$db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$is_mysql = ($db_driver === 'mysql');
echo "<p>Driver: <strong>$db_driver</strong></p>";
echo "<p>Is MySQL: <strong>" . ($is_mysql ? 'YES' : 'NO') . "</strong></p>";

echo "<h2>Testing Student Import</h2>";

// Create test CSV data
$test_data = "student_id,prefix,name,grade_level,room_number\n";
$test_data .= "TEST001,นาย,ทดสอบ หนึ่ง,M3,1\n";
$test_data .= "TEST002,นางสาว,ทดสอบ สอง,M3,1\n";

// Save to temp file
$temp_file = tempnam(sys_get_temp_dir(), 'csv_');
file_put_contents($temp_file, $test_data);

try {
    // Test import
    if ($is_mysql) {
        $stmt = $pdo->prepare("
            INSERT INTO students (student_id, prefix, name, grade_level, room_number)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                prefix = ?,
                name = ?,
                grade_level = ?,
                room_number = ?
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO students (student_id, prefix, name, grade_level, room_number)
            VALUES (?, ?, ?, ?, ?)
        ");
    }
    
    $handle = fopen($temp_file, 'r');
    fgetcsv($handle); // Skip header
    
    $count = 0;
    while (($data = fgetcsv($handle)) !== false) {
        if (count($data) < 5) continue;
        
        $student_id = $data[0];
        $prefix = $data[1];
        $name = $data[2];
        $grade_level = $data[3];
        $room_number = $data[4];
        
        if ($is_mysql) {
            $stmt->execute([
                $student_id, $prefix, $name, $grade_level, $room_number,
                $prefix, $name, $grade_level, $room_number
            ]);
        } else {
            $stmt->execute([$student_id, $prefix, $name, $grade_level, $room_number]);
        }
        $count++;
    }
    
    fclose($handle);
    unlink($temp_file);
    
    echo "<p style='color:green'>✅ Successfully imported $count test students!</p>";
    
    // Verify
    $check = $pdo->query("SELECT * FROM students WHERE student_id LIKE 'TEST%'");
    $students = $check->fetchAll();
    
    echo "<h3>Imported Students:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Prefix</th><th>Name</th><th>Grade</th><th>Room</th></tr>";
    foreach ($students as $s) {
        echo "<tr>";
        echo "<td>{$s['student_id']}</td>";
        echo "<td>{$s['prefix']}</td>";
        echo "<td>{$s['name']}</td>";
        echo "<td>{$s['grade_level']}</td>";
        echo "<td>{$s['room_number']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Clean up
    $pdo->exec("DELETE FROM students WHERE student_id LIKE 'TEST%'");
    echo "<p>✅ Test data cleaned up</p>";
    
    echo "<h2>Conclusion</h2>";
    echo "<p style='color:green; font-weight:bold'>✅ Import functionality works correctly!</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    
    if (file_exists($temp_file)) {
        unlink($temp_file);
    }
}
?>
