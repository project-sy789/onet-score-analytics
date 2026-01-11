<?php
require_once 'db.php';

// Print DB details
echo "<h3>DB_NAME Constant: " . (defined('DB_NAME') ? htmlspecialchars(DB_NAME) : 'Unknown') . "</h3>";
if (defined('DB_NAME') && strpos(DB_NAME, 'sqlite:') !== 0 && file_exists(DB_NAME)) {
    echo "<h3>Real Path: " . realpath(DB_NAME) . " (" . filesize(DB_NAME) . " bytes)</h3>";
}

// Check counts
$tables = ['students', 'indicators', 'questions', 'scores'];
foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo "$t count: $c <br>";
    } catch (Exception $e) {
        echo "$t error: " . $e->getMessage() . "<br>";
    }
}

echo "<hr>";
echo "<h3>Questions Sample (up to 10):</h3>";
$q = $pdo->query("SELECT * FROM questions LIMIT 10");
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) {
    echo "No rows found in questions table.";
} else {
    echo "<table border=1><tr>";
    foreach (array_keys($rows[0]) as $k) echo "<th>$k</th>";
    echo "</tr>";
    foreach ($rows as $r) {
        echo "<tr>";
        foreach ($r as $v) echo "<td>" . htmlspecialchars($v) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr><h3>Table Schema (Questions):</h3>";
try {
    // MySQL
    $stmt = $pdo->query("SHOW CREATE TABLE questions");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . htmlspecialchars($row['Create Table']) . "</pre>";
    
    echo "<h4>Indexes:</h4>";
    $stmt = $pdo->query("SHOW INDEX FROM questions");
    echo "<table border=1><tr><th>Key_name</th><th>Column_name</th><th>Seq_in_index</th></tr>";
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr><td>{$r['Key_name']}</td><td>{$r['Column_name']}</td><td>{$r['Seq_in_index']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    // SQLite fallback
    echo "MySQL Check failed: " . $e->getMessage() . "<br>Trying SQLite...<br>";
    try {
        $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='questions'");
        echo "<pre>" . htmlspecialchars($stmt->fetchColumn()) . "</pre>";
    } catch (Exception $e2) {
        echo "SQLite Check failed: " . $e2->getMessage();
    }
}
?>
