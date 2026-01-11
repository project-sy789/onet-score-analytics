<?php
/**
 * Cleanup Duplicate Questions Script
 * Fixes the issue where Total Max Score is doubled (e.g. 200 instead of 100)
 */
require_once __DIR__ . '/../db.php';

echo "<h2>🧹 Duplicate Question Cleanup Tool</h2>";

// 1. Identify Duplicates
$sql = "
    SELECT exam_set, question_number, COUNT(*) as count, GROUP_CONCAT(id) as ids 
    FROM questions 
    GROUP BY exam_set, question_number 
    HAVING count > 1
";
$stmt = $pdo->query($sql);
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    echo "<div style='color:green'>✅ No duplicate questions found. Your data is clean.</div>";
} else {
    echo "<div style='color:red'>⚠️ Found " . count($duplicates) . " sets of duplicate questions!</div>";
    
    $deleted_count = 0;
    
    echo "<ul>";
    foreach ($duplicates as $dup) {
        $ids = explode(',', $dup['ids']);
        sort($ids); // Keep the first one (lowest ID), delete the rest? 
        // Or keep the LAST one (highest ID)? Usually imports are sequential. 
        // Let's keep the FIRST one imported (Lowest ID) to be safe, or Last?
        // If re-imported updates, maybe Last is better. 
        // But usually duplicates are identical.
        // Let's keep the one with the highest ID (latest import).
        
        $keep_id = array_pop($ids); // Keep the last one
        
        echo "<li>Set: <strong>{$dup['exam_set']}</strong>, Q: <strong>{$dup['question_number']}</strong> - Found IDs: " . implode(', ', $ids) . ", $keep_id. <br>Keeping ID: $keep_id, Deleting: " . implode(', ', $ids) . "</li>";
        
        foreach ($ids as $delete_id) {
            $del_stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
            $del_stmt->execute([$delete_id]);
            $deleted_count++;
            
            // Also clean up question_indicators mapping for the deleted question?
            // Yes, vital!
            $del_map_stmt = $pdo->prepare("DELETE FROM question_indicators WHERE question_id = ?");
            $del_map_stmt->execute([$delete_id]);
        }
    }
    echo "</ul>";
    echo "<h3>🗑️ Deleted $deleted_count duplicate question rows.</h3>";
    echo "<p>Please Check your dashboard again. The Total Score should now be correct (e.g. 100).</p>";
}
?>
