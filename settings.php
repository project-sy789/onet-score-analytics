<?php
/**
 * Enhanced Settings Page - Subject-Specific Percentile Configuration
 * Allows different thresholds for each subject
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Get all subjects
$subjects = getAllSubjects($pdo);

// Get all grades
$grades_stmt = $pdo->query("SELECT DISTINCT grade_level FROM students ORDER BY grade_level");
$grades = $grades_stmt->fetchAll(PDO::FETCH_COLUMN);

// Default thresholds
$default_thresholds = [
    'p80' => 80,
    'p60' => 60,
    'p40' => 40,
    'p20' => 20
];

// Load saved settings
$settings_file = __DIR__ . '/settings.json';
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
} else {
    $settings = [
        'thresholds' => $default_thresholds,
        'subject_thresholds' => [],
        'weakness_threshold' => 50,
        'subject_weakness_thresholds' => [],
        'strength_threshold' => 80,
        'subject_strength_thresholds' => []
    ];
}

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_default'])) {
        // Save default thresholds
        $grading_mode = $_POST['default_mode'] ?? 'percentile';
        
        $new_thresholds = [
            'p80' => floatval($_POST['default_p80']),
            'p60' => floatval($_POST['default_p60']),
            'p40' => floatval($_POST['default_p40']),
            'p20' => floatval($_POST['default_p20'])
        ];
        
        // Validate logic: Must be descending
        if ($new_thresholds['p80'] > $new_thresholds['p60'] &&
            $new_thresholds['p60'] > $new_thresholds['p40'] &&
            $new_thresholds['p40'] > $new_thresholds['p20'] &&
            $new_thresholds['p20'] > 0) {
            
            $settings['thresholds'] = $new_thresholds;
            $settings['grading_mode'] = $grading_mode; // Save Global Mode
            
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $message = 'บันทึกการตั้งค่าเริ่มต้นสำเร็จ';
            $message_type = 'success';
        } else {
            $message = 'ค่าระดับคะแนนต้องเรียงจากมากไปน้อย';
            $message_type = 'danger';
        }
    }
    
    if (isset($_POST['save_subject'])) {
        // Save subject-specific thresholds
        $subject = $_POST['subject'];
        $grade_level = $_POST['grade_level'] ?? '';
        
        // Construct Key
        $key = $subject;
        if ($grade_level && $grade_level !== 'all') {
            $key .= '|' . $grade_level;
        }
        
        $grading_mode = $_POST['subject_mode'] ?? 'percentile';
        
        $new_thresholds = [
            'p80' => floatval($_POST['subject_p80']),
            'p60' => floatval($_POST['subject_p60']),
            'p40' => floatval($_POST['subject_p40']),
            'p20' => floatval($_POST['subject_p20'])
        ];
        
        if ($new_thresholds['p80'] > $new_thresholds['p60'] &&
            $new_thresholds['p60'] > $new_thresholds['p40'] &&
            $new_thresholds['p40'] > $new_thresholds['p20'] &&
            $new_thresholds['p20'] > 0) {
            
            if (!isset($settings['subject_thresholds'])) {
                $settings['subject_thresholds'] = [];
            }
            
            $new_config = $new_thresholds;
            $new_config['mode'] = $grading_mode;
            
            $settings['subject_thresholds'][$key] = $new_config;
            
            // Save additional Subject/Grade specific thresholds
            if (isset($_POST['subject_weakness_threshold']) && $_POST['subject_weakness_threshold'] !== '') {
                if (!isset($settings['subject_weakness_thresholds'])) $settings['subject_weakness_thresholds'] = [];
                $settings['subject_weakness_thresholds'][$key] = floatval($_POST['subject_weakness_threshold']);
            }
            
            if (isset($_POST['subject_strength_threshold']) && $_POST['subject_strength_threshold'] !== '') {
                 if (!isset($settings['subject_strength_thresholds'])) $settings['subject_strength_thresholds'] = [];
                 $settings['subject_strength_thresholds'][$key] = floatval($_POST['subject_strength_threshold']);
            }
            
            if (isset($_POST['subject_indicator_pass_threshold']) && $_POST['subject_indicator_pass_threshold'] !== '') {
                 if (!isset($settings['subject_indicator_pass_thresholds'])) $settings['subject_indicator_pass_thresholds'] = [];
                 $settings['subject_indicator_pass_thresholds'][$key] = floatval($_POST['subject_indicator_pass_threshold']);
            }

            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $msg_subject = $subject . ($grade_level && $grade_level !== 'all' ? " ($grade_level)" : "");
            $message = "บันทึกการตั้งค่าสำหรับวิชา \"$msg_subject\" สำเร็จ";
            $message_type = 'success';
        } else {
            $message = 'ค่าระดับคะแนนต้องเรียงจากมากไปน้อย';
            $message_type = 'danger';
        }
    }
    
    if (isset($_POST['reset_subject'])) {
        $subject = $_POST['subject'];
        $grade_level = $_POST['grade_level'] ?? '';
        
        $key = $subject;
        if ($grade_level && $grade_level !== 'all') {
            $key .= '|' . $grade_level;
        }
        
        if (isset($settings['subject_thresholds'][$key])) {
            unset($settings['subject_thresholds'][$key]);
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $msg_subject = $subject . ($grade_level && $grade_level !== 'all' ? " ($grade_level)" : "");
            $message = "รีเซ็ตการตั้งค่าสำหรับวิชา \"$msg_subject\" สำเร็จ";
            $message_type = 'success';
        }
    }
    
    if (isset($_POST['save_weakness'])) {
        // Save global weakness threshold
        $weakness_threshold = floatval($_POST['weakness_threshold']);
        
        if ($weakness_threshold > 0 && $weakness_threshold <= 100) {
            $settings['weakness_threshold'] = $weakness_threshold;
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $message = 'บันทึกค่า Weakness Threshold สำเร็จ';
            $message_type = 'success';
        } else {
            $message = 'ค่า Weakness Threshold ต้องอยู่ระหว่าง 1-100';
            $message_type = 'danger';
        }
    }
    
    if (isset($_POST['save_subject_weakness'])) {
        // Save subject-specific weakness threshold
        $subject = $_POST['subject'];
        $weakness_threshold = floatval($_POST['subject_weakness_threshold']);
        
        if ($weakness_threshold > 0 && $weakness_threshold <= 100) {
            if (!isset($settings['subject_weakness_thresholds'])) {
                $settings['subject_weakness_thresholds'] = [];
            }
            $settings['subject_weakness_thresholds'][$subject] = $weakness_threshold;
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $message = "บันทึกค่า Weakness Threshold สำหรับวิชา \"$subject\" สำเร็จ";
            $message_type = 'success';
        } else {
            $message = 'ค่า Weakness Threshold ต้องอยู่ระหว่าง 1-100';
            $message_type = 'danger';
        }
    }
    
    if (isset($_POST['save_strength'])) {
        // Save global strength threshold
        $strength_threshold = floatval($_POST['strength_threshold']);
        
        if ($strength_threshold > 0 && $strength_threshold <= 100) {
            $settings['strength_threshold'] = $strength_threshold;
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $message = 'บันทึกค่า Strength Threshold สำเร็จ';
            $message_type = 'success';
        } else {
            $message = 'ค่า Strength Threshold ต้องอยู่ระหว่าง 1-100';
            $message_type = 'danger';
        }
    }
    
    if (isset($_POST['save_indicator_pass'])) {
        // Save indicator pass threshold for Quadrant Analysis
        $indicator_pass_threshold = floatval($_POST['indicator_pass_threshold']);
        
        if ($indicator_pass_threshold > 0 && $indicator_pass_threshold <= 100) {
            $settings['indicator_pass_threshold'] = $indicator_pass_threshold;
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $message = 'บันทึกค่า Indicator Pass Threshold สำเร็จ';
            $message_type = 'success';
        } else {
            $message = 'ค่า Indicator Pass Threshold ต้องอยู่ระหว่าง 1-100';
            $message_type = 'danger';
        }
    }
    
    if (isset($_POST['save_subject_strength'])) {
        // Save subject-specific strength threshold
        $subject = $_POST['subject'];
        $strength_threshold = floatval($_POST['subject_strength_threshold']);
        
        if ($strength_threshold > 0 && $strength_threshold <= 100) {
            if (!isset($settings['subject_strength_thresholds'])) {
                $settings['subject_strength_thresholds'] = [];
            }
            $settings['subject_strength_thresholds'][$subject] = $strength_threshold;
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $message = "บันทึกค่า Strength Threshold สำหรับวิชา \"$subject\" สำเร็จ";
            $message_type = 'success';
        } else {
            $message = 'ค่า Strength Threshold ต้องอยู่ระหว่าง 1-100';
            $message_type = 'danger';
        }
    }
    
    if (isset($_POST['save_subject_indicator_pass'])) {
        // Save subject-specific indicator pass threshold
        $subject = $_POST['subject'];
        $indicator_pass_threshold = floatval($_POST['subject_indicator_pass_threshold']);
        
        if ($indicator_pass_threshold > 0 && $indicator_pass_threshold <= 100) {
            if (!isset($settings['subject_indicator_pass_thresholds'])) {
                $settings['subject_indicator_pass_thresholds'] = [];
            }
            $settings['subject_indicator_pass_thresholds'][$subject] = $indicator_pass_threshold;
            file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $message = "บันทึกค่า Indicator Pass Threshold สำหรับวิชา \"$subject\" สำเร็จ";
            $message_type = 'success';
        } else {
            $message = 'ค่า Indicator Pass Threshold ต้องอยู่ระหว่าง 1-100';
            $message_type = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าระบบ - ระบบวิเคราะห์ผลสอบ O-NET</title>
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
                        <a class="nav-link active" href="settings.php">ตั้งค่า</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4 flex-grow-1">
        <h1 class="mb-4">⚙️ ตั้งค่าระบบ - การจัดกลุ่มนักเรียน</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Default Thresholds -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">🌐 การตั้งค่าเริ่มต้น (ใช้สำหรับทุกวิชา)</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold">รูปแบบการแบ่งกลุ่ม (Grading Mode):</label>
                        <select name="default_mode" class="form-select w-auto" id="defaultModeSelect" onchange="updateDefaultLabels()">
                            <option value="percentile" <?php echo ($settings['grading_mode'] ?? 'percentile') === 'percentile' ? 'selected' : ''; ?>>อิงกลุ่ม (Percentile - เปอร์เซ็นไทล์)</option>
                            <option value="fixed" <?php echo ($settings['grading_mode'] ?? 'percentile') === 'fixed' ? 'selected' : ''; ?>>อิงเกณฑ์ (Fixed Score - คะแนนดิบ)</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <label class="form-label">
                                <span class="badge badge-purple">ดีเยี่ยม</span> <small id="dl_80" class="text-muted">(≥ P80)</small>
                            </label>
                            <input type="number" step="0.01" class="form-control" name="default_p80" 
                                   value="<?php echo $settings['thresholds']['p80']; ?>" min="1" max="100" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">
                                <span class="badge bg-success">ดี</span> <small id="dl_60" class="text-muted">(≥ P60)</small>
                            </label>
                            <input type="number" step="0.01" class="form-control" name="default_p60" 
                                   value="<?php echo $settings['thresholds']['p60']; ?>" min="1" max="100" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">
                                <span class="badge bg-info">ปานกลาง</span> <small id="dl_40" class="text-muted">(≥ P40)</small>
                            </label>
                            <input type="number" step="0.01" class="form-control" name="default_p40" 
                                   value="<?php echo $settings['thresholds']['p40']; ?>" min="1" max="100" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">
                                <span class="badge bg-warning text-dark">ต้องพัฒนา</span> <small id="dl_20" class="text-muted">(≥ P20)</small>
                            </label>
                            <input type="number" step="0.01" class="form-control" name="default_p20" 
                                   value="<?php echo $settings['thresholds']['p20']; ?>" min="1" max="100" required>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <strong>📊 การแบ่งกลุ่ม 5 ระดับ:</strong>
                        <ul class="mb-0 mt-2">
                            <li><span class="badge badge-purple">ดีเยี่ยม</span>: ≥ p80</li>
                            <li><span class="badge bg-success">ดี</span>: p60 ถึง p79</li>
                            <li><span class="badge bg-info">ปานกลาง</span>: p40 ถึง p59</li>
                            <li><span class="badge bg-warning text-dark">ต้องพัฒนา</span>: p20 ถึง p39</li>
                            <li><span class="badge bg-danger">ต้องช่วยเหลือเร่งด่วน</span>: < p20</li>
                        </ul>
                        <hr>
                        <p class="mb-0 small">
                            <strong>💡 ค่า Percentile คืออะไร?</strong><br>
                            คือค่าที่บอกตำแหน่งของคะแนนเมื่อเทียบกับคนทั้งกลุ่ม เช่น <strong>p80 (80 คะแนน)</strong> หมายความว่า "ต้องทำคะแนนให้ได้มากกว่าคน 80% ของทั้งห้อง" ถึงจะติดกลุ่มนี้<br>
                            <em>(ไม่ใช่ % ของคะแนนเต็ม แต่เป็น % ของจำนวนคนในกลุ่มที่คะแนนต่ำกว่าเรา)</em>
                        </p>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" name="save_default" class="btn btn-primary">
                            💾 บันทึกเกณฑ์เริ่มต้น
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Weakness Threshold -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">⚠️ เกณฑ์จุดอ่อน (Weakness Threshold)</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    กำหนดเกณฑ์คะแนนที่ถือว่าเป็น "จุดอ่อน" ในรายงานรายบุคคล (ค่าเริ่มต้น: 50%)
                </p>
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">
                                <span class="badge bg-warning text-dark">จุดอ่อน</span> (คะแนน < %)
                            </label>
                            <input type="number" step="0.01" class="form-control" name="weakness_threshold" 
                                   value="<?php echo $settings['weakness_threshold'] ?? 50; ?>" 
                                   min="1" max="100" required>
                            <small class="text-muted">ตัวชี้วัดที่มีคะแนนต่ำกว่านี้จะถูกแสดงในส่วน "จุดอ่อน"</small>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <button type="submit" name="save_weakness" class="btn btn-warning">
                                💾 บันทึกค่าเกณฑ์จุดอ่อน
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Strength Threshold -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">✨ เกณฑ์จุดเด่น (Strength Threshold)</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    กำหนดเกณฑ์คะแนนที่ถือว่าเป็น "จุดเด่น" ในรายงานรายบุคคล (ค่าเริ่มต้น: 80%)
                </p>
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">
                                <span class="badge bg-success">จุดเด่น</span> (คะแนน ≥ %)
                            </label>
                            <input type="number" step="0.01" class="form-control" name="strength_threshold" 
                                   value="<?php echo $settings['strength_threshold'] ?? 80; ?>" 
                                   min="1" max="100" required>
                            <small class="text-muted">ตัวชี้วัดที่มีคะแนนสูงกว่าหรือเท่ากับนี้จะถูกแสดงในส่วน "จุดเด่น"</small>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <button type="submit" name="save_strength" class="btn btn-success">
                                💾 บันทึกค่าเกณฑ์จุดเด่น
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Indicator Pass Threshold for Quadrant Analysis -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">📈 เกณฑ์ตัวชี้วัดผ่าน (Indicator Pass Threshold)</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    กำหนดเกณฑ์คะแนนที่ถือว่าตัวชี้วัด "ผ่าน" ใน Quadrant Analysis (ค่าเริ่มต้น: 50%)
                </p>
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">
                                <span class="badge bg-info">ตัวชี้วัดผ่าน</span> (คะแนน ≥ %)
                            </label>
                            <input type="number" step="0.01" class="form-control" name="indicator_pass_threshold" 
                                   value="<?php echo $settings['indicator_pass_threshold'] ?? 50; ?>" 
                                   min="1" max="100" required>
                            <small class="text-muted">ตัวชี้วัดที่มีคะแนนต่ำกว่านี้จะนับเป็น "ตัวชี้วัดไม่ผ่าน" ใน Quadrant Analysis</small>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <button type="submit" name="save_indicator_pass" class="btn btn-info">
                                💾 บันทึกค่าเกณฑ์ตัวชี้วัดผ่าน
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Subject-Specific Thresholds -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">📚 การตั้งค่าแยกตามรายวิชา</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    ตั้งค่าเกณฑ์ Percentile แยกสำหรับแต่ละวิชา (ถ้าไม่ตั้งค่า จะใช้ค่าเริ่มต้น)
                </p>
                
                <?php foreach ($subjects as $subject): 
                    // Determine which grade to show for this subject
                    // If user is editing this subject via GET (selector change)
                    $focus = ($_GET['subject_focus'] ?? '') === $subject;
                    $current_grade = $focus ? ($_GET['grade_focus'] ?? 'all') : 'all';
                    
                    // Construct key for lookup
                    $lookup_key = $subject;
                    if ($current_grade !== 'all') {
                        $lookup_key .= '|' . $current_grade;
                    }
                    
                    // Fetch existing settings or default
                    // Note: If looking up specific grade but not found, do we fall back to Subject Default?
                    // Yes, logic: Specific -> Subject Default -> Global Default
                    // But here we want to know if *this specific one* is custom.
                    
                    $is_custom = isset($settings['subject_thresholds'][$lookup_key]);
                    
                    if ($is_custom) {
                        $subject_thresholds = $settings['subject_thresholds'][$lookup_key];
                    } else {
                        // Fallback chain: Subject Default -> Global Default
                        // If current is 'all', look for Global.
                        // If current is 'M.3', look for 'Subject' (all) -> Global.
                        if ($current_grade !== 'all' && isset($settings['subject_thresholds'][$subject])) {
                            $subject_thresholds = $settings['subject_thresholds'][$subject];
                        } else {
                            $subject_thresholds = $settings['thresholds'];
                        }
                        
                        // If falling back, ensure p-values are set (might be missing in fallback config if partial? Unlikely)
                    }
                ?>
                    <div class="card mb-3 <?php echo $is_custom ? 'border-success' : ''; ?>">
                        <div class="card-header <?php echo $is_custom ? 'bg-success bg-opacity-10' : ''; ?>">
                            <strong><?php echo htmlspecialchars($subject); ?></strong>
                            <?php if ($is_custom): ?>
                                <span class="badge bg-success float-end">
                                    <?php echo $current_grade !== 'all' ? "กำหนดเอง ($current_grade)" : "กำหนดเอง (ทุกระดับชั้น)"; ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary float-end">ใช้ค่าเริ่มต้น</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="subject" value="<?php echo htmlspecialchars($subject); ?>">
                                
                                <div class="row mb-3 align-items-center">
                                    <div class="col-auto">
                                        <label class="form-label small fw-bold">ระดับชั้น (Grade):</label>
                                        <select name="grade_level" class="form-select form-select-sm" 
                                                onchange="window.location.href='settings.php?subject_focus=<?php echo urlencode($subject); ?>&grade_focus=' + this.value + '#card_<?php echo md5($subject); ?>'">
                                            <option value="all" <?php echo $current_grade === 'all' ? 'selected' : ''; ?>>ทุกระดับชั้น (All)</option>
                                            <?php foreach ($grades as $g): ?>
                                                <option value="<?php echo htmlspecialchars($g); ?>" <?php echo $current_grade === $g ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($g); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small fw-bold">รูปแบบ (Mode):</label>
                                        <select name="subject_mode" class="form-select form-select-sm w-auto d-inline-block">
                                            <option value="percentile" <?php echo ($subject_thresholds['mode'] ?? 'percentile') === 'percentile' ? 'selected' : ''; ?>>Percentile</option>
                                            <option value="fixed" <?php echo ($subject_thresholds['mode'] ?? 'percentile') === 'fixed' ? 'selected' : ''; ?>>Fixed Score</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row" id="card_<?php echo md5($subject); ?>">
                                    <div class="col-md-2">
                                        <label class="form-label small">
                                            <span class="badge badge-purple">ดีเยี่ยม</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control form-control-sm" name="subject_p80" 
                                               value="<?php echo $subject_thresholds['p80']; ?>" min="1" max="100" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">
                                            <span class="badge bg-success">ดี</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control form-control-sm" name="subject_p60" 
                                               value="<?php echo $subject_thresholds['p60']; ?>" min="1" max="100" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">
                                            <span class="badge bg-info">ปานกลาง</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control form-control-sm" name="subject_p40" 
                                               value="<?php echo $subject_thresholds['p40']; ?>" min="1" max="100" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">
                                            <span class="badge bg-warning text-dark">ต้องพัฒนา</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control form-control-sm" name="subject_p20" 
                                               value="<?php echo $subject_thresholds['p20']; ?>" min="1" max="100" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">
                                            <span class="badge bg-warning text-dark">จุดอ่อน</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control form-control-sm" name="subject_weakness_threshold" 
                                               value="<?php echo $settings['subject_weakness_thresholds'][$lookup_key] ?? $settings['subject_weakness_thresholds'][$subject] ?? $settings['weakness_threshold'] ?? 50; ?>" 
                                               min="1" max="100">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">
                                            <span class="badge bg-success">จุดเด่น</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control form-control-sm" name="subject_strength_threshold" 
                                               value="<?php echo $settings['subject_strength_thresholds'][$lookup_key] ?? $settings['subject_strength_thresholds'][$subject] ?? $settings['strength_threshold'] ?? 80; ?>" 
                                               min="1" max="100">
                                    </div>
                                    <div class="col-md-2 mt-2">
                                        <label class="form-label small">
                                            <span class="badge bg-info">ตัวชี้วัดผ่าน</span>
                                        </label>
                                        <input type="number" step="0.01" class="form-control form-control-sm" name="subject_indicator_pass_threshold" 
                                               value="<?php echo $settings['subject_indicator_pass_thresholds'][$lookup_key] ?? $settings['subject_indicator_pass_thresholds'][$subject] ?? $settings['indicator_pass_threshold'] ?? 50; ?>" 
                                               min="1" max="100">
                                    </div>
                                    <div class="col-md-12 mt-3">
                                        <button type="submit" name="save_subject" class="btn btn-success btn-sm me-2">
                                            💾 บันทึกเกณฑ์ (<?php echo $current_grade === 'all' ? 'ทุกระดับ' : $current_grade; ?>)
                                        </button>
                                        <!-- Other buttons omitted or kept global? Weakness/Strength are stored by Subject only (not grade yet). 
                                             If user wants Grade-specific weakness, loop logic needs update.
                                             User only asked for "Grading Mode" (P80/P60 etc). I will keep Weakness/Strength Subject-only for now unless asked.
                                        -->
                                        <?php if ($is_custom): ?>
                                            <button type="submit" name="reset_subject" class="btn btn-secondary btn-sm" onclick="return confirm('ยืนยันลบการตั้งค่าเฉพาะนี้?')">
                                                🔄 รีเซ็ต (ใช้ค่าเริ่มต้น)
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Help Section -->
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">💡 คำแนะนำ</h5>
            </div>
            <div class="card-body">
                <h6>📊 รูปแบบการแบ่งกลุ่ม (Grading Mode) คืออะไร?</h6>
                <ul class="small">
                    <li>
                        <strong>อิงกลุ่ม (Percentile):</strong> เปรียบเทียบนักเรียนกับเพื่อนในรุ่นเดียวกัน
                        <br>
                        <em>เช่น P80 หมายถึง "นักเรียนที่เก่งกว่าเพื่อน 80% ของทั้งหมด" (เน้นการแข่งขัน)</em>
                    </li>
                    <li>
                        <strong>อิงเกณฑ์ (Fixed Score):</strong> เปรียบเทียบกับคะแนนดิบที่กำหนดไว้
                        <br>
                        <em>เช่น กรอก 80 หมายถึง "ต้องได้คะแนน 80 คะแนนขึ้นไป" ถึงจะได้เกรดดีเยี่ยม (เน้นความสามารถส่วนบุคคล)</em>
                    </li>
                </ul>

                <h6 class="mt-3">ทำไมต้องแยกตามวิชา?</h6>
                <p class="small">
                    แต่ละวิชาอาจมีความยากง่ายต่างกัน ดังนั้นการใช้เกณฑ์เดียวกันอาจไม่เป็นธรรม
                    เช่น คณิตศาสตร์อาจยากกว่าภาษาไทย จึงควรใช้เกณฑ์ที่ผ่อนปรนกว่า
                </p>
                
                <h6>ตัวอย่างการตั้งค่า:</h6>
                <ul class="small">
                    <li><strong>ภาษาไทย (อิงกลุ่ม):</strong> 80, 60, 40, 20</li>
                    <li><strong>คณิตศาสตร์ (อิงเกณฑ์):</strong> 80 คะแนน, 70 คะแนน, 60 คะแนน, 50 คะแนน</li>
                </ul>
            </div>
        </div>
    </div>

    <footer class="bg-light text-center text-lg-start mt-auto py-3 border-top">
        <div class="container text-center">
            <span class="text-muted d-flex align-items-center justify-content-center">
                <img src="logo.png" alt="" width="24" height="24" class="d-inline-block align-text-top me-2" onerror="this.style.display='none'">
                © 2024 โรงเรียนซับใหญ่วิทยาคม | ระบบวิเคราะห์ผลสอบ O-NET
            </span>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateDefaultLabels() {
            const mode = document.getElementById('defaultModeSelect').value;
            const isFixed = mode === 'fixed';
            
            document.getElementById('dl_80').innerText = isFixed ? '(≥ คะแนนที่ระบุ)' : '(≥ P80)';
            document.getElementById('dl_60').innerText = isFixed ? '(≥ คะแนนที่ระบุ)' : '(≥ P60)';
            document.getElementById('dl_40').innerText = isFixed ? '(≥ คะแนนที่ระบุ)' : '(≥ P40)';
            document.getElementById('dl_20').innerText = isFixed ? '(≥ คะแนนที่ระบุ)' : '(≥ P20)';
        }
        
        // Init on load
        document.addEventListener('DOMContentLoaded', function() {
            updateDefaultLabels();
        });
    </script>
</body>
</html>
