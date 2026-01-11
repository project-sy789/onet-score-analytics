<?php
/**
 * WordPress-Style Installation Wizard
 * Creates config.php and database tables automatically
 */

// SQL Schema for database tables
// SQL Schema for database tables
// Load from external schema.sql file to ensure single source of truth
$schema_file = __DIR__ . '/schema.sql';
if (file_exists($schema_file)) {
    $sql_schema = file_get_contents($schema_file);
} else {
    die("Error: schema.sql file not found. Please ensure it exists in the same directory.");
}

// Check if already installed
if (file_exists(__DIR__ . '/config.php')) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = false;

// Process installation form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? '');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';
    
    // Validate inputs
    if (empty($db_host) || empty($db_name) || empty($db_user)) {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    } else {
        try {
            // Test database connection
            $dsn = "mysql:host=$db_host;charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // Create database if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db_name`");
            
            // Execute schema creation
            $pdo->exec($sql_schema);
            
            // Create config.php file
            $config_content = "<?php\n";
            $config_content .= "// Database Configuration\n";
            $config_content .= "define('DB_HOST', " . var_export($db_host, true) . ");\n";
            $config_content .= "define('DB_NAME', " . var_export($db_name, true) . ");\n";
            $config_content .= "define('DB_USER', " . var_export($db_user, true) . ");\n";
            $config_content .= "define('DB_PASS', " . var_export($db_pass, true) . ");\n";
            
            if (file_put_contents(__DIR__ . '/config.php', $config_content)) {
                $success = true;
            } else {
                $error = 'ไม่สามารถสร้างไฟล์ config.php ได้ กรุณาตรวจสอบสิทธิ์การเขียนไฟล์';
            }
            
        } catch (PDOException $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ติดตั้งระบบวิเคราะห์ผลสอบ O-NET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Sarabun', sans-serif;
        }
        .install-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo h1 {
            color: #667eea;
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .logo p {
            color: #6c757d;
            font-size: 16px;
        }
        .success-icon {
            text-align: center;
            font-size: 80px;
            color: #28a745;
            margin-bottom: 20px;
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="install-container">
        <?php if ($success): ?>
            <div class="success-icon">✓</div>
            <h2 class="text-center text-success mb-4">ติดตั้งระบบสำเร็จ!</h2>
            <p class="text-center mb-4">ระบบได้สร้างฐานข้อมูลและตารางทั้งหมดเรียบร้อยแล้ว</p>
            <div class="d-grid">
                <a href="index.php" class="btn btn-primary btn-lg">เข้าสู่ระบบ</a>
            </div>
        <?php else: ?>
            <div class="logo">
                <h1>🎓 ระบบวิเคราะห์ผลสอบ O-NET</h1>
                <p>ติดตั้งระบบครั้งแรก</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <strong>เกิดข้อผิดพลาด!</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label for="db_host" class="form-label">ชื่อโฮสต์ฐานข้อมูล</label>
                    <input type="text" class="form-control" id="db_host" name="db_host" 
                           value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>" required>
                    <div class="form-text">โดยทั่วไปคือ localhost</div>
                </div>
                
                <div class="mb-3">
                    <label for="db_name" class="form-label">ชื่อฐานข้อมูล</label>
                    <input type="text" class="form-control" id="db_name" name="db_name" 
                           value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>" required>
                    <div class="form-text">ชื่อฐานข้อมูลที่ต้องการใช้งาน</div>
                </div>
                
                <div class="mb-3">
                    <label for="db_user" class="form-label">ชื่อผู้ใช้ฐานข้อมูล</label>
                    <input type="text" class="form-control" id="db_user" name="db_user" 
                           value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" required>
                </div>
                
                <div class="mb-4">
                    <label for="db_pass" class="form-label">รหัสผ่านฐานข้อมูล</label>
                    <input type="password" class="form-control" id="db_pass" name="db_pass">
                    <div class="form-text">หากไม่มีรหัสผ่าน ให้เว้นว่างไว้</div>
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">ติดตั้งระบบ</button>
                </div>
            </form>
            
            <div class="mt-4 text-center">
                <small class="text-muted">
                    ระบบจะสร้างไฟล์ config.php และตารางฐานข้อมูลโดยอัตโนมัติ
                </small>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
