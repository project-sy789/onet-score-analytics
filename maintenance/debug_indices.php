<?php
require_once 'db.php';

echo "<h2>Table Indices Check</h2>";

try {
    $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database driver: $db_driver<br>";
    
    $tables = ['questions', 'scores'];
    
    foreach ($tables as $table) {
        echo "<h3>Table: $table</h3>";
        
        // Show Columns
        echo "<b>Columns:</b><br>";
        if ($db_driver === 'mysql') {
            $stmt = $pdo->query("DESCRIBE $table");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                 echo "Field: {$row['Field']} | Type: {$row['Type']}<br>";
            }
        } else {
             $stmt = $pdo->query("PRAGMA table_info($table)");
             while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                 echo "Field: {$row['name']} | Type: {$row['type']}<br>";
            }
        }
        echo "<br><b>Indices:</b><br>";

        if ($db_driver === 'mysql') {
            $stmt = $pdo->query("SHOW INDEX FROM $table");
            echo "<table border='1'><tr><th>Key_name</th><th>Column_name</th><th>Non_unique</th></tr>";
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "<tr>";
                echo "<td>{$row['Key_name']}</td>";
                echo "<td>{$row['Column_name']}</td>";
                echo "<td>{$row['Non_unique']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            // SQLite
            $stmt = $pdo->query("PRAGMA index_list($table)");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo "Index: {$row['name']} (Unique: {$row['unique']})<br>";
                $info = $pdo->query("PRAGMA index_info({$row['name']})");
                while ($col = $info->fetch(PDO::FETCH_ASSOC)) {
                    echo "- Column: {$col['name']}<br>";
                }
            }
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
