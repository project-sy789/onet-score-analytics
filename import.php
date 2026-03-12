<?php
// Increase limits for import
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
@ini_set('max_execution_time', 300);
@ini_set('memory_limit', '256M');
@ini_set('upload_max_filesize', '10M');
@ini_set('post_max_size', '10M');

/**
 * CSV Import Handler
 * Handles three types of CSV uploads: students, indicators, and scores
 */

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/functions.php';
    
    // Error logging for debugging
    error_log("import.php loaded - Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
} catch (Exception $e) {
    die("Fatal Error: " . $e->getMessage());
}

$message = '';
$message_type = '';

// Detect database driver
$db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$is_mysql = ($db_driver === 'mysql');


// Handle file uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST received - import_type: " . ($_POST['import_type'] ?? 'not set'));
    try {
        if (isset($_POST['import_type'])) {
            $import_type = $_POST['import_type'];
            error_log("Processing import type: $import_type");
            
            // Handle either file upload or pasted data
            $file = null;
            $is_temp_file = false;
            
            if (!empty($_POST['csv_data'])) {
                // Convert pasted data to temporary file
                $csv_data = $_POST['csv_data'];
                // Do NOT modify data (str_replace) here. Let fgetcsv handle delimiters (tab/comma) and quotes naturally.
                
                $file = tempnam(sys_get_temp_dir(), 'csv_');
                file_put_contents($file, $csv_data);
                $is_temp_file = true;
                
            } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['csv_file']['tmp_name'];
                
                // Validate file type
                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($file_info, $file);
                finfo_close($file_info);
                
                if (!in_array($mime_type, ['text/plain', 'text/csv', 'application/csv'])) {
                    throw new Exception('กรุณาอัพโหลดไฟล์ CSV เท่านั้น');
                }
            } else {
                throw new Exception('กรุณาอัพโหลดไฟล์หรือวางข้อมูล CSV');
            }
            
            // Process based on import type
            switch ($import_type) {
                case 'students':
                    error_log("Calling importStudents with is_mysql=" . ($is_mysql ? 'true' : 'false'));
                    importStudents($pdo, $file, $is_mysql);
                    error_log("importStudents completed successfully");
                    $message = 'นำเข้าข้อมูลนักเรียนสำเร็จ';
                    break;
                    
                case 'indicators':
                    importIndicators($pdo, $file, $is_mysql);
                    $message = 'นำเข้าข้อมูลตัวชี้วัดและข้อสอบสำเร็จ';
                    break;
                    
                case 'master_indicators':
                    importMasterIndicators($pdo, $file, $is_mysql);
                    $message = 'นำเข้าข้อมูลตัวชี้วัดทั้งหมดสำเร็จ';
                    break;
                    
                case 'scores':
                    $subject = $_POST['subject'] ?? '';
                    $exam_set = $_POST['exam_set'] ?? 'default';
                    $grade_level = $_POST['grade_level'] ?? '';
                    
                    if (empty($subject) || empty($grade_level)) {
                        throw new Exception('กรุณาเลือกวิชาและระดับชั้นก่อนนำเข้าคะแนน');
                    }
                    importScores($pdo, $file, $subject, $exam_set, $grade_level, $is_mysql);
                    $message = "นำเข้าข้อมูลคะแนนวิชา \"$subject\" ($grade_level) ชุด \"$exam_set\" สำเร็จ";
                    break;
                    
                default:
                    throw new Exception('ประเภทการนำเข้าไม่ถูกต้อง');
            }
            
            // Clean up temp file if created
            if ($is_temp_file && file_exists($file)) {
                unlink($file);
            }
            
            $message_type = 'success';
            
        }
    } catch (Exception $e) {
        $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

/**
 * Import students from CSV
 * Format: student_id, prefix, name, grade_level, room_number
 */
function importStudents($pdo, $file, $is_mysql = false) {
    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new Exception('ไม่สามารถเปิดไฟล์ได้');
    }
    
    $delimiter = detectDelimiter($handle);
    
    // Smart header detection
    $first_row = fgetcsv($handle, 0, $delimiter);
    if ($first_row) {
        // Check if first column looks like student_id (numeric)
        if (!is_numeric(trim($first_row[0]))) {
            // It's a header (not numeric), skip it and continue
        } else {
            // It's data, rewind to read it again
            rewind($handle);
        }
    }
    
    
    try {
        if ($is_mysql) {
            // MySQL syntax
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
            // SQLite syntax
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO students (student_id, prefix, name, grade_level, room_number)
                VALUES (?, ?, ?, ?, ?)
            ");
        }
        
        $count = 0;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
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
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw $e;
    }
}

/**
 * Import indicators and exam mapping from CSV
 * Format: exam_set, question_number, indicator_code, description, subject, max_score, grade_level
 * exam_set is optional - defaults to 'default' if not provided
 */
