<?php
/**
 * Exam Comparison Page
 * Allows comparing student performance across multiple exam sets
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Get filter values
$selected_grade = $_GET['grade'] ?? '';
$selected_subject = $_GET['subject'] ?? '';
$selected_room = $_GET['room'] ?? ''; 
// Handle Sorted Sets Order (Prioritize hidden input if available)
if (!empty($_GET['sorted_sets'])) {
    $selected_sets = explode(',', $_GET['sorted_sets']);
    // Filter out empties
    $selected_sets = array_filter($selected_sets);
} else {
    $selected_sets = $_GET['exam_sets'] ?? []; 
    // Fallback sort only if no manual order provided
    // sort($selected_sets); 
    // Actually, keep DOM order (or whatever PHP receives) if no sorted_sets provided.
    // User requested Click Order, which implies manual control.
}

// Ensure unique
$selected_sets = array_unique($selected_sets);
$order = $_GET['order'] ?? ''; // Keep logic but make it secondary if sorted_sets exists

// If manual sort order (asc/desc) is explicitly set via dropdown, we might override click order? 
// But user said "Click Order". So we disable auto-sort if using click order mechanism.
if (!empty($_GET['order']) && empty($_GET['sorted_sets'])) {
    if ($_GET['order'] === 'desc') {
        rsort($selected_sets);
    } else {
        sort($selected_sets);
    }
}

// Get available grades
$grades_stmt = $pdo->query("SELECT DISTINCT grade_level FROM students ORDER BY grade_level");
$grades = $grades_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get available subjects (filter by grade)
$subjects = [];
$rooms = []; // NEW: Rooms
if ($selected_grade) {
    $subjects_stmt = $pdo->prepare("SELECT DISTINCT subject FROM questions q INNER JOIN scores s ON q.question_number = s.question_number AND q.exam_set = s.exam_set INNER JOIN students st ON s.student_id = st.student_id WHERE st.grade_level = ? ORDER BY subject");
    $subjects_stmt->execute([$selected_grade]);
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get rooms
    $rooms_stmt = $pdo->prepare("SELECT DISTINCT room_number FROM students WHERE grade_level = ? ORDER BY room_number");
    $rooms_stmt->execute([$selected_grade]);
    $rooms = $rooms_stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get available exam sets
$exam_sets = [];
if ($selected_grade && $selected_subject) {
    $sets_stmt = $pdo->prepare("
        SELECT DISTINCT q.exam_set 
        FROM questions q 
        INNER JOIN scores s ON q.question_number = s.question_number AND q.exam_set = s.exam_set 
        INNER JOIN students st ON s.student_id = st.student_id 
        WHERE st.grade_level = ? AND q.subject = ? 
        AND q.exam_set IS NOT NULL AND q.exam_set != '' AND q.exam_set != 'default'
        ORDER BY q.exam_set
    ");
    $sets_stmt->execute([$selected_grade, $selected_subject]);
    $exam_sets = $sets_stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Process Comparison Data
$comparison_data = [];
$set_averages = []; // For Chart

if ($selected_grade && $selected_subject && !empty($selected_sets)) {
    // 1. Get List of Students in this Grade/Subject (who took at least one of the exams)
    // Actually, maybe better to get students who are in the grade, and left join scores?
    // Let's get students who have scores in ANY of the selected sets.
    
    $placeholders = str_repeat('?,', count($selected_sets) - 1) . '?';
    
    // Get raw scores pivoted by PHP side usually easier than dynamic SQL pivot
    $sql = "
        SELECT 
            st.student_id, 
            st.name, 
            q.exam_set,
            SUM(s.score_obtained) as raw_score,
            (SELECT SUM(max_score) FROM questions q2 WHERE q2.exam_set = q.exam_set AND q2.subject = ? AND q2.grade_level = ?) as max_score
        FROM scores s
        JOIN students st ON s.student_id = st.student_id
        JOIN questions q ON s.question_number = q.question_number AND s.exam_set = q.exam_set AND q.grade_level = st.grade_level
        WHERE st.grade_level = ? 
        AND q.subject = ? 
        AND q.exam_set IN ($placeholders)
    ";

    $params = array_merge([$selected_subject, $selected_grade, $selected_grade, $selected_subject], $selected_sets);

    // Filter by Room if selected
    if ($selected_room) {
        $sql .= " AND st.room_number = ? ";
        $params[] = $selected_room; // Append room to end (note: verify param order!)
    }
    
    // Correction: $params order is strictly positional.
    // The query has placeholders: ?, ?, ?, IN (?,?,...)
    // Wait.
    // Line 69 param (subquery): selected_subject
    // Line 74 param (grade): selected_grade
    // Line 75 param (subject): selected_subject
    // Line 76 param (IN): selected_sets...
    
    // If I append `AND st.room = ?` at the end of WHERE clause.
    // I must append `$selected_room` to `$params`.
    // My previous array_merge put `$selected_sets` at the END.
    // So if I add WHERE clause AFTER `exam_set IN (...)`, I must add `$selected_room` AFTER `$selected_sets`.
    
    // Correct.
    
    $sql .= "
        GROUP BY st.student_id, st.name, q.exam_set
        ORDER BY st.student_id
    ";
    
    // Duplicate params block removed here
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize by Student
    foreach ($rows as $r) {
        $sid = $r['student_id'];
        if (!isset($comparison_data[$sid])) {
            $comparison_data[$sid] = [
                'id' => $sid,
                'name' => $r['name'],
                'scores' => []
            ];
        }
        
        // Calculate Percentage
        // Note: max_score comes from subquery. Ensure it's correct per set.
        $max = $r['max_score'] > 0 ? $r['max_score'] : 1;
        $pct = ($r['raw_score'] / $max) * 100;
        
        $comparison_data[$sid]['scores'][$r['exam_set']] = [
            'raw' => $r['raw_score'],
            'max' => $r['max_score'],
            'pct' => $pct
        ];
    }
    
    // Calculate Averages per Set (for Chart)
    foreach ($selected_sets as $set) {
        $total_pct = 0;
        $count = 0;
        foreach ($comparison_data as $student) {
            if (isset($student['scores'][$set])) {
                $total_pct += $student['scores'][$set]['pct'];
                $count++;
            }
        }
        $set_averages[$set] = $count > 0 ? $total_pct / $count : 0;
    }
}

// Prepare Cascade JS mapping (Grade -> Subject) - Copied logic from index.php simplified
// Actually we only need Grade -> Subject -> All Exam Sets
// We can reuse the same JSON approach if implemented.
$cascade_mapping = [];
// ... (Logic similar to index.php, omitted for brevity, will rely on server reload for now or add simple PHP-based refresh)
// To make it simple for now, we rely on GET params refreshing the page for the 'Subjects' list.
// But for exam sets, we want them to appear.
// The code above fetches exam_sets based on GET params.
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เปรียบเทียบผลสอบ - ระบบวิเคราะห์ผลสอบ O-NET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .trend-up { color: #198754; font-weight: bold; }
        .trend-down { color: #dc3545; font-weight: bold; }
        .trend-flat { color: #6c757d; }
    </style>
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
                    <li class="nav-item"><a class="nav-link" href="index.php">หน้าหลัก</a></li>
                    <li class="nav-item"><a class="nav-link" href="import.php">นำเข้าข้อมูล</a></li>
                    <li class="nav-item"><a class="nav-link" href="manage_exams.php">จัดการข้อสอบ</a></li>
                    <li class="nav-item"><a class="nav-link active" href="compare.php">เปรียบเทียบ</a></li>
                    <li class="nav-item"><a class="nav-link" href="settings.php">ตั้งค่า</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4 flex-grow-1">
        <h1 class="mb-4">📈 เปรียบเทียบพัฒนาการ (Comparison)</h1>
        
        <!-- Filter Card -->
        <div class="card mb-4">
            <div class="card-header bg-light"><strong>ตัวเลือกการเปรียบเทียบ</strong></div>
            <div class="card-body">
                <form method="GET" action="">
                    <!-- Hidden field to store Click Order -->
                    <input type="hidden" name="sorted_sets" id="sorted_sets" value="<?php echo htmlspecialchars(implode(',', $selected_sets)); ?>">
                    
                    <div class="row">
                        <div class="col-md-3">
                            <label class="form-label">ระดับชั้น</label>
                            <select name="grade" class="form-select" onchange="this.form.submit()">
                                <option value="">-- เลือกระดับชั้น --</option>
                                <?php foreach ($grades as $g): ?>
                                    <option value="<?php echo htmlspecialchars($g); ?>" <?php echo $selected_grade === $g ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($g); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">วิชา</label>
                            <select name="subject" class="form-select" onchange="this.form.submit()">
                                <option value="">-- เลือกวิชา --</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $selected_subject === $s ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <label class="form-label mt-2">ห้องเรียน (Option)</label>
                            <select name="room" class="form-select" onchange="this.form.submit()">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach ($rooms as $r): ?>
                                    <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $selected_room === $r ? 'selected' : ''; ?>>
                                        ห้อง <?php echo htmlspecialchars($r); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label class="form-label mt-2">เรียงลำดับ (Sort Order)</label>
                             <select name="order" class="form-select" onchange="this.form.submit()">
                                <option value="">ตามลำดับการเลือก (Click Order)</option>
                                <option value="asc" <?php echo ($order ?? '') === 'asc' ? 'selected' : ''; ?>>น้อยไปมาก (A-Z)</option>
                                <option value="desc" <?php echo ($order ?? '') === 'desc' ? 'selected' : ''; ?>>มากไปน้อย (Z-A)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">เลือกชุดข้อสอบ (แนะนำเรียงตามลำดับเวลา)</label>
                            <div class="card p-2" style="max-height: 150px; overflow-y: auto;">
                                <?php if (empty($exam_sets)): ?>
                                    <div class="text-muted small">กรุณาเลือกระดับชั้นและวิชาก่อน</div>
                                <?php else: ?>
                                    <?php foreach ($exam_sets as $es): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="exam_sets[]" value="<?php echo htmlspecialchars($es); ?>" id="es_<?php echo htmlspecialchars($es); ?>"
                                                <?php echo in_array($es, $selected_sets) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="es_<?php echo htmlspecialchars($es); ?>">
                                                <?php echo htmlspecialchars($es); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">📊 แสดงผลเปรียบเทียบ</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($comparison_data)): ?>
            <!-- Chart Section -->
            <div class="card mb-4">
                <div class="card-header">แนวโน้มคะแนนเฉลี่ยทั้งห้อง</div>
                <div class="card-body">
                    <canvas id="trendChart" height="80"></canvas>
                </div>
            </div>

            <!-- Comparison Table -->
            <div class="card">
                <div class="card-header">รายละเอียดรายบุคคล</div>
                <div class="card-body">
                    <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr class="table-light">
                                <th>รหัสนักเรียน</th>
                                <th>ชื่อ-นามสกุล</th>
                                <?php foreach ($selected_sets as $set): ?>
                                    <th class="text-center"><?php echo htmlspecialchars($set); ?></th>
                                <?php endforeach; ?>
                                <th class="text-center">แนวโน้ม</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comparison_data as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['id']); ?></td>
                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                    <?php 
                                    $prev_score = null;
                                    $trend_icon = '';
                                    foreach ($selected_sets as $set): 
                                        $score_data = $student['scores'][$set] ?? null;
                                    ?>
                                        <td class="text-center">
                                            <?php if ($score_data): ?>
                                                <div><?php echo number_format($score_data['pct'], 2); ?>%</div>
                                                <small class="text-muted">(<?php echo (float)$score_data['raw'] == (int)$score_data['raw'] ? (int)$score_data['raw'] : number_format($score_data['raw'], 4); ?>/<?php echo (float)$score_data['max'] == (int)$score_data['max'] ? (int)$score_data['max'] : number_format($score_data['max'], 4); ?>)</small>
                                                <?php 
                                        if ($score_data) {
                                            if ($prev_score !== null) {
                                                $diff = $score_data['pct'] - $prev_score;
                                                $diff_text = number_format(abs($diff), 2) . '%';
                                                
                                                if ($diff > 0) $trend_icon = '<span class="trend-up">↗ +' . $diff_text . '</span>';
                                                elseif ($diff < 0) $trend_icon = '<span class="trend-down">↘ -' . $diff_text . '</span>';
                                                else $trend_icon = '<span class="trend-flat">→ 0.00%</span>';
                                            }
                                            $prev_score = $score_data['pct'];
                                        }
                                    ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center fw-bold"><?php echo $trend_icon; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary fw-bold">
                                <td colspan="2" class="text-end">คะแนนเฉลี่ยรวม (Class Average):</td>
                                <?php 
                                $keys = array_values($selected_sets);
                                foreach ($keys as $index => $set): 
                                    $avg = $set_averages[$set] ?? 0;
                                ?>
                                    <td class="text-center"><?php echo number_format($avg, 2); ?>%</td>
                                <?php endforeach; ?>
                                
                                <td class="text-center">
                                    <?php 
                                    if (count($keys) >= 2) {
                                        $last_key = end($keys);
                                        $prev_key = prev($keys);
                                        
                                        $last_avg = $set_averages[$last_key] ?? 0;
                                        $prev_avg = $set_averages[$prev_key] ?? 0;
                                        
                                        $grand_diff = $last_avg - $prev_avg;
                                        $diff_text = number_format(abs($grand_diff), 2) . '%';
                                        
                                        if ($grand_diff > 0) echo '<span class="trend-up">↗ +' . $diff_text . '</span>';
                                        elseif ($grand_diff < 0) echo '<span class="trend-down">↘ -' . $diff_text . '</span>';
                                        else echo '<span class="trend-flat">→ 0.00%</span>';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            </div>

            <script>
            // Render Chart
            const ctx = document.getElementById('trendChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($selected_sets); ?>,
                    datasets: [{
                        label: 'คะแนนเฉลี่ย (%)',
                        data: <?php echo json_encode(array_values($set_averages)); ?>,
                        borderColor: 'rgb(75, 192, 192)',
                        tension: 0.1,
                        fill: false
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });
            </script>
        <?php elseif ($selected_grade && !empty($selected_sets)): ?>
            <div class="alert alert-warning">ไม่พบข้อมูลคะแนนสำหรับเงื่อนไขที่เลือก</div>
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
        // Sort Tracking Logic
        // Initialize from PHP-processed selected_sets
        let activeSets = <?php echo json_encode(array_values($selected_sets)); ?>;
        const sortedInput = document.getElementById('sorted_sets');
        
        if (sortedInput) {
            // Ensure unique just in case
            activeSets = [...new Set(activeSets)];

            document.querySelectorAll('input[name="exam_sets[]"]').forEach(cb => {
                cb.addEventListener('change', function() {
                    if (this.checked) {
                        if (!activeSets.includes(this.value)) activeSets.push(this.value);
                    } else {
                        activeSets = activeSets.filter(item => item !== this.value);
                    }
                    sortedInput.value = activeSets.join(',');
                });
            });
        }
    </script>
</body>
</html>
