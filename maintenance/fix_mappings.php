<?php
/**
 * Fix Duplicate Mappings Script
 * Removes duplicate rows in question_indicators table
 * which cause scores to be double-counted (e.g. 100 -> 200).
 */
require_once __DIR__ . '/db.php';

echo "<h2>🔗 Duplicate Mappings Cleanup Tool</h2>";
echo "<p>Checking for questions that are linked to the SAME indicator multiple times...</p>";

// 1. Identify Duplicate Mappings
$sql = "
    SELECT question_id, indicator_id, COUNT(*) as count, GROUP_CONCAT(id) as ids 
    FROM question_indicators 
    GROUP BY question_id, indicator_id 
    HAVING count > 1
";
$stmt = $pdo->query($sql);
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    echo "<div style='color:green'>✅ No duplicate mappings found. (question_indicators table is clean).</div>";
} else {
    echo "<div style='color:red'>⚠️ Found " . count($duplicates) . " duplicate mappings!</div>";
    
    $deleted_count = 0;
    
    echo "<ul>";
    foreach ($duplicates as $dup) {
        $ids = explode(',', $dup['ids']);
        sort($ids); 
        
        // Keep the First ID (Original), Delete the rest
        $keep_id = array_shift($ids); 
        
        echo "<li>Q_ID: <strong>{$dup['question_id']}</strong> <-> Ind_ID: <strong>{$dup['indicator_id']}</strong> - Found IDs: $keep_id, " . implode(', ', $ids) . ". <br>Keeping ID: $keep_id, Deleting: " . implode(', ', $ids) . "</li>";
        
        foreach ($ids as $delete_id) {
            $del_stmt = $pdo->prepare("DELETE FROM question_indicators WHERE id = ?");
            $del_stmt->execute([$delete_id]);
            $deleted_count++;
        }
    }
    echo "</ul>";
    echo "<h3>🗑️ Deleted $deleted_count duplicate mapping rows.</h3>";
    echo "<p>Please Check your dashboard again. The Total Score should now be correct (100).</p>";
}
?>