function importIndicators($pdo, $file, $is_mysql = false) {
    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new Exception('ไม่สามารถเปิดไฟล์ได้');
    }
    
    // Auto-detect delimiter (Tab or Comma)
    $first_line = fgets($handle);
    rewind($handle);
    $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ",";
    
    // Smart header detection
    $first_row = fgetcsv($handle, 0, $delimiter);
    if ($first_row) {
        // Check if it's a header row by looking for common header keywords
        $first_col = strtolower(trim($first_row[0]));
        $is_header = (
            $first_col === 'exam_set' || 
            $first_col === 'question_number' || 
            strpos($first_col, 'exam') !== false ||
            strpos($first_col, 'question') !== false
        );
        
        if (!$is_header) {
            // It's data, not header - put it back for processing
            rewind($handle);
        }
    }
    
    
    try {
        // Prepare statements based on database type
        if ($is_mysql) {
            // Use INSERT IGNORE to preserve existing descriptions (from Master TBP)
            // Only insert if new.
            $indicator_stmt = $pdo->prepare("
                INSERT IGNORE INTO indicators (code, description, subject, grade_level)
                VALUES (?, ?, ?, ?)
            ");
        } else {
            // SQLite
            $indicator_stmt = $pdo->prepare("
                INSERT OR IGNORE INTO indicators (code, description, subject, grade_level)
                VALUES (?, ?, ?, ?)
            ");
        }
        

        
        $count = 0;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($data) < 3) continue;
            
            // Check if first column is exam_set (non-numeric) or question_number (numeric)
            $has_exam_set = !is_numeric(trim($data[0]));
            
            if ($has_exam_set) {
                // New format: exam_set, question_number, indicator_code, description, subject, max_score, grade_level
                $exam_set = sanitizeCSV($data[0]);
                
                // Validate exam_set is not empty or 'default'
                if (empty($exam_set) || $exam_set === 'default') {
                    fclose($handle);
                    return ['success' => false, 'message' => "❌ กรุณาระบุชื่อชุดข้อสอบ (exam_set) ที่ไม่ใช่ 'default' ในแถวที่ " . ($count + 1)];
                }
                
                $question_number = intval($data[1]);
                $indicator_codes_str = sanitizeCSV($data[2]);
                $description = sanitizeCSV($data[3]);
                $subject = sanitizeCSV($data[4] ?? '');
                $max_score = isset($data[5]) ? floatval($data[5]) : 1.00;
                $grade_level = isset($data[6]) ? sanitizeCSV($data[6]) : null;
                

            } else {
                // Old format no longer supported - require exam_set
                fclose($handle);
                return ['success' => false, 'message' => "❌ รูปแบบไฟล์ไม่ถูกต้อง: กรุณาระบุ exam_set ในคอลัมน์แรก (ตัวอย่าง: Pre-ONET-2566-R1-วิทย์)"];
            }
            
            // Validate max_score
            if ($max_score <= 0) $max_score = 1.00;
            
            if ($question_number <= 0 || empty($indicator_codes_str)) continue;
            
            // Split indicator codes (support comma-separated)
            $indicator_codes = array_map('trim', explode(',', $indicator_codes_str));
            
            // Create or update question (composite key: question_number + exam_set + grade_level)
            // Fix: Include grade_level in uniqueness check to prevent collisions between M.3/M.6 sharing same exam_set name
            $q_check_sql = "SELECT id FROM questions WHERE question_number = ? AND exam_set = ?";
            $q_check_params = [$question_number, $exam_set];
            
            if (!empty($grade_level)) {
                $q_check_sql .= " AND grade_level = ?";
                $q_check_params[] = $grade_level;
            }
            
            $q_check = $pdo->prepare($q_check_sql);
            $q_check->execute($q_check_params);
            $question_id = $q_check->fetchColumn();
            
            if ($question_id) {
                // Update existing question
                $q_update = $pdo->prepare("UPDATE questions SET max_score = ?, subject = ?, grade_level = ? WHERE id = ?");
                $q_update->execute([$max_score, $subject, $grade_level, $question_id]);
            } else {
                // Insert new question
                $q_insert = $pdo->prepare("INSERT INTO questions (question_number, max_score, subject, exam_set, grade_level) VALUES (?, ?, ?, ?, ?)");
                $q_insert->execute([$question_number, $max_score, $subject, $exam_set, $grade_level]);
                $question_id = $pdo->lastInsertId();
            }
            
            // Clear existing question-indicator mappings for this question
            $pdo->prepare("DELETE FROM question_indicators WHERE question_id = ?")->execute([$question_id]);
            
            // Create new mappings for each indicator
            foreach ($indicator_codes as $code) {
                if (empty($code)) continue;
                
                // Normalize code (remove spaces, fix dots)
                $code = normalizeIndicatorCode($code);
                
                // Insert or update indicator
                if ($is_mysql) {
                    $indicator_stmt = $pdo->prepare("
                        INSERT IGNORE INTO indicators (code, description, subject, grade_level, exam_set)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $indicator_stmt->execute([$code, $description, $subject, $grade_level, $exam_set]);
                } else {
                    $indicator_stmt = $pdo->prepare("
                        INSERT OR IGNORE INTO indicators (code, description, subject, grade_level, exam_set)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $indicator_stmt->execute([$code, $description, $subject, $grade_level, $exam_set]);
                }
                
                // Get indicator ID specific to this exam set (or default if it's a master indicator being referenced)
                $id_stmt = $pdo->prepare("SELECT id FROM indicators WHERE code = ? AND (exam_set = ? OR exam_set = 'default') ORDER BY CASE WHEN exam_set = 'default' THEN 1 ELSE 0 END LIMIT 1");
                $id_stmt->execute([$code, $exam_set]);
                $indicator_id = $id_stmt->fetchColumn();
                
                if ($indicator_id) {
                    // Create junction record
                    $junction_stmt = $pdo->prepare("
                        INSERT IGNORE INTO question_indicators (question_id, indicator_id)
                        VALUES (?, ?)
                    ");
                    $junction_stmt->execute([$question_id, $indicator_id]);
                }
            }
            
            $count++;
        }
        
        fclose($handle);
        
        if ($count === 0) {
            throw new Exception('ไม่พบข้อมูลที่ถูกต้องในไฟล์');
        }
        
    } catch (Exception $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw $e;
    }
}

/**
 * Import master indicators from CSV (all curriculum indicators)
 * Format: code, description, subject, grade_level, exam_set
 * This allows importing all curriculum indicators for a specific test blueprint
 */
function importMasterIndicators($pdo, $file, $is_mysql = false) {
    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new Exception('ไม่สามารถเปิดไฟล์ได้');
    }
    
    $delimiter = detectDelimiter($handle);
    
    // Smart header detection
    $first_row = fgetcsv($handle, 0, $delimiter);
    if ($first_row) {
        // Check if first column looks like indicator code (contains letters)
        if (preg_match('/^[a-zA-Zก-๙]/', trim($first_row[0])) && count($first_row) >= 3) {
            // Could be header or data - check if it looks like a header
            $first_col_lower = mb_strtolower(trim($first_row[0]));
            if (in_array($first_col_lower, ['code', 'รหัส', 'indicator', 'ตัวชี้วัด'])) {
                // It's a header, skip it
            } else {
                // It's data, rewind to read it again
                rewind($handle);
            }
        } else {
            // Doesn't look like valid data, assume it's a header
        }
    }
    
    try {
        // Prepare statements based on database type
        if ($is_mysql) {
            $stmt = $pdo->prepare("
                INSERT INTO indicators (code, description, subject, grade_level, exam_set)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    subject = VALUES(subject),
                    grade_level = VALUES(grade_level),
                    exam_set = VALUES(exam_set)
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO indicators (code, description, subject, grade_level, exam_set)
                VALUES (?, ?, ?, ?, ?)
            ");
        }
        
        $count = 0;
        $subjects = [];
        
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($data) < 3) continue;
            
            $code = sanitizeCSV($data[0]);
            $code = normalizeIndicatorCode($code); // Normalize code
            $description = sanitizeCSV($data[1]);
            $subject = sanitizeCSV($data[2]);
            $grade_level = isset($data[3]) ? sanitizeCSV($data[3]) : null;
            $exam_set = isset($data[4]) ? sanitizeCSV($data[4]) : 'default';
            
            if (empty($code) || empty($subject)) continue;
            
            // Track subjects
            if (!in_array($subject, $subjects)) {
                $subjects[] = $subject;
            }
            
            // Insert or update indicator
            if ($is_mysql) {
                $stmt->execute([$code, $description, $subject, $grade_level, $exam_set]);
            } else {
                $stmt->execute([$code, $description, $subject, $grade_level, $exam_set]);
            }
            
            $count++;
        }
        
        fclose($handle);
        
        if ($count === 0) {
            throw new Exception('ไม่พบข้อมูลที่ถูกต้องในไฟล์');
        }
        
        error_log("Imported $count master indicators for subjects: " . implode(', ', $subjects));
        
    } catch (Exception $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw $e;
    }
}


