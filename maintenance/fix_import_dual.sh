#!/bin/bash
# Script to fix import.php for both SQLite and MySQL compatibility

cd /Users/jamies/Downloads/ONET

# Backup
cp import.php import.php.mysql-only-backup

# Create a fixed version with proper dual database support
cat > import_fixed_dual.php << 'PHPEOF'
<?php
/**
 * CSV Import Handler - Dual Database Support (SQLite + MySQL)
 * Handles three types of CSV uploads: students, indicators, and scores
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$message = '';
$message_type = '';

// Detect database driver
$db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$is_mysql = ($db_driver === 'mysql');

// Handle file uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['import_type'])) {
            $import_type = $_POST['import_type'];
            
            // Handle either file upload or pasted data
            $file = null;
            
            if (!empty($_FILES['csv_file']['tmp_name'])) {
                // File upload
                $file = $_FILES['csv_file']['tmp_name'];
            } elseif (!empty($_POST['csv_data'])) {
                // Pasted data - convert tabs to commas and save to temp file
                $csv_data = $_POST['csv_data'];
                // Convert tabs to commas
                $csv_data = str_replace("\t", ',', $csv_data);
                
                // Create temporary file
                $file = tempnam(sys_get_temp_dir(), 'csv_');
                file_put_contents($file, $csv_data);
            }
            
            if ($file) {
                switch ($import_type) {
                    case 'students':
                        importStudents($pdo, $file, $is_mysql);
                        $message = 'นำเข้าข้อมูลนักเรียนสำเร็จ!';
                        $message_type = 'success';
                        break;
                    case 'indicators':
                        importIndicators($pdo, $file, $is_mysql);
                        $message = 'นำเข้าข้อมูลตัวชี้วัดและข้อสอบสำเร็จ!';
                        $message_type = 'success';
                        break;
                    case 'scores':
                        if (empty($_POST['subject'])) {
                            throw new Exception('กรุณาเลือกวิชาก่อนนำเข้าคะแนน');
                        }
                        importScores($pdo, $file, $_POST['subject'], $is_mysql);
                        $message = 'นำเข้าคะแนนสำเร็จ!';
                        $message_type = 'success';
                        break;
                }
                
                // Clean up temp file if created from pasted data
                if (!empty($_POST['csv_data']) && file_exists($file)) {
                    unlink($file);
                }
            }
        }
    } catch (Exception $e) {
        $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

/**
 * Import students from CSV
 */
function importStudents($pdo, $file, $is_mysql) {
    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new Exception('ไม่สามารถเปิดไฟล์ได้');
    }
    
    // Skip header row
    fgetcsv($handle);
    
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
            
            $student_id = sanitizeCSV($data[0]);
            $prefix = sanitizeCSV($data[1]);
            $name = sanitizeCSV($data[2]);
            $grade_level = sanitizeCSV($data[3]);
            $room_number = sanitizeCSV($data[4]);
            
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
            throw new Exception('ไม่พบข้อมูลที่ถูกต้องในไฟล์');
        }
        
    } catch (Exception $e) {
        fclose($handle);
        throw $e;
    }
}

/**
 * Import indicators and questions from CSV
 */
