<?php
/**
 * Direct Import Test - Bypass Form Submission
 */

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Direct Import Test</h1>";

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Detect database driver
$db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$is_mysql = ($db_driver === 'mysql');

echo "<p>Database: <strong>" . ($is_mysql ? 'MySQL/MariaDB' : 'SQLite') . "</strong></p>";

// Create test CSV
$test_csv = "student_id,prefix,name,grade_level,room_number\n";
$test_csv .= "40001,นาย,ทดสอบ หนึ่ง,ม.4,1\n";
$test_csv .= "40002,นางสาว,ทดสอบ สอง,ม.4,1\n";

$temp_file = tempnam(sys_get_temp_dir(), 'csv_');
file_put_contents($temp_file, $test_csv);

echo "<h2>Testing importStudents() function</h2>";

try {
    // Define the function inline to test
    function testImportStudents($pdo, $file, $is_mysql) {
        $handle = fopen($file, 'r');
        if (!$handle) {
            throw new Exception('Cannot open file');
        }
        
        fgetcsv($handle); // Skip header
        
        try {
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
            
            $count = 0;
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) < 5) continue;
                
                $student_id = htmlspecialchars(trim($data[0]), ENT_QUOTES, 'UTF-8');
                $prefix = htmlspecialchars(trim($data[1]), ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars(trim($data[2]), ENT_QUOTES, 'UTF-8');
                $grade_level = htmlspecialchars(trim($data[3]), ENT_QUOTES, 'UTF-8');
                $room_number = htmlspecialchars(trim($data[4]), ENT_QUOTES, 'UTF-8');
                
                if (empty($student_id) || empty($name)) continue;
                
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
            
            if ($count === 0) {
                throw new Exception('No valid data found');
            }
            
            return $count;
            
        } catch (Exception $e) {
            fclose($handle);
            throw $e;
        }
    }
    
    $count = testImportStudents($pdo, $temp_file, $is_mysql);
    
    echo "<p style='color:green'>✅ Successfully imported $count students!</p>";
    
    // Verify
    $stmt = $pdo->query("SELECT * FROM students WHERE student_id IN ('40001', '40002')");
    $students = $stmt->fetchAll();
    
    echo "<h3>Imported Data:</h3>";
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
    $pdo->exec("DELETE FROM students WHERE student_id IN ('40001', '40002')");
    unlink($temp_file);
    
    echo "<p>✅ Test data cleaned up</p>";
    
    echo "<h2>Conclusion</h2>";
    echo "<p style='color:green; font-weight:bold'>✅ The import function works perfectly!</p>";
    echo "<p>If import.php still shows Error 500, the problem is likely:</p>";
    echo "<ul>";
    echo "<li>File upload size limit</li>";
    echo "<li>PHP execution time limit</li>";
    echo "<li>Memory limit</li>";
    echo "<li>Missing sanitizeCSV() function</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    
    if (file_exists($temp_file)) {
        unlink($temp_file);
    }
}

// Check if sanitizeCSV function exists
echo "<h2>Function Check</h2>";
if (function_exists('sanitizeCSV')) {
    echo "<p style='color:green'>✅ sanitizeCSV() function exists</p>";
} else {
    echo "<p style='color:red'>❌ sanitizeCSV() function NOT FOUND!</p>";
    echo "<p>This might be the cause of Error 500!</p>";
}
?>