/**
 * Import scores from CSV (wide format) for a specific subject
 * Format: student_id, q1, q2, q3, ...
 * Maps columns to actual question numbers based on selected subject
 */
function importScores($pdo, $file, $subject, $exam_set = 'default', $grade_level = '', $is_mysql = false) {
    $handle = fopen($file, 'r');
    if (!$handle) {
        throw new Exception('ไม่สามารถเปิดไฟล์ได้');
    }
    
    // Get question numbers for this subject, exam_set AND GRADE
    $sql = "
        SELECT DISTINCT q.question_number
        FROM questions q
        WHERE q.subject = ? AND q.exam_set = ?
    ";
    $params = [$subject, $exam_set];
    
    if ($grade_level) {
        $sql .= " AND q.grade_level = ?";
        $params[] = $grade_level;
    }
    
    $sql .= " ORDER BY CAST(q.question_number AS UNSIGNED), q.question_number";
    
    $question_stmt = $pdo->prepare($sql);
    $question_stmt->execute($params);
    $questions = $question_stmt->fetchAll(PDO::FETCH_COLUMN);
    $questions_count = count($questions);
    
    // DEBUG: Check database and query details
    // Note: $grade_level is not available in this scope for importScores, so it's removed from debug log.
    if ($questions_count == 37) { // Only debug if suspicious count
         $db_name = defined('DB_NAME') ? DB_NAME : 'unknown';
         error_log("DEBUG: DB=$db_name, Subject=$subject, Set=$exam_set, Count=$questions_count");
    }

    if (empty($questions)) {
        fclose($handle);
        throw new Exception("ไม่พบข้อมูลข้อสอบสำหรับวิชา \"$subject\" ชุด \"$exam_set\" (กรุณานำเข้าข้อสอบก่อน)");
    }
    
    $delimiter = detectDelimiter($handle);
    
    // Read header (or first row)
    $header = fgetcsv($handle, 0, $delimiter);
    $is_header_row = true;
    
    if (!$header || count($header) < 2) {
        fclose($handle);
        throw new Exception('รูปแบบไฟล์ไม่ถูกต้อง');
    }
    
    // Check column count
    // Header format: student_id, q1, q2, ...
    // Expected columns: 1 (student_id) + question_count
    $col_count = count($header);
    // If header doesn't look like q1, q2... might be raw data?
    // User data example: student_id, 1, 2, 3 ... (35 scores) -> 36 cols?
    // User says "35 cols found" vs "37 items".
    // If user file has 35 score columns + 1 student_id = 36 columns.
    // Logic below expects $questions_count + 1.
    
    // Let's print strict debug info in Exception
    if ($col_count != ($questions_count + 1) && $col_count != $questions_count) {
        $q_list = implode(', ', $questions);
        $db_debug = defined('DB_NAME') ? DB_NAME : 'unknown';
        throw new Exception("ข้อมูลเชิงเทคนิค (Debug Info):\nฐานข้อมูล: $db_debug\nจำนวนคอลัมน์ที่พบ: $col_count (ระบบต้องการ " . ($questions_count + 1) . " หรือ $questions_count)\nจำนวนข้อสอบในระบบ ($questions_count ข้อ): $q_list\nเงื่อนไข: $subject / $exam_set / Level: $grade_level\n\nคำแนะนำ: ไฟล์ CSV ควรมีคอลัมน์แรกเป็นรหัสนักเรียน และตามด้วยคะแนนแต่ละข้อเท่านั้น (รวม " . ($questions_count + 1) . " คอลัมน์) หากมีคอลัมน์ 'ลำดับ', 'ชื่อ', หรือ 'นามสกุล' ให้ลบออกครับ");
    }
    // Smart Header Detection
    // If the first column (Student ID) is numeric, assume it's DATA, not Header
    if (is_numeric(trim($header[0]))) {
        $is_header_row = false;
        // Verify column count matches first
        // If it matches, we rewind.
        // We use $header just for counting columns now.
    }
    
    // Check if number of columns matches number of questions for this subject
    $num_score_columns = count($header) - 1; // Exclude student_id column
    if ($num_score_columns != count($questions)) {
        fclose($handle);
        throw new Exception(
            "จำนวนคอลัมน์ในไฟล์ ($num_score_columns ข้อ) ไม่ตรงกับจำนวนข้อสอบวิชา \"$subject\" (" . 
            count($questions) . " ข้อ)\n" .
            "ข้อสอบวิชานี้: " . implode(', ', $questions) . "\n" .
            ($is_header_row ? "" : "(หมายเหตุ: ตรวจพบว่าไฟล์ไม่มีหัวตาราง ระบบนับจำนวนคอลัมน์จากแถวแรก)")
        );
    }
    
    // If it was Data, Rewind!
    if (!$is_header_row) {
        rewind($handle);
    }
    
    
    try {
        if ($is_mysql) {
            $stmt = $pdo->prepare("
                INSERT INTO scores (student_id, question_number, score_obtained, exam_set)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    score_obtained = ?
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO scores (student_id, question_number, score_obtained, exam_set)
                VALUES (?, ?, ?, ?)
            ");
        }
        
        $count = 0;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($data) < 2) continue;
            
            $student_id = sanitizeCSV($data[0]);
            if (empty($student_id)) continue;
            
            // Process each question score
            for ($i = 1; $i < count($data); $i++) {
                // Map column index to actual question number
                $question_number = $questions[$i - 1]; // Fix undefined variable
                
                $raw_val = trim($data[$i]);
                
                // If not numeric (e.g. '-', empty, 'absent'), treat as Absent -> Delete record
                if (!is_numeric($raw_val)) {
                    // Delete existing score if any (to update from 0/score to Absent)
                    $del_sql = "DELETE FROM scores WHERE student_id = ? AND question_number = ? AND exam_set = ?";
                    $del_stmt = $pdo->prepare($del_sql);
                    $del_stmt->execute([$student_id, $question_number, $exam_set]);
                    continue; 
                }
                
                $score = floatval($raw_val);
                
                // Get max_score for this question and exam_set AND GRADE
                $max_sql = "SELECT max_score FROM questions WHERE question_number = ? AND exam_set = ?";
                $max_params = [$question_number, $exam_set];
                
                if ($grade_level) {
                   $max_sql .= " AND grade_level = ?";
                   $max_params[] = $grade_level;
                }
                
                $max_stmt = $pdo->prepare($max_sql);
                $max_stmt->execute($max_params);
                $max_score = $max_stmt->fetchColumn();
                
                // Validate score (must be between 0 and max_score)
                if ($score < 0 || $score > $max_score) {
                    throw new Exception("คะแนนไม่ถูกต้องสำหรับข้อ $question_number: $score (คะแนนเต็ม: $max_score)");
                }
                
                if ($is_mysql) {
                    $stmt->execute([$student_id, $question_number, $score, $exam_set, $score]);
                } else {
                    $stmt->execute([$student_id, $question_number, $score, $exam_set]);
                }
                $count++;
            }
        }
        
        fclose($handle);
        
        if ($count === 0) {
            throw new Exception('ไม่พบข้อมูลที่ถูกต้องในไฟล์');
        }
        
    } catch (Exception $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw $e;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>นำเข้าข้อมูล - ระบบวิเคราะห์ผลสอบ O-NET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="d-flex flex-column">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <img src="logo.png" alt="" width="30" height="30" class="d-inline-block align-text-top me-2" onerror="this.style.display='none'">
                โรงเรียนซับใหญ่วิทยาคม
                <span class="fs-6 text-white-50 ms-2">| ระบบวิเคราะห์ผลสอบ O-NET</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">หน้าหลัก</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="import.php">นำเข้าข้อมูล</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_exams.php">จัดการข้อสอบ</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="compare.php">เปรียบเทียบ</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">ตั้งค่า</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4 flex-grow-1">
        <h1 class="mb-4">นำเข้าข้อมูล CSV</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Import Students -->
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">📋 นำเข้าข้อมูลนักเรียน</h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">รูปแบบไฟล์ CSV:</p>
                        <code class="d-block mb-3">
                            student_id, prefix, name, grade_level, room_number
                        </code>
                        <p class="text-muted small">ตัวอย่าง: 12345, นาย, สมชาย ใจดี, M3, 1</p>
                        
                        <form method="POST" enctype="multipart/form-data" id="studentsForm">
                            <input type="hidden" name="import_type" value="students">
                            
                            <ul class="nav nav-tabs mb-3" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="students-file-tab" data-bs-toggle="tab" data-bs-target="#students-file" type="button">📁 อัปโหลดไฟล์</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="students-paste-tab" data-bs-toggle="tab" data-bs-target="#students-paste" type="button">📋 วางข้อมูล</button>
                                </li>
                            </ul>
                            
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="students-file">
                                    <div class="mb-3">
                                        <label class="form-label">ไฟล์ CSV</label>
                                        <input type="file" class="form-control" name="csv_file" accept=".csv">
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="students-paste">
                                    <div class="mb-3">
                                        <label class="form-label">วางข้อมูลจาก Excel/Google Sheets</label>
                                        <textarea class="form-control font-monospace" name="csv_data" rows="8" placeholder="student_id	prefix	name	grade_level	room_number
60001	นาย	สมชาย ใจดี	M3	1
60002	นางสาว	สมหญิง รักเรียน	M3	1"></textarea>
                                        <small class="text-muted">คัดลอกข้อมูลจาก Excel/Sheets แล้ววางที่นี่ (รวมหัวตาราง)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">นำเข้าข้อมูล</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Import Indicators -->
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">🎯 นำเข้าตัวชี้วัดและข้อสอบ</h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">รูปแบบไฟล์ CSV:</p>
                        <code class="d-block mb-2">
                            exam_set, question_number, indicator_code, description, subject, max_score, grade_level
                        </code>
                        
                        <div class="alert alert-info py-2 small">
                            <strong>📌 คำแนะนำ:</strong>
                            <ul class="mb-0 ps-3">
                                <li><strong>หลายตัวชี้วัดใน 1 ข้อ:</strong> ให้คั่นด้วยคอมม่า เช่น <code>ว1.1, ว1.2</code></li>
                                <li><strong>คะแนนเต็ม (max_score):</strong>
                                    <ul>
                                        <li>ข้อช้อยส์ปกติ: ใส่คะแนนเต็มได้เลย (เช่น 1 หรือ 3.125)</li>
                                        <li>ข้อเชิงซ้อน: ใส่คะแนนเต็มรวม (เช่น 5.20)</li>
                                    </ul>
                                </li>
                            </ul>
                        </div>

                        <div class="alert alert-warning mb-2">
                            <strong>⚠️ สำคัญ: การตั้งชื่อ exam_set</strong>
                            <p class="mb-1 small">ถ้าข้อสอบหลายวิชามีเลขข้อเดียวกัน (เช่น ทุกวิชาเริ่มข้อ 1) <strong>ต้องตั้งชื่อ exam_set แยกตามวิชา</strong></p>
                            <p class="mb-0 small"><strong>ตัวอย่าง:</strong></p>
                            <ul class="small mb-0">
                                <li><code>Pre-ONET-2566-R1-วิทย์</code> สำหรับวิทยาศาสตร์</li>
                                <li><code>Pre-ONET-2566-R1-คณิต</code> สำหรับคณิตศาสตร์</li>
                            </ul>
                        </div>
                        
                        <p class="text-muted small mb-1"><strong>ตัวอย่างข้อมูล:</strong></p>
                        <pre class="bg-light p-2 rounded small">Pre-ONET-2566-R1-วิทย์, 1, "ว1.1 ม.3/1, ว1.2 ม.3/4", ระบบนิเวศ, วิทยาศาสตร์, 2.4, ม.3
Pre-ONET-2566-R1-คณิต, 1, ค1.1 ม.3/1, การคำนวณ, คณิตศาสตร์, 3.125, ม.3</pre>
                        <p class="text-danger small"><strong>⚠️ บังคับระบุ exam_set</strong> - ห้ามใช้ 'default' หรือเว้นว่าง</p>
                        
                        <form method="POST" enctype="multipart/form-data" id="indicatorsForm">
                            <input type="hidden" name="import_type" value="indicators">
                            
                            <ul class="nav nav-tabs mb-3" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="indicators-file-tab" data-bs-toggle="tab" data-bs-target="#indicators-file" type="button">📁 อัปโหลดไฟล์</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="indicators-paste-tab" data-bs-toggle="tab" data-bs-target="#indicators-paste" type="button">📋 วางข้อมูล</button>
                                </li>
                            </ul>
                            
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="indicators-file">
                                    <div class="mb-3">
                                        <label class="form-label">ไฟล์ CSV</label>
                                        <input type="file" class="form-control" name="csv_file" accept=".csv">
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="indicators-paste">
                                    <div class="mb-3">
                                        <label class="form-label">วางข้อมูลจาก Excel/Google Sheets</label>
                                        <textarea class="form-control font-monospace" name="csv_data" rows="8" placeholder="exam_set	question_number	indicator_code	description	subject	max_score	grade_level
Pre-ONET-2566-R1	1	ว1.1 ม.3/1	ระบบนิเวศ	วิทยาศาสตร์	2.4	ม.3
Pre-ONET-2566-R1	2	ว1.2 ม.3/1	การลำเลียง	วิทยาศาสตร์	2.4	ม.3
ONET-2566	1	ว1.1 ม.3/1	ระบบนิเวศ	วิทยาศาสตร์	2.4	ม.3"></textarea>
                                        <small class="text-muted">คัดลอกข้อมูลจาก Excel/Sheets แล้ววางที่นี่ (รวมหัวตาราง)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-success w-100">นำเข้าข้อมูล</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Import Master Indicators (NEW) -->
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">📚 นำเข้าตัวชี้วัดทั้งหมด</h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">รูปแบบไฟล์ CSV:</p>
                        <code class="d-block mb-3">
                            code, description, subject, grade_level, exam_set
                        </code>
                        <p class="text-muted small">ตัวอย่าง: ว1.1 ม.3/1, อธิบาย..., วิทยาศาสตร์, ม.3, Pre-ONET-2568-R1</p>
                        <div class="alert alert-info small mb-3">
                            <strong>💡 สำหรับนำเข้าตัวชี้วัดทั้งหมดจาก Test Blueprint</strong><br>
                            ไม่ต้องผูกกับข้อสอบ เพื่อดูว่าตัวชี้วัดไหนไม่เคยออกสอบ<br>
                            <strong>ระบุ exam_set</strong> เพื่อแยก Test Blueprint แต่ละปี/ชุด<br>
                            <strong>ระบุระดับชั้น</strong> (ม.3, ม.6) เพื่อแยกตัวชี้วัดแต่ละระดับ
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="masterIndicatorsForm">
                            <input type="hidden" name="import_type" value="master_indicators">
                            
                            <ul class="nav nav-tabs mb-3" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="master-file-tab" data-bs-toggle="tab" data-bs-target="#master-file" type="button">📁 อัปโหลดไฟล์</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="master-paste-tab" data-bs-toggle="tab" data-bs-target="#master-paste" type="button">📋 วางข้อมูล</button>
                                </li>
                            </ul>
                            
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="master-file">
                                    <div class="mb-3">
                                        <label class="form-label">ไฟล์ CSV</label>
                                        <input type="file" class="form-control" name="csv_file" accept=".csv">
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="master-paste">
                                    <div class="mb-3">
                                        <label class="form-label">วางข้อมูลจาก Excel/Google Sheets</label>
                                        <textarea class="form-control font-monospace" name="csv_data" rows="8" placeholder="code	description	subject	grade_level
ว1.1 ม.3/1	อธิบายการเปลี่ยนแปลง...	วิทยาศาสตร์	ม.3
ว1.1 ม.6/1	วิเคราะห์การเปลี่ยนแปลง...	วิทยาศาสตร์	ม.6
ค1.1 ม.3/1	การคำนวณ...	คณิตศาสตร์	ม.3"></textarea>
                                        <small class="text-muted">คัดลอกข้อมูลจาก Excel/Sheets แล้ววางที่นี่</small>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-info w-100">นำเข้าข้อมูล</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Import Scores -->
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">📊 นำเข้าคะแนนสอบ</h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">รูปแบบไฟล์ CSV (Wide Format):</p>
                        <code class="d-block mb-3">
                            student_id, q1, q2, q3, ...
                        </code>
                        <div class="alert alert-info py-2 small">
                            <strong>📌 การกรอกคะแนน:</strong>
                            <ul class="mb-0 ps-3">
                                <li><strong>คะแนนที่ต้องกรอก:</strong> ให้กรอก <strong>"คะแนนดิบที่นักเรียนได้จริง"</strong> (เช่น ข้อละ 4 คะแนน ถ้าถูกให้กรอก 4, ผิดกรอก 0)</li>
                                <li><strong>ข้อห้าม:</strong> ห้ามกรอกเลข 1 แทนคะแนนเต็ม (ยกเว้นข้อนั้นคะแนนเต็ม 1 จริงๆ) เพราะระบบจะนำเลขที่ท่านกรอกไปใช้คำนวณทันที</li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-info small mb-3">
                            <strong>สำคัญ!</strong> กรุณาเลือกวิชาก่อนนำเข้า ระบบจะ map คอลัมน์ในไฟล์ไปยังข้อสอบของวิชานั้นโดยอัตโนมัติ
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="scoresForm">
                            <input type="hidden" name="import_type" value="scores">
                            
                            <div class="mb-3">
                                <label class="form-label">ระดับชั้น <span class="text-danger">*</span></label>
                                <select class="form-select" name="grade_level" required>
                                    <option value="">-- เลือกระดับชั้น --</option>
                                    <?php
                                    // Get distinct grade levels from indicators
                                    try {
                                        $grades_stmt = $pdo->query("SELECT DISTINCT grade_level FROM indicators WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level");
                                        while ($row = $grades_stmt->fetch(PDO::FETCH_ASSOC)) {
                                            $grade = htmlspecialchars($row['grade_level']);
                                            echo "<option value=\"$grade\">$grade</option>";
                                        }
                                    } catch (Exception $e) {
                                        // Ignore
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">เลือกวิชา <span class="text-danger">*</span></label>
                                <select class="form-select" name="subject" required>
                                    <option value="">-- กรุณาเลือกระดับชั้นก่อน --</option>
                                    <?php
                                    try {
                                    } catch (Exception $e) {
                                        echo "<option value=\"\" disabled>ไม่พบข้อมูลวิชา</option>";
                                    }
                                    ?>
                                    <?php
                                    // Initial subjects load - we'll clear this in JS and load based on grade
                                    ?>
                                </select>
                                </select>
                                <div class="form-text">ไฟล์ CSV ต้องมีจำนวนคอลัมน์ตรงกับจำนวนข้อสอบของวิชานั้น</div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">ชุดข้อสอบ <span class="text-danger">*</span></label>
                                <select class="form-select" name="exam_set" required>
                                    <option value="">-- เลือกชุดข้อสอบ --</option>
                                    <option value="">-- กรุณาเลือกวิชาก่อน --</option>
                                    <?php
                                    // Previously this loaded all exam sets. 
                                    // Now we use JavaScript to populate this based on selected subject.
                                    // We keep it empty initially or with a prompt to select subject.
                                    ?>
                                </select>
                                <div class="form-text">เลือกชุดข้อสอบที่ต้องการนำเข้าคะแนน (ต้องนำเข้าข้อสอบพร้อม exam_set ก่อน)</div>
                            </div>
                            
                            <ul class="nav nav-tabs mb-3" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="scores-file-tab" data-bs-toggle="tab" data-bs-target="#scores-file" type="button">📁 อัปโหลดไฟล์</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="scores-paste-tab" data-bs-toggle="tab" data-bs-target="#scores-paste" type="button">📋 วางข้อมูล</button>
                                </li>
                            </ul>
                            
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="scores-file">
                                    <div class="mb-3">
                                        <label class="form-label">ไฟล์ CSV</label>
                                        <input type="file" class="form-control" name="csv_file" accept=".csv">
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="scores-paste">
                                    <div class="mb-3">
                                        <label class="form-label">วางข้อมูลจาก Excel/Google Sheets</label>
                                        <textarea class="form-control font-monospace" name="csv_data" rows="8" placeholder="student_id	1	2	3	4
60001	2.4	2.4	2.4	2.4
60002	2.4	2.4	2.4	2.4
60003	2.4	2.4	2.4	0"></textarea>
                                        <small class="text-muted">คัดลอกข้อมูลจาก Excel/Sheets (student_id + คะแนนแต่ละข้อ)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-warning w-100">นำเข้าข้อมูล</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Instructions -->
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">📖 คำแนะนำการนำเข้าข้อมูล</h5>
            </div>
            <div class="card-body">
                <h6>ลำดับการนำเข้า:</h6>
                <ol>
                    <li class="mb-2">นำเข้าข้อมูล<strong>นักเรียน</strong>ก่อน</li>
                    <li class="mb-2"><strong>(แนะนำ)</strong> นำเข้า<strong>ตัวชี้วัดทั้งหมด</strong> จาก Test Blueprint ของปีนั้นๆ (เช่น วิทยาศาสตร์ 102 ตัว) เพื่อวัดผลการสอน</li>
                    <li class="mb-2">จากนั้นนำเข้า<strong>ตัวชี้วัดและข้อสอบ</strong> (สำคัญ! ต้องระบุวิชาและระดับชั้น)</li>
                    <li class="mb-2">สุดท้ายนำเข้า<strong>คะแนนสอบ</strong> (เลือกวิชาและชุดข้อสอบก่อนนำเข้า)</li>
                </ol>
                
                <div class="alert alert-success mt-3">
                    <h6 class="alert-heading"><strong>✨ การนำเข้าตัวชี้วัดทั้งหมด (Test Blueprint)</strong></h6>
                    <p class="mb-2">นำเข้าตัวชี้วัดทั้งหมดที่กำหนดไว้ใน <strong>Test Blueprint</strong> ของปีนั้นๆ (เช่น วิทยาศาสตร์ ม.3 มี 102 ตัวชี้วัด)</p>
                    <p class="mb-2"><strong>วัตถุประสงค์:</strong></p>
                    <ul class="mb-2">
                        <li><strong>วัดผลการสอน</strong> - ดูว่านักเรียนทำได้ดีในตัวชี้วัดไหน ตัวชี้วัดไหนยังอ่อน</li>
                        <li><strong>วิเคราะห์ความครอบคลุม</strong> - ดูว่าข้อสอบครอบคลุมตัวชี้วัดกี่ตัว (เช่น 40 ข้อครอบคลุม 102 ตัวชี้วัด = 39%)</li>
                        <li><strong>เห็นตัวชี้วัดที่ไม่มีข้อสอบ</strong> - ตัวชี้วัดที่ยังไม่ได้ออกข้อสอบ</li>
                        <li><strong>แยกตามระดับชั้น</strong> - วิเคราะห์ ม.3 และ ม.6 แยกกัน</li>
                        <li><strong>แยกตาม Test Blueprint</strong> - แต่ละปี/ชุดมีตัวชี้วัดไม่เท่ากัน</li>
                    </ul>
                    <p class="mb-0"><strong>รูปแบบ CSV:</strong> code, description, subject, grade_level, exam_set</p>
                </div>
                
                <div class="alert alert-warning mt-3">
                    <h6 class="alert-heading"><strong>⚠️ สำคัญ: รูปแบบไฟล์ mapping.csv (ตัวชี้วัดและข้อสอบ)</strong></h6>
                    <p class="mb-2">ระบบรองรับ<strong>หลายชุดข้อสอบ</strong> (Pre O-NET, O-NET) และ<strong>แยกระดับชั้น</strong> (ม.3, ม.6)</p>
                    <p class="mb-2"><strong>รูปแบบ CSV แบบใหม่ (แนะนำ):</strong></p>
                    <pre class="bg-light p-2 rounded"><code>exam_set,question_number,indicator_code,description,subject,max_score,grade_level
Pre-ONET-2566-R1,1,ว1.1 ม.3/1,ระบบนิเวศ,วิทยาศาสตร์,2.4,ม.3
Pre-ONET-2566-R1,2,ค1.1 ม.3/1,การคำนวณ,คณิตศาสตร์,2.4,ม.3
ONET-2566,1,ว1.1 ม.3/1,ระบบนิเวศ,วิทยาศาสตร์,2.4,ม.3</code></pre>
                    <p class="mb-2"><strong>รูปแบบเก่า (ยังใช้ได้):</strong></p>
                    <pre class="bg-light p-2 rounded"><code>question_number,indicator_code,description,subject,max_score,grade_level
1,ว1.1 ม.3/1,ระบบนิเวศ,วิทยาศาสตร์,2.4,ม.3</code></pre>
                    <p class="mb-0"><strong>หมายเหตุ:</strong> ถ้าไม่ระบุ exam_set จะใช้ 'default' / ชื่อวิชาต้องสะกดเหมือนกันทุกครั้ง</p>
                </div>
                
                <div class="alert alert-success mt-3">
                    <h6 class="alert-heading"><strong>✅ การนำเข้าคะแนนตามวิชาและชุดข้อสอบ</strong></h6>
                    <p class="mb-2">เมื่อนำเข้าคะแนนสอบ คุณต้อง<strong>เลือกวิชา</strong>และ<strong>เลือกชุดข้อสอบ</strong>ก่อน ระบบจะทำงานดังนี้:</p>
                    <ol class="mb-2">
                        <li>ดึงรายการข้อสอบทั้งหมดของ<strong>วิชา + ชุดข้อสอบ</strong>ที่เลือก (เช่น วิทยาศาสตร์ ชุด Pre-ONET-2566-R1 มีข้อ 1-40)</li>
                        <li>อ่านไฟล์ CSV ของคุณ (student_id, q1, q2, q3, ...)</li>
                        <li>Map คอลัมน์ q1→ข้อ1, q2→ข้อ2, q3→ข้อ3, ...</li>
                        <li>บันทึกคะแนนลงฐานข้อมูลพร้อม exam_set</li>
                    </ol>
                    <p class="mb-2"><strong>ตัวอย่าง:</strong></p>
                    <ul class="mb-0">
                        <li>วิชา: วิทยาศาสตร์, ชุด: Pre-ONET-2566-R1 (40 ข้อ) → ไฟล์ต้องมี 41 คอลัมน์</li>
                        <li>วิชา: คณิตศาสตร์, ชุด: ONET-2566 (30 ข้อ) → ไฟล์ต้องมี 31 คอลัมน์</li>
                    </ul>
                </div>
                
                <h6 class="mt-3">ข้อควรระวัง:</h6>
                <ul>
                    <li class="mb-2">ไฟล์ CSV ต้องเป็น <strong>UTF-8 encoding</strong> เพื่อรองรับภาษาไทย</li>
                    <li class="mb-2">หากมีข้อมูลซ้ำ ระบบจะอัพเดทข้อมูลเดิมโดยอัตโนมัติ</li>
                    <li class="mb-2">คะแนนต้องเป็น <strong>0</strong> (ผิด) หรือ <strong>1</strong> (ถูก) เท่านั้น</li>
                    <li class="mb-2">จำนวนคอลัมน์ในไฟล์ต้อง<strong>ตรงกับจำนวนข้อสอบ</strong>ของวิชาที่เลือก</li>
                    <li class="mb-2">ชื่อวิชาที่ระบุจะปรากฏในตัวกรอง "รายวิชา" ของหน้า Dashboard</li>
                </ul>
            </div>
        </div>
    </div>

    <?php
    // Prepare data for Grade-Subject-ExamSet mapping (3-level cascade)
    $cascade_mapping = [];
    try {
        $map_stmt = $pdo->query("
            SELECT DISTINCT grade_level, subject, exam_set 
            FROM questions 
            WHERE exam_set IS NOT NULL AND exam_set != '' AND exam_set != 'default' 
            AND grade_level IS NOT NULL AND grade_level != ''
            ORDER BY grade_level, subject, exam_set DESC
        ");
        while ($row = $map_stmt->fetch(PDO::FETCH_ASSOC)) {
            $g = $row['grade_level'];
            $s = $row['subject'];
            $e = $row['exam_set'];
            
            if (!isset($cascade_mapping[$g])) {
                $cascade_mapping[$g] = [];
            }
            if (!isset($cascade_mapping[$g][$s])) {
                $cascade_mapping[$g][$s] = [];
            }
            // Add exam set if not already present
            if (!in_array($e, $cascade_mapping[$g][$s])) {
                $cascade_mapping[$g][$s][] = $e;
            }
        }
    } catch (Exception $e) {
        // Silently fail
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 3-Level Cascade: Grade -> Subject -> Exam Set
        const gradeSelect = document.querySelector('select[name="grade_level"]');
        const subjectSelect = document.querySelector('select[name="subject"]');
        const examSetSelect = document.querySelector('select[name="exam_set"]');
        const cascadeData = <?php echo json_encode($cascade_mapping); ?>;
        
        if (gradeSelect && subjectSelect && examSetSelect) {
            
            function updateSubjects() {
                const grade = gradeSelect.value;
                subjectSelect.innerHTML = '<option value="">-- เลือกวิชา --</option>';
                examSetSelect.innerHTML = '<option value="">-- กรุณาเลือกวิชาก่อน --</option>';
                examSetSelect.disabled = true;
                
                if (grade && cascadeData[grade]) {
                    subjectSelect.disabled = false;
                    const subjects = Object.keys(cascadeData[grade]);
                    subjects.sort();
                    
                    subjects.forEach(function(subj) {
                        const option = document.createElement('option');
                        option.value = subj;
                        option.textContent = subj;
                        subjectSelect.appendChild(option);
                    });
                } else {
                    subjectSelect.disabled = true;
                    if (!grade) {
                        subjectSelect.innerHTML = '<option value="">-- กรุณาเลือกระดับชั้นก่อน --</option>';
                    } else {
                        subjectSelect.innerHTML = '<option value="">ไม่มีวิชาสำหรับระดับชั้นนี้</option>';
                    }
                }
            }

            function updateExamSets() {
                const grade = gradeSelect.value;
                const subject = subjectSelect.value;
                
                examSetSelect.innerHTML = '<option value="">-- เลือกชุดข้อสอบ --</option>';
                
                if (grade && subject && cascadeData[grade] && cascadeData[grade][subject]) {
                    examSetSelect.disabled = false;
                    const examSets = cascadeData[grade][subject];
                    
                    examSets.forEach(function(examSet) {
                        const option = document.createElement('option');
                        option.value = examSet;
                        option.textContent = examSet;
                        examSetSelect.appendChild(option);
                    });
                    
                    // Auto-select if only one
                    if (examSets.length === 1) {
                        examSetSelect.selectedIndex = 1;
                    }
                } else {
                    examSetSelect.disabled = true;
                    if (!subject) {
                         examSetSelect.innerHTML = '<option value="">-- กรุณาเลือกวิชาก่อน --</option>';
                    } else {
                         examSetSelect.innerHTML = '<option value="">ไม่มีชุดข้อสอบสำหรับวิชานี้</option>';
                    }
                }
            }
            
            // Listeners
            gradeSelect.addEventListener('change', updateSubjects);
            subjectSelect.addEventListener('change', updateExamSets);
            
            // Initial state: Subject disabled if no grade
            if (!gradeSelect.value) {
                subjectSelect.disabled = true;
                examSetSelect.disabled = true;
            } else {
                // If browser remembers selection, try to restore
                // But for simplicity in this MVP, might be better to reset or trigger updates
                updateSubjects();
            }
        }
    });
    </script>
    
    <footer class="bg-light text-center text-lg-start mt-auto py-3 border-top">
        <div class="container text-center">
            <span class="text-muted d-flex align-items-center justify-content-center">
                <img src="logo.png" alt="" width="24" height="24" class="d-inline-block align-text-top me-2" onerror="this.style.display='none'">
                © 2024 โรงเรียนซับใหญ่วิทยาคม | ระบบวิเคราะห์ผลสอบ O-NET
            </span>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
