<?php
/**
 * Exam Data Inspector
 * Tool to visualize exact content of the Questions table to diagnose duplication.
 */
require_once __DIR__ . '/db.php';

$selected_exam_set = $_GET['exam_set'] ?? '';
$action = $_POST['action'] ?? '';

// Handle Delete Action
if ($action === 'delete_id' && !empty($_POST['question_id'])) {
    $del_id = $_POST['question_id'];
    $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
    $stmt->execute([$del_id]);
    
    // Also delete mappings
    $stmt = $pdo->prepare("DELETE FROM question_indicators WHERE question_id = ?");
    $stmt->execute([$del_id]);
    
    $message = "Deleted Question ID: $del_id";
    $message_type = "success";
}

// Get all exam sets
$sets_stmt = $pdo->query("SELECT DISTINCT exam_set FROM questions ORDER BY exam_set");
$exam_sets = $sets_stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="logo.png">
    <title>🕵️ Exam Inspector</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; background: #f8f9fa; }</style>
</head>
<body class="p-4">
    <div class="container">
        <h2 class="mb-4">🕵️ ตรวจสอบข้อมูลข้อสอบ (Exam Inspector)</h2>
        
        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <form method="GET" class="mb-4 card p-3">
            <label class="form-label fw-bold">เลือกชุดข้อสอบที่ต้องการตรวจสอบ:</label>
            <div class="input-group">
                <select name="exam_set" class="form-select" onchange="this.form.submit()">
                    <option value="">-- เลือกชุดข้อสอบ --</option>
                    <?php foreach ($exam_sets as $set): ?>
                        <option value="<?php echo htmlspecialchars($set); ?>" <?php echo $selected_exam_set === $set ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($set); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">🔍 ตรวจสอบ</button>
            </div>
        </form>

        <?php if ($selected_exam_set): ?>
            <?php
            // Get Questions
            $q_stmt = $pdo->prepare("SELECT * FROM questions WHERE exam_set = ? ORDER BY CAST(question_number AS UNSIGNED), question_number");
            $q_stmt->execute([$selected_exam_set]);
            $questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Stats
            $total_records = count($questions);
            $total_max = 0;
            foreach ($questions as $q) $total_max += $q['max_score'];
            ?>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <h3>จำนวนข้อ: <?php echo $total_records; ?></h3>
                            <small>Records found in DB</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h3>คะแนนเต็มรวม: <?php echo $total_max; ?></h3>
                            <small>Sum of Max Scores</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-dark text-white">
                    <strong>รายการข้อสอบทั้งหมด (<?php echo $selected_exam_set; ?>)</strong>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>ข้อที่ (Num)</th>
                                <th>คะแนนเต็ม (Max)</th>
                                <th>วิชา (Subject)</th>
                                <th>การกระทำ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $q): ?>
                            <tr>
                                <td><?php echo $q['id']; ?></td>
                                <td><?php echo htmlspecialchars($q['question_number']); ?></td>
                                <td><?php echo $q['max_score']; ?></td>
                                <td><?php echo htmlspecialchars($q['subject']); ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('ยืนยันลบ ID: <?php echo $q['id']; ?>?');">
                                        <input type="hidden" name="action" value="delete_id">
                                        <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                        <input type="hidden" name="exam_set" value="<?php echo htmlspecialchars($selected_exam_set); ?>"> <!-- Keep context -->
                                        <button type="submit" class="btn btn-danger btn-sm">🗑️ ลบ (Delete)</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
