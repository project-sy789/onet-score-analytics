<?php
require_once 'db.php';
require_once 'functions.php';

// Check if indicator_id is provided
if (!isset($_POST['indicator_id'])) {
    echo '<div class="alert alert-danger">ไม่พบรหัสตัวชี้วัด</div>';
    exit;
}

$indicator_id = $_POST['indicator_id'];
$grade_level = $_POST['grade_level'] ?? null;
$room_number = $_POST['room_number'] ?? null;
$exam_set = $_POST['exam_set'] ?? null;

// Get Indicator Info
$stmt = $pdo->prepare("SELECT * FROM indicators WHERE id = ?");
$stmt->execute([$indicator_id]);
$indicator = $stmt->fetch();

if (!$indicator) {
    echo '<div class="alert alert-danger">ไม่พบข้อมูลตัวชี้วัด</div>';
    exit;
}

// Get Data
$students = getStudentsByIndicator($pdo, $indicator_id, $grade_level, $room_number, $exam_set);

if (empty($students)) {
    echo '<div class="alert alert-warning">ไม่พบข้อมูลนักเรียนสำหรับตัวชี้วัดนี้</div>';
    exit;
}

// Prepare thresholds (Check Specific Grade -> Subject Default -> Global)
$pass_threshold = 50; 
$settings_file = __DIR__ . '/settings.json';
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
    
    $subject = $indicator['subject'];
    // Construct lookup key using the grade level passed from POST or derived from somewhere?
    // We have $grade_level from line 12.
    // However, if $grade_level is 'all' or empty, we default to just subject.
    // The indicator itself has a grade_level column too ($indicator['grade_level']).
    // It's safer to use the indicator's intrinsic grade level if available, but the filter might be overriding specific exam sets.
    // Let's use the $grade_level passed in from the context (POST) if available, otherwise indicator's grade.
    
    $lookup_grade = $grade_level ?: ($indicator['grade_level'] ?? '');
    
    $lookup_key = $subject;
    if ($lookup_grade && $lookup_grade !== 'all') {
        $lookup_key .= '|' . $lookup_grade;
    }

    if (isset($settings['subject_indicator_pass_thresholds'][$lookup_key])) {
        $pass_threshold = $settings['subject_indicator_pass_thresholds'][$lookup_key];
    } elseif (isset($settings['subject_indicator_pass_thresholds'][$subject])) {
        $pass_threshold = $settings['subject_indicator_pass_thresholds'][$subject];
    } elseif (isset($settings['indicator_pass_threshold'])) {
        $pass_threshold = $settings['indicator_pass_threshold'];
    }
}
// Get Question Count for context
$q_count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT question_id) FROM question_indicators WHERE indicator_id = ?");
$q_count_stmt->execute([$indicator_id]);
$question_count = $q_count_stmt->fetchColumn();
?>

<div class="mb-3">
    <h5>
        <?php echo htmlspecialchars(normalizeIndicatorCode($indicator['code'])); ?>
        <span class="badge bg-secondary ms-2" style="font-size: 0.8em;"><?php echo $question_count; ?> ข้อ</span>
    </h5>
    <p class="text-muted"><?php echo htmlspecialchars($indicator['description']); ?></p>
</div>

<div class="table-responsive">
    <table class="table table-bordered table-sm table-hover" id="indicatorDetailTable">
        <thead class="table-light">
            <tr>
                <th>ห้อง</th>
                <th>เลขที่/รหัส</th>
                <th>ชื่อ-นามสกุล</th>
                <th class="text-center">คะแนนที่ได้</th>
                <th class="text-center">เต็ม</th>
                <th class="text-center">%</th>
                <th class="text-center">ผลลัพธ์</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $pass_count = 0;
            $fail_count = 0;
            
            foreach ($students as $st): 
                $obtained = $st['obtained_score'];
                $max = $st['max_score'];
                $pct = ($max > 0) ? ($obtained / $max) * 100 : 0;
                $is_pass = $pct >= $pass_threshold;
                
                if ($is_pass) $pass_count++; else $fail_count++;
            ?>
            <tr>
                <td><?php echo htmlspecialchars($st['room_number']); ?></td>
                <td><?php echo htmlspecialchars($st['student_id']); ?></td>
                <td><?php echo htmlspecialchars($st['prefix'] . $st['name']); ?></td>
                <td class="text-center"><?php echo number_format($obtained, 2); ?></td>
                <td class="text-center"><?php echo number_format($max, 2); ?></td>
                <td class="text-center fw-bold <?php echo $is_pass ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($pct, 2); ?>%
                </td>
                <td class="text-center">
                    <?php if ($is_pass): ?>
                        <span class="badge bg-success">ผ่าน</span>
                    <?php else: ?>
                        <span class="badge bg-danger">ไม่ผ่าน</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="alert alert-light border mt-3">
    <strong>สรุปผล:</strong> 
    ผ่าน <span class="text-success fw-bold"><?php echo $pass_count; ?></span> คน 
    / ไม่ผ่าน <span class="text-danger fw-bold"><?php echo $fail_count; ?></span> คน 
    (จากทั้งหมด <?php echo count($students); ?> คน)
</div>

<script>
    // Simple datatable if needed, but standard table is fine for modal
</script>
