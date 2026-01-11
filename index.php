<?php
/**
 * Main Dashboard
 * Displays item analysis and student performance reports
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Get filter values
$selected_grade = $_GET['grade'] ?? '';
$selected_room = $_GET['room'] ?? '';
$selected_subject = $_GET['subject'] ?? '';  // NEW: Subject filter
$selected_exam_set = $_GET['exam_set'] ?? '';  // NEW: Exam set filter
$view = $_GET['view'] ?? 'overview';
$selected_student = $_GET['student'] ?? '';

// Get available grades
$grades_stmt = $pdo->query("SELECT DISTINCT grade_level FROM students ORDER BY grade_level");
$grades = $grades_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get available subjects (filter by grade if selected)
if (!empty($selected_grade)) {
    $subjects_stmt = $pdo->prepare("SELECT DISTINCT subject FROM questions q INNER JOIN scores s ON q.question_number = s.question_number AND q.exam_set = s.exam_set INNER JOIN students st ON s.student_id = st.student_id WHERE st.grade_level = ? ORDER BY subject");
    // Fix duplicate execute
    $subjects_stmt->execute([$selected_grade]);
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $subjects = []; // Changed: Empty by default if no grade selected
}

// Get available exam sets (filter by grade and subject if selected)
$exam_sets = [];
if (!empty($selected_grade) && !empty($selected_subject)) { // Only load if both grade AND subject selected
    try {
        $exam_sets_stmt = $pdo->prepare("
            SELECT DISTINCT q.exam_set 
            FROM questions q 
            INNER JOIN scores s ON q.question_number = s.question_number AND q.exam_set = s.exam_set 
            INNER JOIN students st ON s.student_id = st.student_id 
            WHERE st.grade_level = ? 
            AND q.subject = ? 
            AND q.exam_set IS NOT NULL 
            AND q.exam_set != '' 
            AND q.exam_set != 'default' 
            ORDER BY q.exam_set DESC
        ");
        $exam_sets_stmt->execute([$selected_grade, $selected_subject]);
        $exam_sets = $exam_sets_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $exam_sets = [];
    }
}

// Get available rooms for selected grade
$rooms = [];
if ($selected_grade) {
    $rooms_stmt = $pdo->prepare("SELECT DISTINCT room_number FROM students WHERE grade_level = ? ORDER BY room_number");
    $rooms_stmt->execute([$selected_grade]);
    $rooms = $rooms_stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get students for selected filters
$students = [];
if ($selected_grade) {
    $where = ["grade_level = ?"];
    $params = [$selected_grade];
    
    if ($selected_room) {
        $where[] = "room_number = ?";
        $params[] = $selected_room;
    }
    
    $students_stmt = $pdo->prepare("SELECT student_id, name FROM students WHERE " . implode(" AND ", $where) . " ORDER BY student_id");
    $students_stmt->execute($params);
    $students = $students_stmt->fetchAll();
}

// Prepare Cascade Data for JavaScript (Grade -> Subject -> Exam Set)
$cascade_mapping = [];
try {
    $map_stmt = $pdo->query("
        SELECT DISTINCT s.grade_level, q.subject, q.exam_set 
        FROM questions q
        JOIN scores sc ON q.question_number = sc.question_number AND q.exam_set = sc.exam_set
        JOIN students s ON sc.student_id = s.student_id
        WHERE q.exam_set IS NOT NULL AND q.exam_set != '' AND q.exam_set != 'default'
        ORDER BY s.grade_level, q.subject, q.exam_set DESC
    ");
    while ($row = $map_stmt->fetch(PDO::FETCH_ASSOC)) {
        $g = $row['grade_level'];
        $s = $row['subject'];
        $e = $row['exam_set'];
        
        if (!isset($cascade_mapping[$g])) $cascade_mapping[$g] = [];
        if (!isset($cascade_mapping[$g][$s])) $cascade_mapping[$g][$s] = [];
        if (!in_array($e, $cascade_mapping[$g][$s])) {
            $cascade_mapping[$g][$s][] = $e;
        }
    }
} catch (Exception $e) {
    // Fail silently
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบวิเคราะห์ผลสอบ O-NET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="d-flex flex-column">
    <!-- Print Only Header -->
    <!-- Print Only Header -->
    <div class="d-none d-print-block text-center mb-4 mt-3">
        <div class="d-flex align-items-center justify-content-center mb-2">
            <img src="logo.png" alt="" width="50" height="50" class="me-3" onerror="this.style.display='none'">
            <div class="text-start">
                <h4 class="mb-0 fw-bold">โรงเรียนซับใหญ่วิทยาคม</h4>
                <p class="mb-0 text-muted">ระบบวิเคราะห์ผลสอบ O-NET</p>
            </div>
        </div>
        <hr>
        <table style="width: 100%; border: none; margin-bottom: 1rem;">
            <tr>
                <td style="text-align: left; vertical-align: top; width: 50%;">
                    <p class="mb-1"><strong>ระดับชั้น:</strong> <?php echo htmlspecialchars($selected_grade ?: 'ทั้งหมด'); ?></p>
                    <p class="mb-1"><strong>วิชา:</strong> <?php echo htmlspecialchars($selected_subject ?: 'ทั้งหมด'); ?></p>
                </td>
                <td style="text-align: right; vertical-align: top; width: 50%;">
                    <p class="mb-1"><strong>ชุดข้อสอบ:</strong> <?php echo htmlspecialchars($selected_exam_set ?: 'ทั้งหมด'); ?></p>
                    <p class="mb-1"><strong>วันที่พิมพ์:</strong> <?php echo date('d/m/Y H:i'); ?></p>
                </td>
            </tr>
        </table>
        <hr>
    </div>
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
                        <a class="nav-link active" href="index.php">หน้าหลัก</a>
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

    <div class="container-fluid mt-4 flex-grow-1">
        <!-- Filter Panel -->
        <div class="card mb-4" id="filterCard">
            <div class="card-body">
                <form method="GET" action="" id="filterForm">
                    <div class="row align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">ระดับชั้น</label>
                            <select name="grade" id="gradeSelect" class="form-select" required onchange="document.getElementById('filterForm').submit();">
                                <option value="">-- เลือกระดับชั้น --</option>
                                <?php foreach ($grades as $grade): ?>
                                    <option value="<?php echo htmlspecialchars($grade); ?>" 
                                            <?php echo $selected_grade === $grade ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($grade); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">ห้องเรียน</label>
                            <select name="room" id="roomSelect" class="form-select">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo htmlspecialchars($room); ?>"
                                            <?php echo $selected_room === $room ? 'selected' : ''; ?>>
                                        ห้อง <?php echo htmlspecialchars($room); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">รายวิชา</label>
                            <select name="subject" class="form-select">
                                <option value="">-- เลือกวิชา --</option>
                                <?php foreach ($subjects as $subj): ?>
                                    <option value="<?php echo htmlspecialchars($subj); ?>"
                                            <?php echo $selected_subject === $subj ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subj); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">ชุดข้อสอบ</label>
                            <select name="exam_set" class="form-select">
                                <option value="">-- เลือกชุด --</option>
                                <?php foreach ($exam_sets as $exam_set): ?>
                                    <option value="<?php echo htmlspecialchars($exam_set); ?>"
                                            <?php echo $selected_exam_set === $exam_set ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($exam_set); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- View selector removed as per user request -->
                        
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">วิเคราะห์ผลสอบ</button>
                        </div>
                    </div>
                    
                    <!-- Preserve View and Student ID when changing filters in Individual Mode -->
                    <?php if ($view === 'individual' && $selected_student): ?>
                        <input type="hidden" name="view" value="individual">
                        <input type="hidden" name="student" value="<?php echo htmlspecialchars($selected_student); ?>">
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="alert alert-info py-2 mb-0 d-flex align-items-center justify-content-between no-print">
                                    <span>
                                        <i class="bi bi-person-circle me-2"></i>
                                        กำลังดูข้อมูลของ: <strong><?php 
                                            // Find student name
                                            $curr_name = $selected_student;
                                            foreach($students as $st) {
                                                if ($st['student_id'] == $selected_student) {
                                                    $curr_name = $st['name'];
                                                    break;
                                                }
                                            }
                                            echo htmlspecialchars($curr_name);
                                        ?></strong>
                                    </span>
                                    <a href="index.php?grade=<?php echo urlencode($selected_grade); ?>&room=<?php echo urlencode($selected_room); ?>&subject=<?php echo urlencode($selected_subject); ?>&exam_set=<?php echo urlencode($selected_exam_set); ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-arrow-left"></i> กลับไปภาพรวม
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cascadeData = <?php echo json_encode($cascade_mapping); ?>;
            const gradeSelect = document.getElementById('gradeSelect');
            const subjectSelect = document.querySelector('select[name="subject"]');
            const examSetSelect = document.querySelector('select[name="exam_set"]');
            
            // Current selections from PHP (to preserve state on reload)
            const currentSubject = "<?php echo $selected_subject; ?>";
            const currentExamSet = "<?php echo $selected_exam_set; ?>";
            
            function updateSubjects() {
                const grade = gradeSelect.value;
                const previousSubject = subjectSelect.value || currentSubject;
                
                // Keep "Select Subject" option
                subjectSelect.innerHTML = '<option value="">-- เลือกวิชา --</option>';
                
                // If grade selected and data exists
                if (grade && cascadeData[grade]) {
                    const subjects = Object.keys(cascadeData[grade]);
                    subjects.sort();
                    
                    subjects.forEach(subj => {
                        const option = document.createElement('option');
                        option.value = subj;
                        option.textContent = subj;
                        if (subj === previousSubject) option.selected = true;
                        subjectSelect.appendChild(option);
                    });
                }
                updateExamSets(); // Trigger exam set update
            }
            
            function updateExamSets() {
                const grade = gradeSelect.value;
                const subject = subjectSelect.value;
                const previousExamSet = examSetSelect.value || currentExamSet;
                
                examSetSelect.innerHTML = '<option value="">-- เลือกชุด --</option>';
                
                if (grade && subject && cascadeData[grade] && cascadeData[grade][subject]) {
                    const examSets = cascadeData[grade][subject];
                    examSets.forEach(es => {
                        const option = document.createElement('option');
                        option.value = es;
                        option.textContent = es;
                        if (es === previousExamSet) option.selected = true;
                        examSetSelect.appendChild(option);
                    });
                }
            }
            
            // Listeners
            // gradeSelect.addEventListener('change', updateSubjects); // Disabled: We reload page on Grade change to get Rooms
            subjectSelect.addEventListener('change', updateExamSets);
            
            // Note: We don't run updateSubjects() on load because PHP already populated the correct options.
            // But we DO want client-side updates moving forward.
            // Actually, if we want full client-side control, we could run it, but PHP logic is fine for first load.
        });
        </script>

        <?php if ($selected_grade): ?>
            
            <?php if ($view === 'overview'): ?>
                <!-- OVERVIEW VIEW: Teacher Dashboard -->
                <div class="row">
                    <!-- Item Quality Table -->
                    <div class="col-12 mb-4">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">📊 คุณภาพข้อสอบ (Item Analysis)</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                // Get questions - filter by subject, grade level, and exam_set if selected
                                $questions_query = "SELECT DISTINCT q.question_number FROM questions q";
                                $questions_params = [];
                                $questions_where = [];
                                
                                if ($selected_subject) {
                                    $questions_where[] = "q.subject = ?";
                                    $questions_params[] = $selected_subject;
                                }
                                
                                // Filter by exam_set if selected
                                if ($selected_exam_set) {
                                    $questions_where[] = "q.exam_set = ?";
                                    $questions_params[] = $selected_exam_set;
                                }
                                
                                // Filter by grade level through indicators
                                if ($selected_grade) {
                                    $questions_query .= "
                                        LEFT JOIN question_indicators qi ON q.id = qi.question_id
                                        LEFT JOIN indicators i ON qi.indicator_id = i.id
                                    ";
                                    $questions_where[] = "(i.grade_level = ? OR i.grade_level IS NULL)";
                                    $questions_params[] = $selected_grade;
                                }
                                
                                if (!empty($questions_where)) {
                                    $questions_query .= " WHERE " . implode(" AND ", $questions_where);
                                }
                                
                                $questions_query .= " ORDER BY q.question_number";
                                
                                $questions_stmt = $pdo->prepare($questions_query);
                                $questions_stmt->execute($questions_params);
                                $questions = $questions_stmt->fetchAll(PDO::FETCH_COLUMN);
                                
                                if (empty($questions)):
                                ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="bi bi-info-circle"></i>
                                        <strong>ยังไม่มีข้อมูลข้อสอบ</strong><br>
                                        กรุณานำเข้าข้อมูลตัวชี้วัดและข้อสอบผ่านเมนู <a href="import.php" class="alert-link">นำเข้าข้อมูล</a>
                                    </div>
                                <?php elseif (empty($selected_exam_set)): ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong>กรุณาเลือกชุดข้อสอบ</strong><br>
                                        ต้องเลือกชุดข้อสอบ (Exam Set) เพื่อดูค่าอำนาจจำแนกและความยากรายข้อ
                                    </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>ข้อที่</th>
                                                <th>ตัวชี้วัด</th>
                                                <th>คะแนนเต็ม</th>

                                                <th class="text-center">Mean</th>
                                                <th class="text-center">S.D.</th>
                                                <th class="text-center">C.V.(%)</th>
                                                <th>ค่าความยาก (p)</th>
                                                <th>ค่าอำนาจจำแนก (r)</th>
                                                <th>การตีความ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            // Initialize accumulators for Average P and R
                                            $sum_p = 0;
                                            $sum_r = 0;
                                            $item_count = 0;
                                            
                                            foreach ($questions as $q_num):
                                                // Only calculate if question exists in this exam set
                                                // Check if score data exists for this question/exam_set combination to avoid division by zero or empty errors logic inside functions
                                                
                                                $p = calculateDifficultyIndex($pdo, $q_num, $selected_exam_set, $selected_grade);
                                                $r = calculateDiscriminationIndex($pdo, $q_num, $selected_exam_set, $selected_grade);
                                                $stats = calculateQuestionStats($pdo, $q_num, $selected_exam_set, $selected_grade); // New Stats
                                                
                                                if ($p > 0) { // Count items that have data (p=0 usually means no data or all wrong, but mostly no data if r also 0)
                                                    $sum_p += $p;
                                                    $sum_r += $r;
                                                    $item_count++;
                                                } else {
                                                     // If p=0, maybe we should still count it? 
                                                     // If max_score > 0, we should count it. 
                                                     // Let's rely on $questions count.
                                                     $sum_p += $p;
                                                     $sum_r += $r;
                                                     $item_count++;
                                                }
                                                
                                                // Get indicator codes (comma-separated) and max_score
                                                $ind_sql = "
                                                    SELECT 
                                                        GROUP_CONCAT(i.code SEPARATOR ', ') as indicator_codes,
                                                        q.max_score
                                                    FROM questions q
                                                    LEFT JOIN question_indicators qi ON q.id = qi.question_id
                                                    LEFT JOIN indicators i ON qi.indicator_id = i.id
                                                    WHERE q.question_number = ? AND q.exam_set = ?
                                                ";
                                                $ind_params = [$q_num, $selected_exam_set];
                                                
                                                if ($selected_grade) {
                                                    $ind_sql .= " AND q.grade_level = ?";
                                                    $ind_params[] = $selected_grade;
                                                }
                                                
                                                $ind_sql .= " GROUP BY q.id";
                                                
                                                $ind_stmt = $pdo->prepare($ind_sql);
                                                $ind_stmt->execute($ind_params);
                                                $ind_data = $ind_stmt->fetch();
                                                $indicator_codes = $ind_data['indicator_codes'] ?? '-';
                                                
                                                // Format codes for beautiful display
                                                if ($indicator_codes !== '-') {
                                                    $codes_array = explode(', ', $indicator_codes);
                                                    $formatted_codes = array_map('normalizeIndicatorCode', $codes_array);
                                                    $indicator_codes = implode(', ', $formatted_codes);
                                                }
                                                
                                                $max_score = $ind_data['max_score'] ?? 0;
                                                
                                                $p_class = '';
                                                if ($p < 0.2 || $p > 0.8) $p_class = 'table-warning';
                                                if ($r < 0) $p_class = 'table-danger';
                                            ?>
                                                <tr class="<?php echo $p_class; ?>">
                                                    <td><?php echo $q_num; ?></td>
                                                    <td><?php echo htmlspecialchars($indicator_codes); ?></td>
                                                    <td><?php echo (float)$max_score == (int)$max_score ? (int)$max_score : number_format($max_score, 2); ?></td>
                                                    
                                                    <!-- New Stats Columns -->

                                                    <td class="text-center"><?php echo number_format($stats['mean'], 2); ?></td>
                                                    <td class="text-center"><?php echo number_format($stats['sd'], 2); ?></td>
                                                    <td class="text-center"><?php echo number_format($stats['cv'], 2); ?>%</td>
                                                    
                                                    <td><?php echo number_format($p, 2); ?></td>
                                                    <td><?php echo number_format($r, 2); ?></td>
                                                    <td>
                                                        <?php echo interpretDifficulty($p); ?> / 
                                                        <?php echo interpretDiscrimination($r); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <?php 
                                                // Calculate Context Stats
                                                $exam_stats = calculateExamOverviewStats($pdo, $selected_exam_set, $selected_grade, $selected_room, $selected_subject);
                                                $avg_p = $item_count > 0 ? $sum_p / $item_count : 0;
                                                $avg_r = $item_count > 0 ? $sum_r / $item_count : 0;
                                            ?>
                                            <tr class="table-dark fw-bold">
                                                <td colspan="3" class="text-end">คะแนนรวมทั้งฉบับ (Total Score):</td>

                                                <td class="text-center">
                                                    <?php 
                                                    echo number_format($exam_stats['mean'], 2); 
                                                    if (abs($exam_stats['mean'] - $exam_stats['mean_percent']) > 0.05) {
                                                        echo '<br><small class="text-muted">(' . number_format($exam_stats['mean_percent'], 2) . '%)</small>';
                                                    }
                                                    ?>
                                                </td>
                                                <td class="text-center"><?php echo number_format($exam_stats['sd'], 2); ?></td>
                                                <td class="text-center"><?php echo number_format($exam_stats['cv'], 2); ?>%</td>
                                                <td><?php echo number_format($avg_p, 2); ?></td>
                                                <td><?php echo number_format($avg_r, 2); ?></td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quadrant Analysis -->
                    <div class="col-12 mb-4 page-break">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">📈 การวิเคราะห์แบบ Quadrant (คะแนน vs ตัวชี้วัดที่ไม่ผ่าน)</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                if (empty($selected_exam_set)):
                                ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong>กรุณาเลือกชุดข้อสอบ</strong><br>
                                        ต้องเลือกชุดข้อสอบ (Exam Set) เพื่อดูการวิเคราะห์ Quadrant (คะแนน vs ตัวชี้วัดที่ไม่ผ่าน)
                                    </div>
                                <?php
                                else:
                                    $quadrant_data_check = getStudentFailedIndicators($pdo, $selected_grade, $selected_room, $selected_exam_set, $selected_subject);
                                    if (empty($quadrant_data_check)):
                                ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="bi bi-info-circle"></i>
                                        <strong>ยังไม่มีข้อมูลนักเรียน</strong><br>
                                        กรุณานำเข้าข้อมูลนักเรียนและคะแนนผ่านเมนู <a href="import.php" class="alert-link">นำเข้าข้อมูล</a>
                                    </div>
                                <?php else: ?>
                                    <?php
                                    $absent_count = 0;
                                    foreach ($quadrant_data_check as $d) {
                                        if ($d['total_score'] === null) {
                                            $absent_count++;
                                        }
                                    }
                                    
                                    if ($absent_count > 0):
                                    ?>
                                    <div class="alert alert-secondary mb-3">
                                        <i class="bi bi-person-x-fill me-2"></i>
                                        <strong>มีนักเรียนขาดสอบ <?php echo $absent_count; ?> คน</strong> 
                                        <small class="text-muted ms-2">(ไม่ถูกนำมาแสดงในกราฟนี้)</small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div style="height: 350px; width: 100%;">
                                        <canvas id="quadrantChart"></canvas>
                                    </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Score Distribution Analysis (Bell Curve) -->
                    <div class="col-12 mb-4">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">📊 การกระจายตัวของคะแนนสถิติ (Score Distribution)</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                if (empty($selected_exam_set)):
                                ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong>กรุณาเลือกชุดข้อสอบ</strong><br>
                                        ต้องเลือกชุดข้อสอบ (Exam Set) เพื่อดูกราฟการกระจายตัวของคะแนน
                                    </div>
                                <?php
                                else:
                                    // Get Distribution Data
                                    $dist_data = getScoreDistribution($pdo, $selected_exam_set, $selected_grade);
                                    
                                    if (empty($dist_data['data']) || array_sum($dist_data['data']) == 0):
                                ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="bi bi-info-circle"></i>
                                        <strong>ยังไม่มีข้อมูลคะแนน</strong><br>
                                        ไม่พบข้อมูลคะแนนสำหรับชุดข้อสอบนี้
                                    </div>
                                <?php else: ?>
                                    <div style="height: 300px; width: 100%;">
                                        <canvas id="distributionChart"></canvas>
                                    </div>
                                    
                                    <!-- JSON Data for ChartJS -->
                                    <script>
                                        var distLabels = <?php echo json_encode($dist_data['labels']); ?>;
                                        var distData = <?php echo json_encode($dist_data['data']); ?>;
                                        
                                        // Initialize Chart immediately
                                        if (document.getElementById('distributionChart')) {
                                            const distCtx = document.getElementById('distributionChart').getContext('2d');
                                            new Chart(distCtx, {
                                                type: 'bar',
                                                data: {
                                                    labels: distLabels,
                                                    datasets: [{
                                                        label: 'จำนวนนักเรียน (Number of Students)',
                                                        data: distData, // JS automatically handles [1, 4, etc]
                                                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                                                        borderColor: 'rgba(54, 162, 235, 1)',
                                                        borderWidth: 1,
                                                        barPercentage: 0.8,
                                                        categoryPercentage: 0.9
                                                    }]
                                                },
                                                options: {
                                                    responsive: true,
                                                    maintainAspectRatio: false,
                                                    scales: {
                                                        y: {
                                                            beginAtZero: true,
                                                            title: {
                                                                display: true,
                                                                text: 'จำนวนนักเรียน (คน)'
                                                            },
                                                            ticks: {
                                                                stepSize: 1,
                                                                precision: 0
                                                            }
                                                        },
                                                        x: {
                                                            title: {
                                                                display: true,
                                                                text: 'ช่วงคะแนน (Score Range)'
                                                            }
                                                        }
                                                    },
                                                    plugins: {
                                                        legend: {
                                                            position: 'top',
                                                        },
                                                        tooltip: {
                                                            callbacks: {
                                                                label: function(context) {
                                                                    return context.parsed.y + ' คน';
                                                                }
                                                            }
                                                        },
                                                        title: {
                                                            display: true,
                                                            text: 'การกระจายตัวของคะแนนรวม (Total Score Distribution)'
                                                        }
                                                    }
                                                }
                                            });
                                        }
                                    </script>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Indicator Coverage Analysis -->
                    <?php if ($selected_grade && $selected_subject): ?>
                    <div class="col-12 mb-4 page-break">
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">🎯 การวิเคราะห์ความครอบคลุมตัวชี้วัด (Test Blueprint Coverage)</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                // Load threshold settings
                                $settings_file = __DIR__ . '/settings.json';
                                $weakness_threshold = 50; // Default
                                $strength_threshold = 80; // Default
                                
                                if (file_exists($settings_file)) {
                                    $settings = json_decode(file_get_contents($settings_file), true);
                                    if (isset($settings['weakness_threshold'])) {
                                        $weakness_threshold = $settings['weakness_threshold'];
                                    }
                                    if (isset($settings['strength_threshold'])) {
                                        $strength_threshold = $settings['strength_threshold'];
                                    }
                                }
                                
                                // Get indicator coverage statistics
                                // IMPORTANT: This aggregates across ALL exam_sets (no exam_set filter)
                                $coverage_query = "
                                    SELECT 
                                        i.id,
                                        i.code,
                                        i.description,
                                        i.subject,
                                        i.grade_level,
                                        COUNT(DISTINCT CONCAT(COALESCE(q.exam_set, 'default'), '-', qi.question_id)) as question_count,
                                        GROUP_CONCAT(
                                            DISTINCT CASE 
                                                WHEN q.question_number IS NOT NULL 
                                                THEN q.question_number 
                                            END 
                                            ORDER BY q.exam_set, q.question_number 
                                            SEPARATOR ', '
                                        ) as questions,
                                        CASE 
                                            WHEN COUNT(s.score_obtained) > 0 THEN 
                                                (SUM(
                                                    CASE 
                                                        WHEN s.score_obtained = 1 AND q.max_score > 1 THEN q.max_score 
                                                        ELSE s.score_obtained 
                                                    END
                                                ) / SUM(
                                                    CASE 
                                                        WHEN s.score_obtained IS NOT NULL THEN q.max_score 
                                                        ELSE 0 
                                                    END
                                                )) * 100 
                                            ELSE NULL
                                        END
                                        as avg_score

                                    FROM indicators i
                                    LEFT JOIN question_indicators qi ON i.id = qi.indicator_id
                                    LEFT JOIN questions q ON qi.question_id = q.id
                                    LEFT JOIN scores s ON q.question_number = s.question_number AND q.exam_set = s.exam_set
                                    WHERE 1=1
                                ";
                                
                                $cov_params = [];
                                
                                // Filter by grade level if selected
                                if ($selected_grade) {
                                    $coverage_query .= " AND (i.grade_level = ? OR i.grade_level IS NULL)";
                                    $cov_params[] = $selected_grade;
                                }
                                
                                if ($selected_subject) {
                                    $coverage_query .= " AND i.subject = ?";
                                    $cov_params[] = $selected_subject;
                                }
                                
                                // Filter by exam_set if selected
                                if ($selected_exam_set) {
                                    // Filter questions by exam_set (or default if exam_set is 'default')
                                    $coverage_query .= " AND q.exam_set = ?";
                                    $cov_params[] = $selected_exam_set;
                                }
                                
                                $coverage_query .= " GROUP BY i.code, i.description, i.subject, i.grade_level";
                                
                                $indicator_coverage = [];
                                try {
                                    $cov_stmt = $pdo->prepare($coverage_query);
                                    $cov_stmt->execute($cov_params);
                                    $indicator_coverage = $cov_stmt->fetchAll();
                                    
                                    // Sort by question_count DESC, subject, code (natural sort)
                                    if (!empty($indicator_coverage)) {
                                        usort($indicator_coverage, function($a, $b) {
                                            // First: sort by question count (descending)
                                            if ($a['question_count'] != $b['question_count']) {
                                                return $b['question_count'] - $a['question_count'];
                                            }
                                            // Second: sort by subject
                                            if ($a['subject'] != $b['subject']) {
                                                return strcmp($a['subject'], $b['subject']);
                                            }
                                            // Third: natural sort for code (handles numbers correctly)
                                            // This ensures ว1.2 ม.1/2 comes before ว1.2 ม.1/10
                                            return strnatcmp($a['code'], $b['code']);
                                        });
                                    }
                                } catch (PDOException $e) {
                                    // Tables don't exist yet, show empty state
                                    $indicator_coverage = [];
                                }
                                
                                if (empty($indicator_coverage)):
                                ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="bi bi-info-circle"></i>
                                        <strong>ยังไม่มีข้อมูลตัวชี้วัด</strong><br>
                                        กรุณานำเข้าข้อมูลตัวชี้วัดผ่านเมนู <a href="import.php" class="alert-link">นำเข้าข้อมูล</a>
                                    </div>
                                <?php else: 
                                    // Group by subject and calculate statistics
                                    $by_subject_cov = [];
                                    $total_ind = 0;
                                    $covered_ind = 0;
                                    $total_q = 0;
                                    
                                    foreach ($indicator_coverage as $ind) {
                                        $subj = $ind['subject'];
                                        if (!isset($by_subject_cov[$subj])) {
                                            $by_subject_cov[$subj] = [
                                                'indicators' => [],
                                                'total' => 0,
                                                'covered' => 0,
                                                'questions' => 0,
                                                'never_tested' => 0
                                            ];
                                        }
                                        $by_subject_cov[$subj]['indicators'][] = $ind;
                                        $by_subject_cov[$subj]['total']++;
                                        $total_ind++;
                                        
                                        $q_cnt = $ind['question_count'];
                                        if ($q_cnt > 0) {
                                            $by_subject_cov[$subj]['covered']++;
                                            $covered_ind++;
                                            $by_subject_cov[$subj]['questions'] += $q_cnt;
                                            $total_q += $q_cnt;
                                        } else {
                                            $by_subject_cov[$subj]['never_tested']++;
                                        }
                                    }
                                    
                                    $cov_pct = safeDiv($covered_ind, $total_ind, 0) * 100;
                                ?>
                                    <?php foreach ($by_subject_cov as $subj => $data): 
                                        $subj_cov = safeDiv($data['covered'], $data['total'], 0) * 100;
                                    ?>
                                        <div class="mb-4">
                                            <h6 class="border-bottom pb-2">
                                                📚 <?php echo htmlspecialchars($subj); ?>
                                            </h6>
                                            
                                            <div class="table-responsive">
                                                <table class="table table-sm table-hover">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width: 5%">ลำดับ</th>
                                                            <th style="width: 12%">รหัสตัวชี้วัด</th>
                                                            <th style="width: 30%">รายละเอียด</th>
                                                            <th style="width: 10%" class="text-center">คะแนนเฉลี่ย</th>
                                                            <th style="width: 10%" class="text-center">จำนวนข้อ</th>
                                                            <th style="width: 20%">ข้อสอบที่เกี่ยวข้อง</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php 
                                                        $rank = 1;
                                                        foreach ($data['indicators'] as $ind): 
                                                            $q_cnt = $ind['question_count'];
                                                            $row_class = '';
                                                            $badge_class = '';
                                                            
                                                            if ($q_cnt > 0) {
                                                                $badge_class = 'bg-success';
                                                            } else {
                                                                $row_class = 'table-light text-muted';
                                                                $badge_class = 'bg-secondary';
                                                            }
                                                        ?>
                                                                <tr class="<?php echo $row_class; ?>">
                                                                <td class="text-center"><?php echo $rank++; ?></td>
                                                                <td>
                                                                    <a href="#" onclick="viewIndicatorDetails(<?php echo $ind['id']; ?>); return false;" class="text-decoration-none text-dark">
                                                                        <strong><?php echo htmlspecialchars(normalizeIndicatorCode($ind['code'])); ?></strong>
                                                                    </a>
                                                                </td>
                                                                <td><small><?php echo htmlspecialchars($ind['description']); ?></small></td>
                                                                <td class="text-center">
                                                                    <?php 
                                                                    $avg_score = $ind['avg_score'];
                                                                    if ($avg_score !== null && $q_cnt > 0):
                                                                        $score_class = '';
                                                                        if ($avg_score >= $strength_threshold) {
                                                                            $score_class = 'bg-success';
                                                                        } elseif ($avg_score >= $weakness_threshold) {
                                                                            $score_class = 'bg-warning text-dark';
                                                                        } else {
                                                                            $score_class = 'bg-danger';
                                                                        }
                                                                    ?>
                                                                        <span class="badge <?php echo $score_class; ?>">
                                                                            <?php echo number_format($avg_score, 1); ?>%
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="text-muted">-</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="text-center">
                                                                    <span class="badge <?php echo $badge_class; ?>">
                                                                        <?php echo $q_cnt > 0 ? $q_cnt . ' ข้อ' : 'ไม่มี'; ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <small class="text-muted">
                                                                        <?php echo $ind['questions'] ?: '-'; ?>
                                                                    </small>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php 
                                    $high_freq_total = 0;
                                    foreach ($by_subject_cov as $data) {
                                        $high_freq_total += $data['high_frequency'];
                                    }
                                    if ($high_freq_total > 0): 
                                    ?>
                                        <div class="alert alert-danger">
                                            <h6><strong>🎯 คำแนะนำการสอน:</strong></h6>
                                            <ul class="mb-0">
                                                <li>มีตัวชี้วัด <strong class="text-danger"><?php echo $high_freq_total; ?> ตัว</strong> ที่ออกข้อสอบบ่อย (≥3 ข้อ) 
                                                    <strong>ควรเน้นสอนและฝึกฝนเป็นพิเศษ</strong></li>
                                                <li>ตัวชี้วัดที่ไม่เคยออกอาจไม่สำคัญหรือยังไม่ถึงคิว แต่ก็ควรสอนให้ครบตามหลักสูตร</li>
                                                <li>ใช้ข้อมูลนี้ประกอบการวางแผนการสอนและจัดสรรเวลาให้เหมาะสม</li>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Student Segmentation -->
                    <div class="col-12 mb-4 page-break">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    👥 การจัดกลุ่มนักเรียน
                                    <?php if ($selected_subject): ?>
                                        <span class="badge bg-light text-dark ms-2">วิชา: <?php echo htmlspecialchars($selected_subject); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark ms-2">ทุกวิชา</span>
                                    <?php endif; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php
                                if (!$selected_exam_set):
                                ?>
                                    <div class="alert alert-warning mb-0">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong>กรุณาเลือกชุดข้อสอบ</strong><br>
                                        ต้องเลือกชุดข้อสอบ (Exam Set) เพื่อดูการจัดกลุ่มนักเรียน
                                    </div>
                                <?php else:
                                    // Use subject-specific segmentation if subject is selected
                                    if ($selected_subject) {
                                        $segmented = segmentStudentsBySubject($pdo, $selected_subject, $selected_grade, $selected_room, null, $selected_exam_set);
                                    } else {
                                        $segmented = segmentStudents($pdo, $selected_grade, $selected_room, null, $selected_exam_set);
                                    }
                                    
                                    if (empty($segmented)):
                                    ?>
                                        <div class="alert alert-info mb-0">
                                            <i class="bi bi-info-circle"></i>
                                            <strong>ยังไม่มีข้อมูลนักเรียน</strong><br>
                                            กรุณานำเข้าข้อมูลนักเรียนและคะแนนผ่านเมนู <a href="import.php" class="alert-link">นำเข้าข้อมูล</a>
                                        </div>
                                    <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>ลำดับ</th>
                                                <th>รหัสนักเรียน</th>
                                                <th>ชื่อ-นามสกุล</th>
                                                <th>คะแนนสอบ (คิดเป็น %)</th>
                                                <th>ตัวชี้วัดผ่าน/ทั้งหมด</th>
                                                <th>กลุ่ม</th>
                                            </tr>
                                        </thead>
                                         <tbody>
                                            <?php
                                            $rank_count = 1;
                                            foreach ($segmented as $s):
                                                // Map color names to Bootstrap badge classes
                                                $badge_class = 'bg-' . $s['color'];
                                                // Special handling for purple (not in Bootstrap)
                                                if ($s['color'] === 'purple') {
                                                    $badge_class = 'badge-purple';
                                                }
                                            ?>
                                                <tr>
                                                    <td><?php echo $rank_count++; ?></td>
                                                    <td><?php echo htmlspecialchars($s['student_id']); ?></td>
                                                    <td>
                                                        <?php if ($s['segment'] === 'ขาดสอบ'): ?>
                                                            <span class="text-muted"><?php echo htmlspecialchars($s['name']); ?></span>
                                                        <?php else: ?>
                                                            <a href="index.php?view=individual&student=<?php echo urlencode($s['student_id']); ?>&grade=<?php echo urlencode($selected_grade); ?>&room=<?php echo urlencode($selected_room); ?>&subject=<?php echo urlencode($selected_subject); ?>&exam_set=<?php echo urlencode($selected_exam_set); ?>" class="text-decoration-none fw-bold">
                                                                <?php echo htmlspecialchars($s['name']); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        if ($s['segment'] === 'ขาดสอบ') {
                                                            echo '<span class="text-muted fst-italic">ไม่ได้เข้าสอบ</span>';
                                                        } elseif (isset($s['raw_total'])) {
                                                            echo number_format($s['raw_total'], 2) . ' / ' . number_format($s['raw_possible'], 2);
                                                            echo '<br><small class="text-muted">(' . number_format($s['score'], 2) . '%)</small>';
                                                        } else {
                                                            echo number_format($s['score'], 2) . '%';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        if ($s['segment'] !== 'ขาดสอบ') {
                                                            $passed = $s['indicators_passed'] ?? 0;
                                                            $total = $s['indicators_total'] ?? 0;
                                                            // Calculate percentage with 2 decimal places
                                                            $pass_pct = $total > 0 ? ($passed / $total) * 100 : 0;
                                                            $pass_color = $pass_pct >= 70 ? 'success' : ($pass_pct >= 50 ? 'warning' : 'danger');
                                                            ?>
                                                            <span class="badge bg-<?php echo $pass_color; ?>">
                                                                <?php echo $passed; ?>/<?php echo $total; ?> (<?php echo number_format($pass_pct, 2); ?>%)
                                                            </span>
                                                        <?php
                                                        } else {
                                                            echo '<span class="text-muted small">-</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><span class="badge <?php echo $badge_class; ?>"><?php echo $s['segment']; ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php // endforeach was already here ?>
                                        </tbody>
                                        <!-- Footer with Class Average -->
                                        <?php
                                        // Calculate class average
                                        // Calculate class average using Raw Scores to match Item Analysis Mean precision
                                        $sum_raw = 0;
                                        $sum_max = 0;
                                        $count_present = 0;
                                        foreach ($segmented as $student) {
                                            if ($student['segment'] !== 'ขาดสอบ' && isset($student['raw_total'])) {
                                                $sum_raw += $student['raw_total'];
                                                $sum_max += $student['raw_possible'];
                                                $count_present++;
                                            }
                                        }
                                        // Calculate global percentage: (Sum Raw / Sum Max) * 100
                                        $class_avg = ($sum_max > 0) ? ($sum_raw / $sum_max) * 100 : 0;
                                        ?>
                                        <tfoot>
                                            <tr class="table-secondary fw-bold">
                                                <td colspan="3" class="text-end">คะแนนเฉลี่ยรวม (Average Score):</td>
                                                <td><?php echo number_format($class_avg, 2); ?>%</td>
                                                <td colspan="2"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <script>
                // Quadrant Chart Data with Color Coding by Student Tier
                <?php
                $quadrant_data = getStudentFailedIndicators($pdo, $selected_grade, $selected_room, $selected_exam_set, $selected_subject);
                
                // Get segmentation for color coding
                if ($selected_subject) {
                    $segmented = segmentStudentsBySubject($pdo, $selected_subject, $selected_grade, $selected_room, null, $selected_exam_set);
                } else {
                    $segmented = segmentStudents($pdo, $selected_grade, $selected_room, null, $selected_exam_set);
                }
                
                // Create lookup for student colors and segments
                $student_info = [];
                foreach ($segmented as $s) {
                    $student_info[$s['student_id']] = [
                        'color' => $s['color'],
                        'segment' => $s['segment']
                    ];
                }
                
                // Prepare datasets by tier for color coding
                $datasets = [
                    'purple' => ['label' => 'ดีเยี่ยม', 'data' => [], 'bg' => 'rgba(102, 126, 234, 0.7)', 'border' => 'rgb(102, 126, 234)'],
                    'success' => ['label' => 'ดี', 'data' => [], 'bg' => 'rgba(40, 167, 69, 0.7)', 'border' => 'rgb(40, 167, 69)'],
                    'info' => ['label' => 'ปานกลาง', 'data' => [], 'bg' => 'rgba(23, 162, 184, 0.7)', 'border' => 'rgb(23, 162, 184)'],
                    'warning' => ['label' => 'ต้องพัฒนา', 'data' => [], 'bg' => 'rgba(255, 193, 7, 0.7)', 'border' => 'rgb(255, 193, 7)'],
                    'danger' => ['label' => 'ต้องช่วยเหลือเร่งด่วน', 'data' => [], 'bg' => 'rgba(220, 53, 69, 0.7)', 'border' => 'rgb(220, 53, 69)'],
                    'secondary' => ['label' => 'ขาดสอบ', 'data' => [], 'bg' => 'rgba(108, 117, 125, 0.7)', 'border' => 'rgb(108, 117, 125)']
                ];
                
                
                foreach ($quadrant_data as $d) {
                    // Skip absent students (score is null)
                    if ($d['total_score'] === null) {
                        continue;
                    }
                    
                    $color = $student_info[$d['student_id']]['color'] ?? 'info';
                    $datasets[$color]['data'][] = [
                        'x' => $d['total_score'],
                        'y' => $d['failed_indicators'],
                        'label' => $d['name']
                    ];
                }
                ?>
                
                const quadrantCanvas = document.getElementById('quadrantChart');
                if (quadrantCanvas) {
                    const ctx = quadrantCanvas.getContext('2d');
                    new Chart(ctx, {
                    type: 'scatter',
                    data: {
                        datasets: [
                            <?php 
                            $output = [];
                            foreach ($datasets as $key => $ds) {
                                if (!empty($ds['data'])) {
                                    $output[] = json_encode([
                                        'label' => $ds['label'],
                                        'data' => $ds['data'],
                                        'backgroundColor' => $ds['bg'],
                                        'borderColor' => $ds['border'],
                                        'borderWidth' => 2,
                                        'pointRadius' => 7,
                                        'pointHoverRadius' => 10
                                    ]);
                                }
                            }
                            echo implode(",\n", $output);
                            ?>
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.raw.label + ': คะแนน ' + 
                                               context.raw.x.toFixed(2) + '%, ตัวชี้วัดไม่ผ่าน ' + 
                                               context.raw.y + ' ตัว';
                                    }
                                }
                            },
                            legend: {
                                display: true,
                                position: 'top'
                            }
                        },
                        scales: {
                            x: {
                                title: {
                                    display: true,
                                    text: 'คะแนนรวม (%)',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    }
                                },
                                min: 0,
                                max: 100,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                }
                            },
                            y: {
                                title: {
                                    display: true,
                                    text: 'จำนวนตัวชี้วัดที่ไม่ผ่าน',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    }
                                },
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                }
                            }
                        }
                    }
                });
                }
                </script>
                
            <?php elseif ($view === 'individual' && $selected_student): ?>
                <!-- INDIVIDUAL VIEW: Student Report -->
                <?php
                $student_info_stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
                $student_info_stmt->execute([$selected_student]);
                $student_info = $student_info_stmt->fetch();
                
                if ($student_info):
                    $student_indicators = getStudentIndicators($pdo, $selected_student, $selected_subject, $student_info['grade_level'], $selected_exam_set);
                    $room_averages = getIndicatorAverages($pdo, $selected_grade, $selected_room ?: null, $selected_exam_set);
                    $grade_averages = getIndicatorAverages($pdo, $selected_grade, null, $selected_exam_set);
                    
                    // Load weakness and strength thresholds from settings
                    $settings_file = __DIR__ . '/settings.json';
                    $weakness_threshold = 50; // Default
                    $strength_threshold = 80; // Default
                    if (file_exists($settings_file)) {
                        $settings = json_decode(file_get_contents($settings_file), true);
                        
                        // Auto-detect subject from student's indicators if not selected in filter
                        $detected_subject = $selected_subject;
                        if (!$detected_subject && !empty($student_indicators)) {
                            $detected_subject = $student_indicators[0]['subject'] ?? null;
                        }
                        
                        // Use subject-specific thresholds if available
                        if ($detected_subject && isset($settings['subject_weakness_thresholds'][$detected_subject])) {
                            $weakness_threshold = $settings['subject_weakness_thresholds'][$detected_subject];
                        } else {
                            $weakness_threshold = $settings['weakness_threshold'] ?? 50;
                        }
                        
                        if ($detected_subject && isset($settings['subject_strength_thresholds'][$detected_subject])) {
                            $strength_threshold = $settings['subject_strength_thresholds'][$detected_subject];
                        } else {
                            $strength_threshold = $settings['strength_threshold'] ?? 80;
                        }
                    }
                    
                    // Debug
                    echo "<!-- DEBUG: Student found, indicators count: " . count($student_indicators) . ", weakness: $weakness_threshold%, strength: $strength_threshold% -->";
                ?>
                    <div class="row">
                        <!-- Student Info -->
                        <div class="col-12 mb-4">
                            <div class="card">
                                <div class="card-body">
                                    <h4><?php echo htmlspecialchars($student_info['prefix'] . ' ' . $student_info['name']); ?></h4>
                                    <p class="mb-0">
                                        รหัสนักเรียน: <?php echo htmlspecialchars($student_info['student_id']); ?> | 
                                        ระดับชั้น: <?php echo htmlspecialchars($student_info['grade_level']); ?> | 
                                        ห้อง: <?php echo htmlspecialchars($student_info['room_number']); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Radar Chart -->
                        <!-- Radar Chart -->
                        <div class="col-12 mb-4">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">📊 เปรียบเทียบผลการเรียน</h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="radarChart"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <?php
                        // Pre-calculate Strength and Weakness Lists
                        $strength_list = [];
                        $weakness_list = [];
                        $total_ind_count = count($student_indicators);
                        
                        foreach ($student_indicators as $ind) {
                            $pct = safeDiv($ind['obtained'], $ind['total'], 0) * 100;
                            $ind['percentage'] = $pct; // Store percentage
                            
                            if ($pct >= $strength_threshold) {
                                $strength_list[] = $ind;
                            } elseif ($pct < $weakness_threshold) {
                                $weakness_list[] = $ind;
                            }
                        }
                        ?>
                        
                        <!-- Strengths -->
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        ✨ จุดเด่น (คะแนน ≥ <?php echo $strength_threshold; ?>%) 
                                        <span class="badge bg-white text-success ms-2"><?php echo count($strength_list); ?>/<?php echo $total_ind_count; ?></span>
                                    </h5>
                                </div>
                                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                    <ul class="list-group">
                                        <?php if (!empty($strength_list)): ?>
                                            <?php foreach ($strength_list as $ind): ?>
                                                <li class="list-group-item">
                                                    <strong><?php echo htmlspecialchars($ind['code']); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($ind['description']); ?></small><br>
                                                    <span class="badge bg-success"><?php echo number_format($ind['percentage'], 2); ?>%</span>
                                                </li>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                        <?php endif; // End if !empty ?>
                                        <?php if (false): // Legacy block closure to match structure ?>
                                            <li class="list-group-item text-muted">
                                                ยังไม่มีตัวชี้วัดที่ได้คะแนน ≥ <?php echo $strength_threshold; ?>%
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Weaknesses -->
                        <!-- Weaknesses -->
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header bg-warning text-dark">
                                    <h5 class="mb-0">
                                        ⚠️ จุดอ่อน (คะแนน < <?php echo $weakness_threshold; ?>%)
                                        <span class="badge bg-white text-warning ms-2"><?php echo count($weakness_list); ?>/<?php echo $total_ind_count; ?></span>
                                    </h5>
                                </div>
                                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                    <ul class="list-group">
                                        <?php if (!empty($weakness_list)): ?>
                                            <?php foreach ($weakness_list as $ind): ?>
                                                <li class="list-group-item">
                                                    <strong><?php echo htmlspecialchars(normalizeIndicatorCode($ind['code'])); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($ind['description']); ?></small><br>
                                                    <span class="badge bg-danger"><?php echo number_format($ind['percentage'], 2); ?>%</span>
                                                </li>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                        <?php endif; // End if !empty ?>
                                        <?php if (false): // Legacy block closure ?>
                                            <li class="list-group-item text-success">
                                                ไม่พบจุดอ่อน นักเรียนมีผลการเรียนดีทุกตัวชี้วัด
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Detailed Scores -->
                        <div class="col-12 mb-4">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h5 class="mb-0">📋 คะแนนรายละเอียด</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>ตัวชี้วัด</th>
                                                    <th>รายละเอียด</th>
                                                    <th>คะแนนนักเรียน</th>
                                                    <th>เฉลี่ยห้อง</th>
                                                    <th>เฉลี่ยระดับชั้น</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($student_indicators as $ind): 
                                                    $student_pct = safeDiv($ind['obtained'], $ind['total'], 0) * 100;
                                                    $room_pct = $room_averages[$ind['id']] ?? 0;
                                                    $grade_pct = $grade_averages[$ind['id']] ?? 0;
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars(normalizeIndicatorCode($ind['code'])); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($ind['description']); ?></td>
                                                        <td><?php echo number_format($student_pct, 2); ?>%</td>
                                                        <td><?php echo number_format($room_pct, 2); ?>%</td>
                                                        <td><?php echo number_format($grade_pct, 2); ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    // Radar Chart
                    <?php
                    $radar_labels = [];
                    $radar_student = [];
                    $radar_room = [];
                    $radar_grade = [];
                    
                    foreach ($student_indicators as $ind) {
                        $radar_labels[] = normalizeIndicatorCode($ind['code']);
                        $radar_student[] = safeDiv($ind['obtained'], $ind['total'], 0) * 100;
                        $radar_room[] = $room_averages[$ind['id']] ?? 0;
                        $radar_grade[] = $grade_averages[$ind['id']] ?? 0;
                    }
                    ?>
                    
                    const radarCtx = document.getElementById('radarChart').getContext('2d');
                    new Chart(radarCtx, {
                        type: 'radar',
                        data: {
                            labels: <?php echo json_encode($radar_labels); ?>,
                            datasets: [
                                {
                                    label: 'นักเรียน',
                                    data: <?php echo json_encode($radar_student); ?>,
                                    borderColor: 'rgb(255, 99, 132)',
                                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                    borderWidth: 2
                                },
                                {
                                    label: 'เฉลี่ยห้อง',
                                    data: <?php echo json_encode($radar_room); ?>,
                                    borderColor: 'rgb(54, 162, 235)',
                                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                                    borderWidth: 2
                                },
                                {
                                    label: 'เฉลี่ยระดับชั้น',
                                    data: <?php echo json_encode($radar_grade); ?>,
                                    borderColor: 'rgb(75, 192, 192)',
                                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                                    borderWidth: 2
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            scales: {
                                r: {
                                    beginAtZero: true,
                                    max: 100,
                                    ticks: {
                                        stepSize: 20
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'top'
                                }
                            }
                        }
                    });
                    </script>
                <?php else: ?>
                    <div class="alert alert-warning">ไม่พบข้อมูลนักเรียน</div>
                <?php endif; ?>
                
            <?php endif; ?>
            
        <?php else: ?>
            <div class="alert alert-info text-center">
                <h4>ยินดีต้อนรับสู่ระบบวิเคราะห์ผลสอบ O-NET</h4>
                <p>กรุณาเลือกระดับชั้นเพื่อเริ่มการวิเคราะห์</p>
            </div>
        <?php endif; ?>
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
    // Auto-submit form when grade changes handled by inline onchange
    // document.getElementById('gradeSelect').addEventListener('change', ...);
    
    // Indicator Details Modal Script
    function viewIndicatorDetails(indicatorId) {
        console.log("Clicked Indicator ID:", indicatorId);
        
        if (!indicatorId) {
            alert("Error: Missing Indicator ID");
            return;
        }

        const modalEl = document.getElementById('indicatorDetailModal');
        if (!modalEl) {
            alert("Error: Modal Element not found");
            return;
        }

        const contentDiv = document.getElementById('indicatorDetailContent');
        if (!contentDiv) {
            alert("Error: Content Div not found");
            return;
        }

        try {
            // Check if bootstrap is loaded
            if (typeof bootstrap === 'undefined') {
                alert("Error: Bootstrap JS is not loaded. Please check your internet connection.");
                return;
            }

            const modal = new bootstrap.Modal(modalEl);
            
            // Show loading state
            contentDiv.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary"></div><p class="mt-2">กำลังโหลดข้อมูล...</p></div>';
            modal.show();
            
            // Prepare data
            const formData = new FormData();
            formData.append('indicator_id', indicatorId);
            formData.append('grade_level', '<?php echo $selected_grade ?? ""; ?>');
            formData.append('room_number', '<?php echo $selected_room ?? ""; ?>');
            formData.append('exam_set', '<?php echo $selected_exam_set ?? ""; ?>');
            
            // Fetch data
            fetch('ajax_indicator_details.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                contentDiv.innerHTML = html;
            })
            .catch(error => {
                contentDiv.innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาด: ' + error.message + '</div>';
                console.error('Error:', error);
            });

        } catch (e) {
            console.error("Critical Error:", e);
            alert("System Error: " + e.message);
        }
    }
    </script>
    
    <!-- Indicator Details Modal -->
    <div class="modal fade" id="indicatorDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">รายละเอียดตัวชี้วัด</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="indicatorDetailContent">
                    <!-- Content via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
