<?php
ob_start(); // Start output buffering to prevent "headers already sent" errors
/**
 * Exam Management Page
 * Allows viewing, editing, and deleting exam questions and sets.
 * Also allows managing student scores.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$selected_exam_set_raw = $_GET['exam_set'] ?? '';
$parts = explode('|', $selected_exam_set_raw);
$selected_exam_set = $parts[0] ?? '';
$selected_grade = $parts[1] ?? '';
$selected_subject_param = $parts[2] ?? '';

$action = $_POST['action'] ?? '';
$active_tab = $_POST['active_tab'] ?? $_GET['tab'] ?? 'questions'; // Default tab
$message = '';
$message_type = '';

// Handle Actions
$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? ''; // Accept from GET or POST

if ($request_method === 'POST' || ($request_method === 'GET' && $action === 'delete_exam_set')) {
    if ($action === 'delete_question' && !empty($_POST['question_id'])) {
        $del_id = $_POST['question_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Delete mappings
            $stmt = $pdo->prepare("DELETE FROM question_indicators WHERE question_id = ?");
            $stmt->execute([$del_id]);
            
            // Find Q details
            $q_stmt = $pdo->prepare("SELECT question_number, exam_set FROM questions WHERE id = ?");
            $q_stmt->execute([$del_id]);
            $q_data = $q_stmt->fetch();
            
            if ($q_data) {
                $del_scores = $pdo->prepare("DELETE FROM scores WHERE question_number = ? AND exam_set = ?");
                $del_scores->execute([$q_data['question_number'], $q_data['exam_set']]);
            }
            
            // Delete question
            $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
            $stmt->execute([$del_id]);
            
            $pdo->commit();
            $message = "ลบข้อสอบ ID: $del_id และคะแนนที่เกี่ยวข้องเรียบร้อยแล้ว";
            $message_type = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $message_type = "danger";
        }
    }
    
    elseif ($action === 'delete_exam_set' && !empty($_REQUEST['exam_set_to_delete'])) {
        $set_to_del = $_REQUEST['exam_set_to_delete'];
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("DELETE FROM scores WHERE TRIM(exam_set) = TRIM(?)");
            $stmt->execute([$set_to_del]);
            
            $stmt = $pdo->prepare("DELETE FROM question_indicators WHERE question_id IN (SELECT id FROM questions WHERE TRIM(exam_set) = TRIM(?))");
            $stmt->execute([$set_to_del]);
            
            $stmt = $pdo->prepare("DELETE FROM questions WHERE TRIM(exam_set) = TRIM(?)");
            $stmt->execute([$set_to_del]);
            
            // Indicators are shared across exams (no exam_set column based on import.php), so we do NOT delete them by exam_set.
            // Orphan indicators can remain.
            // $stmt = $pdo->prepare("DELETE FROM indicators WHERE exam_set = ?");
            // $stmt->execute([$set_to_del]);
            
            $pdo->commit();
            $pdo->commit();
            // Redirect to prevent re-submission/refresh issues with GET action
            header("Location: manage_exams.php?msg=deleted");
            exit; 
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $message_type = "danger";
        }
    }
    
    elseif ($action === 'update_question') {
        $q_id = $_POST['question_id'];
        $max_score = $_POST['max_score'];
        $subject = $_POST['subject'];
        $q_num = $_POST['question_number'];
        
        try {
            $stmt = $pdo->prepare("UPDATE questions SET max_score = ?, subject = ?, question_number = ? WHERE id = ?");
            $stmt->execute([$max_score, $subject, $q_num, $q_id]);
            
            $message = "บันทึกการแก้ไขข้อสอบเรียบร้อยแล้ว";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    elseif ($action === 'delete_student_scores') {
        $st_id = $_POST['student_id'];
        $active_tab = 'scores'; // Switch back to scores tab
        
        try {
            $stmt = $pdo->prepare("DELETE FROM scores WHERE student_id = ? AND exam_set = ?");
            $stmt->execute([$st_id, $selected_exam_set]);
            
            $message = "ลบผลการสอบของนักเรียน ID: $st_id เรียบร้อยแล้ว";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $message_type = "danger";
        }
    }

    elseif ($action === 'update_student_scores') {
        $st_id = $_POST['student_id'];
        $scores = $_POST['scores'] ?? []; // Array [question_number => score]
        $active_tab = 'scores';
        
        try {
            $pdo->beginTransaction();
            
            // Loop through submitted scores
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO scores (student_id, question_number, score_obtained, exam_set) VALUES (?, ?, ?, ?)");
            // Note: For MySQL use: INSERT ... ON DUPLICATE KEY UPDATE. But application uses SQLite default?
            // Checking db.php or index.php context... import.php supports both.
            // Let's assume SQLite for simplicity OR use generic "Delete then Insert" to be safe and compatible?
            // "Insert or Replace" is SQLite specific. MySQL is "ON DUPLICATE".
            // Let's detect driver or use Delete-then-Insert logic which is safer cross-db for this small scale.
            
            // Just use Delete-then-Insert for this specific student/exam_set/question combo
            // Actually, we iterate questions.
            foreach ($scores as $q_num => $score_val) {
                // Remove old score
                $del = $pdo->prepare("DELETE FROM scores WHERE student_id = ? AND question_number = ? AND exam_set = ?");
                $del->execute([$st_id, $q_num, $selected_exam_set]);
                
                // Insert new score if not empty/null
                if ($score_val !== '' && is_numeric($score_val)) {
                     // Check max score first?
                     // Verify against max score
                     $max_q = $pdo->prepare("SELECT max_score FROM questions WHERE question_number = ? AND exam_set = ? AND grade_level = ?");
                     $max_q->execute([$q_num, $selected_exam_set, $selected_grade]);
                     $max_val = $max_q->fetchColumn();
                     
                     if ($score_val > $max_val) {
                         throw new Exception("คะแนนข้อ $q_num ($score_val) เกินคะแนนเต็ม ($max_val)");
                     }
                     
                     $ins = $pdo->prepare("INSERT INTO scores (student_id, question_number, score_obtained, exam_set) VALUES (?, ?, ?, ?)");
                     $ins->execute([$st_id, $q_num, $score_val, $selected_exam_set]);
                }
            }
            
            $pdo->commit();
            $message = "บันทึกคะแนนนักเรียน ID: $st_id เรียบร้อยแล้ว";
            $message_type = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Get all exam sets with Grade info
$sets_stmt = $pdo->query("SELECT DISTINCT exam_set, grade_level, subject FROM questions ORDER BY exam_set, grade_level, subject");
$exam_sets_options = $sets_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อสอบ - ระบบวิเคราะห์ผลสอบ O-NET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body class="d-flex flex-column">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
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
                        <a class="nav-link active" href="manage_exams.php">จัดการข้อสอบ</a>
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

    <div class="container flex-grow-1">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>🛠️ จัดการข้อมูลข้อสอบ (Exam Management)</h2>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">เลือกชุดข้อสอบที่ต้องการจัดการ:</label>
                        <select name="exam_set" class="form-select" onchange="this.form.submit()">
                            <option value="">-- กรุณาเลือกชุดข้อสอบ --</option>
                            <?php foreach ($exam_sets_options as $opt): 
                                $val = $opt['exam_set'] . '|' . $opt['grade_level'] . '|' . $opt['subject'];
                                $label = $opt['exam_set'];
                                if ($opt['grade_level']) $label .= " (" . $opt['grade_level'] . ")";
                                if ($opt['subject']) $label .= " - " . $opt['subject'];
                            ?>
                                <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $selected_exam_set_raw === $val ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($selected_exam_set): ?>
                    <div class="col-md-6 text-end">
                         <form method="POST" onsubmit="return confirm('⚠️ คำเตือน: คุณต้องการลบชุดข้อสอบ \'' + '<?php echo htmlspecialchars($selected_exam_set); ?>' + '\' และคะแนนทั้งหมดทิ้งใช่หรือไม่? การกระทำนี้ไม่สามารถเรียกคืนได้!');" class="d-inline">
                            <input type="hidden" name="action" value="delete_exam_set">
                            <input type="hidden" name="exam_set_to_delete" value="<?php echo htmlspecialchars($selected_exam_set); ?>">
                            <button type="button" class="btn btn-outline-danger" onclick="if(confirm('ยืนยันที่จะลบชุดข้อสอบ \'<?php echo htmlspecialchars($selected_exam_set); ?>\' ทั้งหมด? (รวมถึงคะแนนนักเรียนทั้งหมดในชุดนี้)')) this.form.submit();">
                                <i class="bi bi-trash"></i> ลบชุดข้อสอบนี้ทั้งหมด
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($selected_exam_set): ?>
            <?php
            // Get Questions
            $q_stmt = $pdo->prepare("
                SELECT q.*, GROUP_CONCAT(i.code SEPARATOR ', ') as mapped_indicators 
                FROM questions q 
                LEFT JOIN question_indicators qi ON q.id = qi.question_id
                LEFT JOIN indicators i ON qi.indicator_id = i.id
                WHERE q.exam_set = ? 
                GROUP BY q.id
                ORDER BY CAST(q.question_number AS UNSIGNED), q.question_number
            ");
            $q_stmt->execute([$selected_exam_set]);
            $questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get Students with scores for this exam set
            // Join students to get names
            $st_stmt = $pdo->prepare("
                SELECT s.student_id, st.name, SUM(s.score_obtained) as total_score, COUNT(s.id) as questions_answered 
                FROM scores s
                LEFT JOIN students st ON s.student_id = st.student_id
                WHERE s.exam_set = ?
                GROUP BY s.student_id
                ORDER BY s.student_id
            ");
            $st_stmt->execute([$selected_exam_set]);
            $students_scores = $st_stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" id="examTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === 'questions' ? 'active' : ''; ?>" id="questions-tab" data-bs-toggle="tab" data-bs-target="#questions" type="button" role="tab" onclick="setTab('questions')">📝 รายการข้อสอบ (Questions)</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $active_tab === 'scores' ? 'active' : ''; ?>" id="scores-tab" data-bs-toggle="tab" data-bs-target="#scores" type="button" role="tab" onclick="setTab('scores')">📊 คะแนนนักเรียน (Student Scores)</button>
                </li>
            </ul>

            <div class="tab-content" id="examTabsContent">
                <!-- Questions Tab -->
                <div class="tab-pane fade <?php echo $active_tab === 'questions' ? 'show active' : ''; ?>" id="questions" role="tabpanel">
                    <div class="card">
                         <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                            <span>รายการข้อสอบ: <strong><?php echo htmlspecialchars($selected_exam_set); ?></strong> (<?php echo count($questions); ?> ข้อ)</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>ข้อที่</th>
                                        <th>วิชา</th>
                                        <th>คะแนนเต็ม</th>
                                        <th>ตัวชี้วัด</th>
                                        <th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($questions as $q): ?>
                                    <tr>
                                        <td><span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($q['question_number']); ?></span></td>
                                        <td><?php echo htmlspecialchars($q['subject']); ?></td>
                                        <td class="fw-bold text-primary"><?php echo number_format($q['max_score'], 2); ?></td>
                                        <td>
                                            <?php if ($q['mapped_indicators']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($q['mapped_indicators']); ?></small>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">ไม่มีตัวชี้วัด</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-warning me-1" 
                                                    onclick="editQuestion(<?php echo htmlspecialchars(json_encode($q)); ?>)">
                                                <i class="bi bi-pencil"></i> แก้ไข
                                            </button>
                                            
                                            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันลบข้อที่ <?php echo htmlspecialchars($q['question_number']); ?>?');">
                                                <input type="hidden" name="action" value="delete_question">
                                                <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                                <input type="hidden" name="exam_set" value="<?php echo htmlspecialchars($selected_exam_set); ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i> ลบ
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Scores Tab -->
                <div class="tab-pane fade <?php echo $active_tab === 'scores' ? 'show active' : ''; ?>" id="scores" role="tabpanel">
                     <div class="card">
                         <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                            <span>คะแนนนักเรียน: <strong><?php echo htmlspecialchars($selected_exam_set); ?></strong> (<?php echo count($students_scores); ?> คน)</span>
                        </div>
                         <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>รหัสนักเรียน</th>
                                        <th>ชื่อ-สกุล</th>
                                        <th>ทำไป (ข้อ)</th>
                                        <th>คะแนนรวม</th>
                                        <th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students_scores as $st): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($st['student_id']); ?></td>
                                        <td><?php echo htmlspecialchars($st['name'] ?? 'N/A'); ?></td>
                                        <td><?php echo $st['questions_answered']; ?></td>
                                        <td class="fw-bold text-success h5 mb-0"><?php echo number_format($st['total_score'], 2); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary me-1" 
                                                    onclick="editStudentScore('<?php echo $st['student_id']; ?>', '<?php echo htmlspecialchars($st['name']); ?>')">
                                                <i class="bi bi-pencil-square"></i> แก้ไขคะแนน
                                            </button>
                                            
                                            <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันลบคะแนนทั้งหมดของนักเรียน <?php echo htmlspecialchars($st['name']); ?>?');">
                                                <input type="hidden" name="action" value="delete_student_scores">
                                                <input type="hidden" name="student_id" value="<?php echo $st['student_id']; ?>">
                                                <input type="hidden" name="exam_set" value="<?php echo htmlspecialchars($selected_exam_set); ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i> ลบ
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
        <?php endif; ?>
    </div>

    <!-- Edit Question Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">แก้ไขข้อสอบ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_question">
                        <input type="hidden" name="question_id" id="edit_id">
                        <input type="hidden" name="exam_set" value="<?php echo htmlspecialchars($selected_exam_set); ?>">
                        <input type="hidden" name="active_tab" value="questions">
                        
                        <div class="mb-3">
                            <label class="form-label">ข้อที่</label>
                            <input type="number" step="any" name="question_number" id="edit_number" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">คะแนนเต็ม (Max Score)</label>
                            <input type="number" step="0.01" name="max_score" id="edit_max" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">วิชา</label>
                            <input type="text" name="subject" id="edit_subject" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Score Modal (Loaded dynamically or pre-rendered? Pre-rendered is hard for dynamic scores) -->
    <!-- We will use JS to fetch scores via AJAX? Or just simple redirect? -->
    <!-- Easier: JS fetches scores via a helper endpoint or just pass scores data if small? -->
    <!-- OR: Since we are in PHP, we can't easily iterate all students' all scores to modals. -->
    <!-- Better approach: Button opens a new page OR we use a simple fetch to get current scores for a student. -->
    <!-- To keep it single file: Create a hidden separate "get_scores" mode in this file via GET/POST param to return JSON -->
   
    <div class="modal fade" id="scoreModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
             <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">แก้ไขคะแนน: <span id="score_student_name"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_student_scores">
                        <input type="hidden" name="student_id" id="score_student_id">
                        <input type="hidden" name="exam_set" value="<?php echo htmlspecialchars($selected_exam_set); ?>">
                        <input type="hidden" name="active_tab" value="scores">
                        
                        <div id="score_inputs_container" class="row">
                            <!-- Inputs injected by JS -->
                            <div class="text-center"><div class="spinner-border text-primary"></div> กำลังโหลด...</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึกคะแนน</button>
                    </div>
                </form>
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
        function setTab(tabName) {
            // Update URL query param to persist tab on refresh (optional but good)
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }

        function editQuestion(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_number').value = data.question_number;
            document.getElementById('edit_max').value = data.max_score;
            document.getElementById('edit_subject').value = data.subject;
            
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
        
        function editStudentScore(studentId, studentName) {
            document.getElementById('score_student_id').value = studentId;
            document.getElementById('score_student_name').innerText = studentName;
            
            const container = document.getElementById('score_inputs_container');
            container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p>กำลังโหลดคะแนน...</p></div>';
            
            new bootstrap.Modal(document.getElementById('scoreModal')).show();
            
            // Fetch scores for this student
            // We need a way to get scores. let's call this same file with a special parameter
            fetch('get_student_scores.php?exam_set=<?php echo urlencode($selected_exam_set); ?>&student_id=' + studentId)
                .then(response => response.json())
                .then(data => {
                    let html = '';
                    if (data.error) {
                         html = '<div class="alert alert-danger">' + data.error + '</div>';
                    } else {
                        // Group by Subject for better layout
                        // Assuming data is array of {question_number, score, max_score, subject}
                        
                        // Sort by Q number
                        
                        html += '<div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> กรอกคะแนนดิบที่ได้ (ห้ามเกินคะแนนเต็มที่ระบุ)</div>';
                        
                        data.forEach(q => {
                            html += `
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small mb-1">
                                        ข้อ ${q.question_number} <span class="text-muted">(${q.subject})</span>
                                        <span class="badge bg-light text-dark border">เต็ม ${q.max_score}</span>
                                    </label>
                                    <input type="number" step="any" 
                                           name="scores[${q.question_number}]" 
                                           value="${q.score !== null ? q.score : ''}" 
                                           class="form-control form-control-sm"
                                           min="0" max="${q.max_score}">
                                </div>
                            `;
                        });
                    }
                    container.innerHTML = html;
                })
                .catch(err => {
                    container.innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการโหลดคะแนน</div>';
                    console.error(err);
                });
        }
    </script>
</body>
</html>
