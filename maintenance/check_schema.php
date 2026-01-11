<?php
require_once 'db.php';

try {
    echo "Database: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
    
    // Check questions table columns
    $stmt = $pdo->query("DESCRIBE questions");
    echo "\nColumns in 'questions' table:\n";
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . "\n";
    }
} catch (Exception $e) {
    // Try SQLite syntax if MySQL fails
    try {
        $stmt = $pdo->query("PRAGMA table_info(questions)");
        echo "\nColumns in 'questions' table (SQLite):\n";
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo $col['name'] . "\n";
        }
    } catch (Exception $e2) {
        echo "Error: " . $e->getMessage();
    }
}
?>