function importIndicators($pdo, $file, $is_mysql) {
    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new Exception('ไม่สามารถเปิดไฟล์ได้');
    }
    
    // Skip header row
    fgetcsv($handle);
    
    try {
        // Prepare statements based on database type
        if ($is_mysql) {
            $indicator_stmt = $pdo->prepare("
                INSERT INTO indicators (code, description, subject)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description = ?,
                    subject = ?
            ");
        } else {
            $indicator_stmt = $pdo->prepare("
                INSERT OR REPLACE INTO indicators (code, description, subject)
                VALUES (?, ?, ?)
            ");
        }
        
        $count = 0;
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 4) continue;
            
            $question_number = intval($data[0]);
            $indicator_codes_str = sanitizeCSV($data[1]);
            $description = sanitizeCSV($data[2]);
            $subject = sanitizeCSV($data[3]);
            $max_score = isset($data[4]) ? floatval($data[4]) : 1.00;
            
            if ($max_score <= 0) $max_score = 1.00;
            if ($question_number <= 0 || empty($indicator_codes_str)) continue;
            
            $indicator_codes = array_map('trim', explode(',', $indicator_codes_str));
            
            // Create or update question
            $q_check = $pdo->prepare("SELECT id FROM questions WHERE question_number = ?");
            $q_check->execute([$question_number]);
            $question_id = $q_check->fetchColumn();
            
            if ($question_id) {
                $q_update = $pdo->prepare("UPDATE questions SET max_score = ?, subject = ? WHERE id = ?");
                $q_update->execute([$max_score, $subject, $question_id]);
            } else {
                $q_insert = $pdo->prepare("INSERT INTO questions (question_number, max_score, subject) VALUES (?, ?, ?)");
                $q_insert->execute([$question_number, $max_score, $subject]);
                $question_id = $pdo->lastInsertId();
            }
            
            // Clear existing mappings
            $pdo->prepare("DELETE FROM question_indicators WHERE question_id = ?")->execute([$question_id]);
            
            // Create new mappings
            foreach ($indicator_codes as $code) {
                if (empty($code)) continue;
                
                // Insert or update indicator
                if ($is_mysql) {
                    $indicator_stmt->execute([$code, $description, $subject, $description, $subject]);
                } else {
                    $indicator_stmt->execute([$code, $description, $subject]);
                }
                
                // Get indicator ID
                $id_stmt = $pdo->prepare("SELECT id FROM indicators WHERE code = ?");
                $id_stmt->execute([$code]);
                $indicator_id = $id_stmt->fetchColumn();
                
                // Link question to indicator
                $link_stmt = $pdo->prepare("INSERT OR IGNORE INTO question_indicators (question_id, indicator_id) VALUES (?, ?)");
                $link_stmt->execute([$question_id, $indicator_id]);
            }
            
            $count++;
        }
        
        fclose($handle);
        
        if ($count === 0) {
            throw new Exception('ไม่พบข้อมูลที่ถูกต้องในไฟล์');
        }
        
    } catch (Exception $e) {
        fclose($handle);
        throw $e;
    }
}

/**
 * Import scores from CSV
 */
function importScores($pdo, $file, $subject, $is_mysql) {
    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new Exception('ไม่สามารถเปิดไฟล์ได้');
    }
    
    // Get question numbers for this subject
    $q_stmt = $pdo->prepare("SELECT question_number FROM questions WHERE subject = ? ORDER BY question_number");
    $q_stmt->execute([$subject]);
    $subject_questions = $q_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($subject_questions)) {
        fclose($handle);
        throw new Exception(
            "ไม่พบข้อสอบสำหรับวิชา: $subject\n" .
            "กรุณานำเข้าข้อมูลตัวชี้วัดและข้อสอบก่อน"
        );
    }
    
    // Skip header row
    $header = fgetcsv($handle);
    
    // Validate header matches subject questions
    if (count($header) - 1 !== count($subject_questions)) {
        fclose($handle);
        throw new Exception(
            "จำนวนข้อสอบในไฟล์ไม่ตรงกับข้อมูลในระบบ\n" .
            "ไฟล์มี: " . (count($header) - 1) . " ข้อ\n" .
            "ระบบมี: " . count($subject_questions) . " ข้อ\n" .
            "ข้อสอบวิชานี้: " . implode(', ', $subject_questions)
        );
    }
    
    try {
        if ($is_mysql) {
            $stmt = $pdo->prepare("
                INSERT INTO scores (student_id, question_number, score_obtained)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    score_obtained = ?
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO scores (student_id, question_number, score_obtained)
                VALUES (?, ?, ?)
            ");
        }
        
        $count = 0;
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 2) continue;
            
            $student_id = sanitizeCSV($data[0]);
            if (empty($student_id)) continue;
            
            for ($i = 1; $i < count($data); $i++) {
                $question_number = $subject_questions[$i - 1];
                $score = floatval($data[$i]);
                
                $max_stmt = $pdo->prepare("SELECT max_score FROM questions WHERE question_number = ?");
                $max_stmt->execute([$question_number]);
                $max_score = $max_stmt->fetchColumn();
                
                if ($score < 0 || $score > $max_score) {
                    throw new Exception("คะแนนไม่ถูกต้องสำหรับข้อ $question_number: $score (คะแนนเต็ม: $max_score)");
                }
                
                if ($is_mysql) {
                    $stmt->execute([$student_id, $question_number, $score, $score]);
                } else {
                    $stmt->execute([$student_id, $question_number, $score]);
                }
                $count++;
            }
        }
        
        fclose($handle);
        
        if ($count === 0) {
            throw new Exception('ไม่พบข้อมูลที่ถูกต้องในไฟล์');
        }
        
    } catch (Exception $e) {
        fclose($handle);
        throw $e;
    }
}
PHPEOF

echo "Fixed import.php created as import_fixed_dual.php"
echo "To apply: mv import_fixed_dual.php import.php"
