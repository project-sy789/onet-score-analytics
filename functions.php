<?php
/**
 * Statistical Functions Library
 * Contains all mathematical calculations for student performance and item analysis
 */

/**
 * Detect CSV delimiter (Tab or Comma)
 */
function detectDelimiter($handle) {
    $first_line = fgets($handle);
    rewind($handle);
    return (strpos($first_line, "\t") !== false) ? "\t" : ",";
}

/**
 * Safe division with zero-check protection
 */
function safeDiv($numerator, $denominator, $default = 0) {
    return ($denominator != 0) ? ($numerator / $denominator) : $default;
}

/**
 * Sanitize CSV input
 */
function sanitizeCSV($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/**
 * Normalize Indicator Code
 * Handles variations like "ว 1.1", "ว.1.1" -> "ว1.1"
 * 1. Removes all whitespace
 * 2. Removes dots occurring immediately after Thai characters
 */
function normalizeIndicatorCode($code) {
    // 1. Remove all whitespace first
    $code = preg_replace('/\s+/u', '', $code);
    
    // 2. Remove dot after any Thai character to standardize
    $code = preg_replace('/([\x{0E01}-\x{0E5B}])\./u', '$1', $code);
    
    // 3. Format: Add Space for Subjects, Dot for Grade Levels
    $code = preg_replace_callback('/([\x{0E01}-\x{0E5B}])([0-9])/u', function($m) {
        $char = $m[1];
        $num = $m[2];
        // If Grade Level (ป or ม), add dot (e.g. ป.5)
        if ($char === 'ป' || $char === 'ม') {
            return $char . '.' . $num;
        }
        // Else (Subject codes like ว, ค), add space (e.g. ว 1.1)
        return $char . ' ' . $num;
    }, $code);
    
    // 4. Add space between Number and Thai char (e.g. 1.1ป.4 -> 1.1 ป.4)
    $code = preg_replace('/([0-9])([\x{0E01}-\x{0E5B}])/u', '$1 $2', $code);
    
    return $code;
}

/**
 * Calculate percentile from array of values
 */
function calculatePercentile($values, $percentile) {
    if (empty($values)) return 0;
    
    sort($values);
    $index = ($percentile / 100) * (count($values) - 1);
    $lower = floor($index);
    $upper = ceil($index);
    
    if ($lower == $upper) {
        return $values[$lower];
    }
    
    $fraction = $index - $lower;
    return $values[$lower] + ($fraction * ($values[$upper] - $values[$lower]));
}

// ============================================
// STUDENT PERFORMANCE FUNCTIONS
// ============================================

/**
 * Get student's score for a specific indicator
 * Returns percentage (0-100)
 * Now supports Many-to-Many: aggregates from all questions mapped to this indicator
 */
function getStudentScoreByIndicator($pdo, $student_id, $indicator_id) {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(s.score_obtained) as obtained,
            SUM(q.max_score) as max_possible
        FROM scores s
        INNER JOIN questions q ON s.question_number = q.question_number
        INNER JOIN question_indicators qi ON q.id = qi.question_id
        WHERE s.student_id = ? AND qi.indicator_id = ?
    ");
    $stmt->execute([$student_id, $indicator_id]);
    $result = $stmt->fetch();
    
    return safeDiv($result['obtained'], $result['max_possible'], 0) * 100;
}

/**
 * Get group statistics (mean and standard deviation)
 * Can filter by grade_level, room_number, or both
 */
/**
 * Get group statistics (mean and standard deviation)
 * Can filter by grade_level, room_number, or both
 * Updated to calculate Percentage based on (Obtained / MaxScore) * 100
 */
function getGroupStatistics($pdo, $grade_level = null, $room_number = null, $exam_set = null) {
    // Build query based on filters
    $where = [];
    $params = [];
    
    if ($grade_level) {
        $where[] = "st.grade_level = ?";
        $params[] = $grade_level;
    }
    
    if ($room_number) {
        $where[] = "st.room_number = ?";
        $params[] = $room_number;
    }
    
    // Filter by Exam Set (Important to prevent mixing P.6 and M.6 sums)
    if ($exam_set) {
        $where[] = "s.exam_set = ?";
        $params[] = $exam_set;
    }
    
    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    // Correct Logic: Sum Obtained / Sum Max
    $stmt = $pdo->prepare("
        SELECT 
            st.student_id,
            SUM(s.score_obtained) as total_score,
            SUM(q.max_score) as max_score
        FROM students st
        INNER JOIN scores s ON st.student_id = s.student_id
        INNER JOIN questions q ON s.question_number = q.question_number AND s.exam_set = q.exam_set
        $whereClause
        GROUP BY st.student_id
    ");
    $stmt->execute($params);
    $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($scores)) {
        return ['mean' => 0, 'sd' => 0, 'count' => 0];
    }
    
    // Calculate percentages
    $percentages = [];
    foreach ($scores as $score) {
        $max = $score['max_score'] > 0 ? $score['max_score'] : 1;
        $percentages[] = ($score['total_score'] / $max) * 100;
    }
    
    // Calculate mean
    $mean = array_sum($percentages) / count($percentages);
    
    // Calculate standard deviation
    $variance = 0;
    foreach ($percentages as $pct) {
        $variance += pow($pct - $mean, 2);
    }
    $sd = sqrt(safeDiv($variance, count($percentages), 0));
    
    return [
        'mean' => round($mean, 2),
        'sd' => round($sd, 2),
        'count' => count($scores)
    ];
}

/**
 * Segment students into 5 tiers based on percentile ranking
 * Configurable thresholds for flexible classification
 * 
 * @param PDO $pdo Database connection
 * @param string|null $grade_level Filter by grade
 * @param string|null $room_number Filter by room
 * @param array $thresholds Custom percentile thresholds [p80, p60, p40, p20]
 * @param string|null $exam_set Filter by exam set
 * @return array Segmented students with color-coded tiers
 */
function segmentStudents($pdo, $grade_level = null, $room_number = null, $thresholds = null, $exam_set = null) {
    // Load thresholds from settings file if not provided
    if ($thresholds === null) {
        $settings_file = __DIR__ . '/settings.json';
        if (file_exists($settings_file)) {
            $settings = json_decode(file_get_contents($settings_file), true);
            $thresholds = $settings['thresholds'] ?? null;
        }
    }
    
    // Load indicator pass threshold
    $indicator_pass_threshold = 50; // Default
    $settings_file = __DIR__ . '/settings.json';
    if (file_exists($settings_file)) {
        $settings = json_decode(file_get_contents($settings_file), true);
        $indicator_pass_threshold = $settings['indicator_pass_threshold'] ?? 50;
    }
    
    // Default percentile thresholds (statistically sound)
    if ($thresholds === null) {
        $thresholds = [
            'p80' => 80,  // Top 20% - Excellent
            'p60' => 60,  // Next 20% - Good
            'p40' => 40,  // Middle 20% - Average
            'p20' => 20   // Bottom 20% - Needs Help
        ];
    }
    
    
    // Build WHERE clause for student filters
    $student_where = [];
    if ($grade_level) {
        $student_where[] = "st.grade_level = ?";
    }
    if ($room_number) {
        $student_where[] = "st.room_number = ?";
    }
    $student_where_clause = !empty($student_where) ? "AND " . implode(" AND ", $student_where) : "";
    
    // Build SQL with actual threshold value
    // Determine Driver for SQL Compatibility (MySQL vs SQLite)
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $trim_fmt = ($driver === 'mysql') ? "TRIM(LEADING '0' FROM %s)" : "LTRIM(%s, '0')";
    
    // Prepare column headers
    $s2_sid = sprintf($trim_fmt, "s2.student_id");
    $s3_sid = sprintf($trim_fmt, "s3.student_id");
    $s_sid  = sprintf($trim_fmt, "s.student_id");
    $st_sid = sprintf($trim_fmt, "st.student_id");
    $ind_sid = sprintf($trim_fmt, "ind_agg.student_id");

    $sql = "
        SELECT 
            st.student_id,
            st.name,
             (SELECT SUM(s2.score_obtained)
              FROM scores s2
              INNER JOIN questions q2 ON s2.question_number = q2.question_number AND TRIM(s2.exam_set) = TRIM(q2.exam_set) AND q2.grade_level = st.grade_level
              WHERE $s2_sid = $st_sid
              " . ($exam_set ? "AND s2.exam_set = ?" : "") . ") as total_score,
             (SELECT SUM(q2.max_score)
              FROM scores s3
              INNER JOIN questions q2 ON s3.question_number = q2.question_number AND TRIM(s3.exam_set) = TRIM(q2.exam_set) AND q2.grade_level = st.grade_level
              WHERE $s3_sid = $st_sid
              " . ($exam_set ? "AND s3.exam_set = ?" : "") . ") as total_possible,
            COALESCE(ind_agg.total_ind, 0) as indicators_total,
            COALESCE(ind_agg.passed_ind, 0) as indicators_passed
        FROM students st
        LEFT JOIN (
            SELECT 
                agg.student_id,
                COUNT(DISTINCT agg.indicator_id) as total_ind,
                COUNT(DISTINCT CASE WHEN (agg.score / agg.max_score * 100) >= " . $indicator_pass_threshold . " THEN agg.indicator_id END) as passed_ind
            FROM (
                SELECT s.student_id, qi.indicator_id, SUM(s.score_obtained) as score, SUM(q.max_score) as max_score
                FROM scores s
                INNER JOIN students st_inner ON s.student_id = st_inner.student_id
                INNER JOIN questions q ON s.question_number = q.question_number AND TRIM(s.exam_set) = TRIM(q.exam_set) AND q.grade_level = st_inner.grade_level
                INNER JOIN question_indicators qi ON q.id = qi.question_id
                WHERE 1=1
                " . (!empty($exam_set) ? "AND s.exam_set = ?" : "") . "
                AND q.subject = ?
                GROUP BY s.student_id, qi.indicator_id
            ) agg
            GROUP BY agg.student_id
        ) ind_agg ON $st_sid = $ind_sid
        WHERE 1=1
        " . $student_where_clause . "
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // Build parameters for grade/room filters
    // Build parameters dynamically matching the SQL place-holders
    $exec_params = [];
    
    // 1. total_score subquery
    if ($exam_set) $exec_params[] = $exam_set;
    
    // 2. total_possible subquery
    if ($exam_set) $exec_params[] = $exam_set;
    
    // 3. indicators subquery
    if (!empty($exam_set)) $exec_params[] = $exam_set;

    // Main Query filters
    if ($grade_level) {
        $exec_params[] = $grade_level;
    }
    if ($room_number) {
        $exec_params[] = $room_number;
    }
    
    $stmt->execute($exec_params);
    $students = $stmt->fetchAll();
    
    if (empty($students)) {
        return [];
    }
    
    // Calculate percentages
    $scores = [];
    foreach ($students as $student) {
        $scores[] = safeDiv($student['total_score'], $student['total_possible'], 0) * 100;
    }
    
    // Determine grading mode (Percentile vs Fixed)
    $grading_mode = $settings['grading_mode'] ?? 'percentile';

    // Calculate cutoffs based on mode
    if ($grading_mode === 'fixed') {
        // Fixed Score Mode: Thresholds are raw scores (0-100)
        $p80 = $thresholds['p80'];
        $p60 = $thresholds['p60'];
        $p40 = $thresholds['p40'];
        $p20 = $thresholds['p20'];
    } else {
        // Percentile Mode: Thresholds are percentiles (e.g. Top 20%)
        $p80 = calculatePercentile($scores, $thresholds['p80']);
        $p60 = calculatePercentile($scores, $thresholds['p60']);
        $p40 = calculatePercentile($scores, $thresholds['p40']);
        $p20 = calculatePercentile($scores, $thresholds['p20']);
    }
    
    // Segment students into 5 tiers
    $segmented = [];
    foreach ($students as $student) {
        if ($student['total_score'] === null) {
            // Absent (Did not take exam)
            $segment = 'ขาดสอบ';
            $color = 'secondary';
            $rank = 6;
            $percentage = null; // Set to NULL for Absent
        } else {
            $percentage = safeDiv($student['total_score'], $student['total_possible'], 0) * 100;
            
            // Determine tier based on percentile ranking (using epsilon for float precision)
            $epsilon = 0.0001;
            
            if ($percentage >= $p80 - $epsilon) {
                $segment = 'ดีเยี่ยม';
                $color = 'purple';
                $rank = 1;
            } elseif ($percentage >= $p60 - $epsilon) {
                $segment = 'ดี';
                $color = 'success';
                $rank = 2;
            } elseif ($percentage >= $p40 - $epsilon) {
                $segment = 'ปานกลาง';
                $color = 'info';
                $rank = 3;
            } elseif ($percentage >= $p20 - $epsilon) {
                $segment = 'ต้องพัฒนา';
                $color = 'warning';
                $rank = 4;
            } else {
                $segment = 'ต้องช่วยเหลือเร่งด่วน';
                $color = 'danger';
                $rank = 5;
            }
        }
        
        $segmented[] = [
            'student_id' => $student['student_id'],
            'name' => $student['name'],
            'score' => ($percentage !== null) ? round($percentage, 2) : null,
            'raw_total' => $student['total_score'],
            'raw_possible' => $student['total_possible'],
            'segment' => $segment,
            'color' => $color,
            'rank' => $rank,
            'indicators_passed' => $student['indicators_passed'] ?? 0,
            'indicators_total' => $student['indicators_total'] ?? 0
        ];
    }
    
    // Sort by score (highest to lowest)
    usort($segmented, function($a, $b) {
        $scoreA = ($a['score'] === null) ? -1 : $a['score'];
        $scoreB = ($b['score'] === null) ? -1 : $b['score'];
        
        // Primary sort: score (descending)
        if ($scoreA != $scoreB) {
            return ($scoreA < $scoreB) ? 1 : -1;
        }
        // Secondary sort: student_id (ascending) for consistency
        return strcmp($a['student_id'], $b['student_id']);
    });
    
    return $segmented;
}

/**
 * Segment students by subject with subject-specific percentile thresholds
 * Allows different grading standards for each subject
 * 
 * @param PDO $pdo Database connection
 * @param string $subject Subject name (e.g., 'ภาษาไทย', 'คณิตศาสตร์')
 * @param string|null $grade_level Filter by grade
 * @param string|null $room_number Filter by room
 * @param array|null $thresholds Custom percentile thresholds for this subject
 * @param string|null $exam_set Filter by exam set
 * @return array Segmented students with color-coded tiers
 */
function segmentStudentsBySubject($pdo, $subject, $grade_level = null, $room_number = null, $thresholds = null, $exam_set = null) {
    // Load subject-specific thresholds from settings file if not provided
    if ($thresholds === null) {
        $settings_file = __DIR__ . '/settings.json';
        if (file_exists($settings_file)) {
            $settings = json_decode(file_get_contents($settings_file), true);
            // Try to get subject-specific thresholds (with priority to grade level)
            $key = $subject;
            if ($grade_level) {
                // Try Exact Match (Subject|Grade)
                $grade_key = $subject . '|' . $grade_level;
                if (isset($settings['subject_thresholds'][$grade_key])) {
                    $thresholds = $settings['subject_thresholds'][$grade_key];
                } elseif (isset($settings['subject_thresholds'][$subject])) {
                    // Fallback to Subject Default
                    $thresholds = $settings['subject_thresholds'][$subject];
                } else {
                    $thresholds = $settings['thresholds'] ?? null;
                }
            } else {
                // No grade provided, use Subject Default
                if (isset($settings['subject_thresholds'][$subject])) {
                    $thresholds = $settings['subject_thresholds'][$subject];
                } else {
                    $thresholds = $settings['thresholds'] ?? null;
                }
            }
        }
    }
    
    // Load indicator pass threshold (subject-specific or global)
    $indicator_pass_threshold = 50; // Default
    $settings_file = __DIR__ . '/settings.json';
    if (file_exists($settings_file)) {
        $settings = json_decode(file_get_contents($settings_file), true);
        // Use subject-specific threshold if available
        // Use subject-specific threshold if available (Check Specific Grade -> Subject Default -> Global)
        $lookup_key = $subject;
        if ($grade_level) {
            $lookup_key .= '|' . $grade_level;
        }
        
        if (isset($settings['subject_indicator_pass_thresholds'][$lookup_key])) {
            $indicator_pass_threshold = $settings['subject_indicator_pass_thresholds'][$lookup_key];
        } elseif (isset($settings['subject_indicator_pass_thresholds'][$subject])) {
            $indicator_pass_threshold = $settings['subject_indicator_pass_thresholds'][$subject];
        } else {
            $indicator_pass_threshold = $settings['indicator_pass_threshold'] ?? 50;
        }
    }
    
    // Default percentile thresholds
    if ($thresholds === null) {
        $thresholds = [
            'p80' => 80,
            'p60' => 60,
            'p40' => 40,
            'p20' => 20
        ];
    }
    
    // Build query based on filters
    $where = ["q.subject = ?"];
    $params = [$subject];
    
    if ($grade_level) {
        $where[] = "st.grade_level = ?";
        $params[] = $grade_level;
    }
    
    if ($room_number) {
        $where[] = "st.room_number = ?";
        $params[] = $room_number;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $where);
    
    // Get student scores for this subject only
    // Use subqueries to avoid duplicate counting from JOINs
    
    // Build WHERE clause for student filters
    $student_where = [];
    if ($grade_level) {
        $student_where[] = "st.grade_level = ?";
    }
    if ($room_number) {
        $student_where[] = "st.room_number = ?";
    }
    $student_where_clause = !empty($student_where) ? "AND " . implode(" AND ", $student_where) : "";
    
    // Build SQL with actual threshold value
    // Determine Driver for SQL Compatibility (MySQL vs SQLite)
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $trim_fmt = ($driver === 'mysql') ? "TRIM(LEADING '0' FROM %s)" : "LTRIM(%s, '0')";
    
    // Prepare column headers
    $trim_fmt = ($driver === 'mysql') ? "TRIM(LEADING '0' FROM %s)" : "LTRIM(%s, '0')";
    $st_sid_trim = sprintf($trim_fmt, "st.student_id");
    $sc_sid_trim = sprintf($trim_fmt, "sc_agg.student_id");
    $pos_sid_trim = sprintf($trim_fmt, "pos_agg.student_id");
    $ind_sid_trim = sprintf($trim_fmt, "ind_agg.student_id");

    $sql = "
        SELECT 
            st.student_id,
            st.name,
            sc_agg.total_score as total_score,
            pos_agg.total_possible as total_possible,
            COALESCE(ind_agg.total_ind, 0) as indicators_total,
            COALESCE(ind_agg.passed_ind, 0) as indicators_passed
        FROM students st
        
        -- 1. Total Score Join
        LEFT JOIN (
             SELECT s2.student_id, SUM(s2.score_obtained) as total_score
             FROM scores s2
             JOIN students st_inner ON s2.student_id = st_inner.student_id
             INNER JOIN questions q2 ON s2.question_number = q2.question_number AND TRIM(s2.exam_set) = TRIM(q2.exam_set) AND q2.grade_level = st_inner.grade_level
             WHERE q2.subject = ?
             " . ($exam_set ? "AND s2.exam_set = ?" : "") . "
             GROUP BY s2.student_id
        ) sc_agg ON $st_sid_trim = $sc_sid_trim
        
        -- 2. Total Possible Join (Theoretical Max)
        LEFT JOIN (
             SELECT st_inner.student_id, SUM(q2.max_score) as total_possible
             FROM students st_inner
             JOIN questions q2 ON q2.grade_level = st_inner.grade_level
             WHERE q2.subject = ?
             " . ($exam_set ? "AND TRIM(q2.exam_set) = TRIM(?)" : "") . "
             GROUP BY st_inner.student_id
        ) pos_agg ON $st_sid_trim = $pos_sid_trim
        
        -- 3. Indicators Join
        LEFT JOIN (
            SELECT 
                agg.student_id,
                COUNT(DISTINCT agg.indicator_id) as total_ind,
                COUNT(DISTINCT CASE WHEN (agg.score / agg.max_score * 100) >= " . $indicator_pass_threshold . " THEN agg.indicator_id END) as passed_ind
            FROM (
                SELECT s.student_id, qi.indicator_id, SUM(s.score_obtained) as score, SUM(q.max_score) as max_score
                FROM scores s
                JOIN students st_inner ON s.student_id = st_inner.student_id
                INNER JOIN questions q ON s.question_number = q.question_number AND TRIM(s.exam_set) = TRIM(q.exam_set) AND q.grade_level = st_inner.grade_level
                INNER JOIN question_indicators qi ON q.id = qi.question_id
                WHERE q.subject = ?
                " . ($exam_set ? "AND s.exam_set = ?" : "") . "
                GROUP BY s.student_id, qi.indicator_id
            ) agg
            GROUP BY agg.student_id
        ) ind_agg ON $st_sid_trim = $ind_sid_trim
        
        WHERE 1=1 " . $student_where_clause . "
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // Build parameters dynamically matching the SQL place-holders
    $exec_params = [];
    
    // 1. total_score join
    $exec_params[] = $subject;
    if ($exam_set) $exec_params[] = $exam_set;
    
    // 2. total_possible join
    $exec_params[] = $subject;
    if ($exam_set) $exec_params[] = $exam_set;
    
    // 3. indicators join
    $exec_params[] = $subject;
    if ($exam_set) $exec_params[] = $exam_set;
    
    // 4. exists subquery REMOVED
    // $exec_params[] = $subject;
    
    // 5. Main Query filters (Grade/Room)
    if ($grade_level) {
        $exec_params[] = $grade_level;
    }
    if ($room_number) {
        $exec_params[] = $room_number;
    }
    
    $stmt->execute($exec_params);
    $students = $stmt->fetchAll();
    
    if (empty($students)) {
        return [];
    }
    
    // Calculate percentages (exclude absent/null scores)
    $scores = [];
    foreach ($students as $student) {
        if ($student['total_score'] !== null) {
            $scores[] = safeDiv($student['total_score'], $student['total_possible'], 0) * 100;
        }
    }
    
    // Ensure settings are loaded to check global grading mode fallback
    if (!isset($settings)) {
         $settings_file = __DIR__ . '/settings.json';
         if (file_exists($settings_file)) {
             $settings = json_decode(file_get_contents($settings_file), true);
         }
    }
    
    // Determine grading mode (Subject override -> Global -> Default)
    $grading_mode = $thresholds['mode'] ?? $settings['grading_mode'] ?? 'percentile';

    // Calculate cutoffs based on mode
    if ($grading_mode === 'fixed') {
        // Fixed Score Mode
        $p80 = $thresholds['p80'];
        $p60 = $thresholds['p60'];
        $p40 = $thresholds['p40'];
        $p20 = $thresholds['p20'];
    } else {
        // Percentile Mode
        $p80 = calculatePercentile($scores, $thresholds['p80']);
        $p60 = calculatePercentile($scores, $thresholds['p60']);
        $p40 = calculatePercentile($scores, $thresholds['p40']);
        $p20 = calculatePercentile($scores, $thresholds['p20']);
    }
    
    // Segment students into 5 tiers
    $segmented = [];
    foreach ($students as $student) {
        if ($student['total_score'] === null) {
            // Absent
            $segment = 'ขาดสอบ';
            $color = 'secondary';
            $rank = 6;
            $percentage = null; // Set to NULL explicitly
        } else {
            // Calculate percentage for present students
            $percentage = safeDiv($student['total_score'], $student['total_possible'], 0) * 100;
            
            // Determine tier based on percentile ranking (using epsilon for float precision)
            $epsilon = 0.0001;
            
            if ($percentage >= $p80 - $epsilon) {
                $segment = 'ดีเยี่ยม';
                $color = 'purple';
                $rank = 1;
            } elseif ($percentage >= $p60 - $epsilon) {
                $segment = 'ดี';
                $color = 'success';
                $rank = 2;
            } elseif ($percentage >= $p40 - $epsilon) {
                $segment = 'ปานกลาง';
                $color = 'info';
                $rank = 3;
            } elseif ($percentage >= $p20 - $epsilon) {
                $segment = 'ต้องพัฒนา';
                $color = 'warning';
                $rank = 4;
            } else {
                $segment = 'ต้องช่วยเหลือเร่งด่วน';
                $color = 'danger';
                $rank = 5;
            }
        }
        
        $segmented[] = [
            'student_id' => $student['student_id'],
            'name' => $student['name'],
            'score' => ($percentage !== null) ? round($percentage, 2) : null,
            'raw_total' => $student['total_score'],
            'raw_possible' => $student['total_possible'],
            'segment' => $segment,
            'color' => $color,
            'rank' => $rank,
            'subject' => $subject,
            'indicators_passed' => $student['indicators_passed'] ?? 0,
            'indicators_total' => $student['indicators_total'] ?? 0
        ];
    }
    
    // Sort by score (highest to lowest)
    // Sort by score (highest to lowest)
    usort($segmented, function($a, $b) {
        $scoreA = ($a['score'] === null) ? -1 : $a['score'];
        $scoreB = ($b['score'] === null) ? -1 : $b['score'];
        
        // Primary sort: score (descending)
        if ($scoreA != $scoreB) {
            return ($scoreA < $scoreB) ? 1 : -1;
        }
        // Secondary sort: student_id (ascending) for consistency
        return strcmp($a['student_id'], $b['student_id']);
    });
    
    return $segmented;
}

/**
 * Get list of all subjects in the database
 */
function getAllSubjects($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT subject FROM indicators WHERE subject IS NOT NULL AND subject != '' ORDER BY subject");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ============================================
// ITEM ANALYSIS (PSYCHOMETRICS) FUNCTIONS
// ============================================

/**
 * Calculate Basic Statistics for a Question
 * Returns Min, Max, Mean, SD, CV
 */
function calculateQuestionStats($pdo, $question_number, $exam_set, $grade_level = null, $room_number = null) {
    // Build Query
    $where = ["s.question_number = ?", "s.exam_set = ?"];
    $params = [$question_number, $exam_set];
    
    $join = "";
    if ($grade_level) {
        $join = "JOIN students st ON s.student_id = st.student_id";
        $where[] = "st.grade_level = ?";
        $params[] = $grade_level;
    }
    
    // Add Room Filter
    if ($room_number) {
        if (strpos($join, 'students') === false) {
             $join = "JOIN students st ON s.student_id = st.student_id";
        }
        $where[] = "st.room_number = ?";
        $params[] = $room_number;
    }
    
    $whereStr = implode(" AND ", $where);
    
    // Fetch all scores to calculate SD in PHP (Compatible with SQLite)
    $sql = "SELECT s.score_obtained FROM scores s $join WHERE $whereStr";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $scores = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($scores)) {
        return ['min' => 0, 'max' => 0, 'mean' => 0, 'sd' => 0, 'cv' => 0];
    }
    
    $count = count($scores);
    $min = min($scores);
    $max = max($scores);
    $sum = array_sum($scores);
    $mean = $sum / $count;
    
    // Calculate SD (Sample SD: N-1)
    $sum_sq_diff = 0;
    foreach ($scores as $s) {
        $sum_sq_diff += pow($s - $mean, 2);
    }
    
    $sd = ($count > 1) ? sqrt($sum_sq_diff / ($count - 1)) : 0;
    
    // CV (%)
    $cv = ($mean > 0) ? ($sd / $mean) * 100 : 0;
    
    return [
        'min' => $min,
        'max' => $max,
        'mean' => $mean,
        'sd' => $sd,
        'cv' => $cv
    ];
}

/**
 * Calculate Overview Statistics for the Entire Exam Set
 * Returns Min, Max, Mean, SD, CV of Total Scores
 */
function calculateExamOverviewStats($pdo, $exam_set, $grade_level = null, $room_number = null, $subject = null) {
    // Build Query to get Total Score per Student
    $where = ["s.exam_set = ?"];
    $params = [$exam_set];
    
    $join = "";
    // If subject is provided, we need to join questions table to filter
    if ($subject) {
        // Use TRIM to match segmentStudentsBySubject robustness
        $join .= " JOIN questions q ON s.question_number = q.question_number AND TRIM(s.exam_set) = TRIM(q.exam_set)";
        $where[] = "q.subject = ?";
        $params[] = $subject;
        
        // Also filter questions by grade level if provided
        if ($grade_level) {
            $where[] = "q.grade_level = ?";
            $params[] = $grade_level;
        }
    }

    if ($grade_level || $room_number) {
        $join .= " JOIN students st ON s.student_id = st.student_id";
        
        if ($grade_level) {
            $where[] = "st.grade_level = ?";
            $params[] = $grade_level;
        }
        
        if ($room_number) {
            $where[] = "st.room_number = ?";
            $params[] = $room_number;
        }
    }
    
    $whereStr = implode(" AND ", $where);
    
    // Group by student_id to get total scores
    $sql = "SELECT SUM(s.score_obtained) as total_score 
            FROM scores s 
            $join 
            WHERE $whereStr 
            GROUP BY s.student_id";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $totals = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($totals)) {
        return ['min' => 0, 'max' => 0, 'mean' => 0, 'sd' => 0, 'cv' => 0, 'mean_percent' => 0, 'total_possible' => 0];
    }
    
    // Calculate Theoretical Max Possible Score for this Exam Set (filtered)
    $max_sql_params = [$exam_set];
    $max_sql_where = ["exam_set = ?"];
    if ($subject) {
        $max_sql_where[] = "subject = ?";
        $max_sql_params[] = $subject;
    }
    if ($grade_level) {
        $max_sql_where[] = "grade_level = ?";
        $max_sql_params[] = $grade_level;
    }
    
    $max_sql = "SELECT SUM(max_score) FROM questions WHERE " . implode(" AND ", $max_sql_where);
    $max_stmt = $pdo->prepare($max_sql);
    $max_stmt->execute($max_sql_params);
    $total_possible = $max_stmt->fetchColumn();
    
    $count = count($totals);
    $min = min($totals);
    $max = max($totals); // Max obtained
    $sum = array_sum($totals);
    $mean = $sum / $count;
    
    // Calculate SD
    $sum_sq_diff = 0;
    foreach ($totals as $v) {
        $sum_sq_diff += pow($v - $mean, 2);
    }
    
    $sd = ($count > 1) ? sqrt($sum_sq_diff / ($count - 1)) : 0;
    
    // CV (%)
    $cv = ($mean > 0) ? ($sd / $mean) * 100 : 0;
    
    // Mean Percent
    $mean_percent = ($total_possible > 0) ? ($mean / $total_possible) * 100 : 0;
    
    return [
        'min' => $min,
        'max' => $max,
        'mean' => $mean,
        'sd' => $sd,
        'cv' => $cv,
        'mean_percent' => $mean_percent,
        'total_possible' => $total_possible
    ];
}

/**
 * Get Score Distribution for Histogram (10 Bins)
 */
function getScoreDistribution($pdo, $exam_set, $grade_level = null, $room_number = null) {
    // Reuse query logic from OverviewStats but fetch all scores
    $where = ["s.exam_set = ?"];
    $params = [$exam_set];
    
    $join = "";
    if ($grade_level) {
        $join = "JOIN students st ON s.student_id = st.student_id";
        $where[] = "st.grade_level = ?";
        $params[] = $grade_level;
    }
    
    if ($room_number) {
        if (strpos($join, 'students') === false) {
             $join = "JOIN students st ON s.student_id = st.student_id";
        }
        $where[] = "st.room_number = ?";
        $params[] = $room_number;
    }
    
    $whereStr = implode(" AND ", $where);
    
    $sql = "SELECT SUM(s.score_obtained) as total_score 
            FROM scores s 
            $join 
            WHERE $whereStr 
            GROUP BY s.student_id
            ORDER BY total_score ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $scores = array_map('floatval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    
    if (empty($scores)) return ['labels' => [], 'data' => []];
    
    $min = floor(min($scores));
    $max = ceil(max($scores));
    
    // Auto-calculate bin width (approx 10 bins)
    $range = $max - $min;
    if ($range <= 0) $range = 1;
    
    $binCount = 10;
    $step = ceil($range / $binCount);
    if ($step < 1) $step = 1;
    
    // Adjust bin count based on step
    // e.g. Min 0, Max 10, Step 1 -> 10 bins.
    
    $bins = [];
    $labels = [];
    
    // Initialize bins
    $current = $min;
    // Safety break loop
    $maxLoops = 20; 
    $i = 0;
    while ($current <= $max && $i < $maxLoops) {
        $end = $current + $step;
        $label = "$current - " . ($end - 0.01); // Display Range
        // Use integer range display if step is integer
        if ($step >= 1 && floor($step) == $step) {
             $label = "$current - " . ($current + $step - 1); // e.g. 0-9
        }
        
        $labels[] = $label;
        $bins[] = 0;
        
        $current += $step;
        $i++;
    }
    
    // Populate bins
    foreach ($scores as $score) {
        $binIndex = floor(($score - $min) / $step);
        if ($binIndex >= count($bins)) $binIndex = count($bins) - 1; // Put max in last bin
        if ($binIndex < 0) $binIndex = 0;
        $bins[$binIndex]++;
    }
    
    return [
        'labels' => $labels,
        'data' => $bins
    ];
}

/**
 * Calculate Difficulty Index (p-value)
 * p = (Number of correct answers) / (Total students)
 */
function calculateDifficultyIndex($pdo, $question_number, $exam_set, $grade_level = null, $room_number = null) {
    // Get max_score for this question AND exam_set
    $max_sql = "SELECT max_score FROM questions WHERE question_number = ? AND exam_set = ?";
    $max_params = [$question_number, $exam_set];
    
    if ($grade_level) {
        $max_sql .= " AND grade_level = ?";
        $max_params[] = $grade_level;
    }
    
    $max_stmt = $pdo->prepare($max_sql);
    $max_stmt->execute($max_params);
    $max_score = $max_stmt->fetchColumn();
    
    if (!$max_score) return 0;
    
    // Build logic dynamically to support Room/Grade
    $sql = "SELECT AVG(s.score_obtained / ?) as avg_proportion FROM scores s";
    $params = [$max_score];
    $where = ["s.question_number = ?", "s.exam_set = ?"];
    $params[] = $question_number;
    $params[] = $exam_set;
    
    $join = "";
    
    // Check if we need to join students (if filtering by grade or room)
    if ($grade_level || $room_number) {
        $join = " JOIN students st ON s.student_id = st.student_id";
        
        if ($grade_level) {
            $where[] = "st.grade_level = ?";
            $params[] = $grade_level;
        }
        
        if ($room_number) {
            $where[] = "st.room_number = ?";
            $params[] = $room_number;
        }
    }
    
    $whereStr = implode(" AND ", $where);
    $sql .= $join . " WHERE " . $whereStr;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    
    // p-value: mean proportion of maximum score achieved
    // 1 = very easy, 0 = very difficult
    return floatval($result['avg_proportion'] ?? 0);
}

/**
 * Calculate Discrimination Index (r-value)
 * Uses top 27% and bottom 27% method
 */
function calculateDiscriminationIndex($pdo, $question_number, $exam_set, $grade_level = null, $room_number = null) {
    // Get max_score for this question AND exam_set
    $max_sql = "SELECT max_score FROM questions WHERE question_number = ? AND exam_set = ?";
    $max_params = [$question_number, $exam_set];
    if ($grade_level) {
        $max_sql .= " AND grade_level = ?";
        $max_params[] = $grade_level;
    }
    $max_stmt = $pdo->prepare($max_sql);
    $max_stmt->execute($max_params);
    $max_score = $max_stmt->fetchColumn();
    
    if (!$max_score) return 0;
    
    // Get all students with their total scores
    $sql_students = "
        SELECT 
            s.student_id,
            SUM(s.score_obtained) as total_score
        FROM scores s
    ";
    
    $params_students = [];
    $joins = [];
    $wheres = ["s.exam_set = ?"];
    $params_students[] = $exam_set;
    
    if ($grade_level || $room_number) {
        $joins[] = "JOIN students st ON s.student_id = st.student_id";
        
        if ($grade_level) {
            $wheres[] = "st.grade_level = ?";
            $params_students[] = $grade_level;
        }
        if ($room_number) {
            $wheres[] = "st.room_number = ?";
            $params_students[] = $room_number;
        }
    }
    
    if (!empty($joins)) {
        $sql_students .= " " . implode(" ", $joins) . " ";
    }
    
    $sql_students .= " WHERE " . implode(" AND ", $wheres);
    
    $sql_students .= " GROUP BY s.student_id ORDER BY total_score DESC ";
    
    $stmt = $pdo->prepare($sql_students);
    $stmt->execute($params_students);
    $students = $stmt->fetchAll();
    
    if (empty($students)) return 0;
    
    // Calculate 27% group size
    $total_students = count($students);
    $group_size = ceil($total_students * 0.27);
    
    if ($group_size == 0) return 0;
    
    // Get high group (top 27%)
    $high_group = array_slice($students, 0, $group_size);
    $high_ids = array_column($high_group, 'student_id');
    
    // Get low group (bottom 27%)
    $low_group = array_slice($students, -$group_size);
    $low_ids = array_column($low_group, 'student_id');
    
    // Helper to calculate group average
    $calculateGroupAvg = function($ids) use ($pdo, $max_score, $question_number, $exam_set) {
        if (empty($ids)) return 0;
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "
            SELECT AVG(score_obtained / ?) as avg_proportion
            FROM scores
            WHERE question_number = ? AND exam_set = ? AND student_id IN ($placeholders)
        ";
        $params = array_merge([$max_score, $question_number, $exam_set], $ids);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return floatval($stmt->fetchColumn());
    };
    
    $high_avg = $calculateGroupAvg($high_ids);
    $low_avg = $calculateGroupAvg($low_ids);
    
    return $high_avg - $low_avg;
}


/**
 * Interpret difficulty index value
 */
function interpretDifficulty($p) {
    if ($p < 0.2) {
        return 'ยากเกินไป'; // Too Hard
    } elseif ($p > 0.8) {
        return 'ง่ายเกินไป'; // Too Easy
    } else {
        return 'เหมาะสม'; // Appropriate
    }
}

/**
 * Interpret discrimination index value
 */
function interpretDiscrimination($r) {
    if ($r < 0) {
        return 'ไม่ดี (ต้องแก้ไข)'; // Bad (needs revision)
    } elseif ($r < 0.2) {
        return 'ต่ำ'; // Low
    } elseif ($r < 0.4) {
        return 'ปานกลาง'; // Moderate
    } else {
        return 'ดี'; // Good
    }
}

/**
 * Get all indicators for a student with scores
 * Updated for Many-to-Many relationship
 * @param PDO $pdo Database connection
 * @param string $student_id Student ID
 * @param string|null $subject Optional subject filter
 * @param string|null $grade_level Optional grade level filter
 */
function getStudentIndicators($pdo, $student_id, $subject = null, $grade_level = null, $exam_set = null) {
    $params = [$student_id];
    $where_clauses = [];
    
    if ($subject) {
        $where_clauses[] = "i.subject = ?";
        $params[] = $subject;
    }
    
    // Filter by grade level if provided
    if ($grade_level) {
        $where_clauses[] = "(i.grade_level = ? OR i.grade_level IS NULL)";
        $params[] = $grade_level;
    }

    // Filter by exam_set if provided
    if ($exam_set) {
        $where_clauses[] = "q.exam_set = ?";
        $params[] = $exam_set;
    }
    
    $where_clause = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
    
    $stmt = $pdo->prepare("
        SELECT 
            i.id,
            i.code,
            i.description,
            i.subject,
            i.grade_level,
            SUM(CASE 
                WHEN s.score_obtained = 1 AND q.max_score > 1 THEN q.max_score 
                ELSE s.score_obtained 
            END) as obtained,
            SUM(q.max_score) as total
        FROM indicators i
        INNER JOIN question_indicators qi ON i.id = qi.indicator_id
        INNER JOIN questions q ON qi.question_id = q.id
        LEFT JOIN scores s ON q.question_number = s.question_number AND q.exam_set = s.exam_set AND s.student_id = ?
        $where_clause
        GROUP BY i.id, i.code, i.description, i.subject, i.grade_level
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get indicator averages for a group
 * @param PDO $pdo Database connection
 * @param string|null $grade_level Filter by grade level
 * @param string|null $room_number Filter by room number
 * @param string|null $exam_set Filter by exam set
 */
function getIndicatorAverages($pdo, $grade_level = null, $room_number = null, $exam_set = null) {
    // Build query based on filters
    $where = [];
    $params = [];
    
    if ($grade_level) {
        $where[] = "st.grade_level = ?";
        $params[] = $grade_level;
    }
    
    if ($room_number) {
        $where[] = "st.room_number = ?";
        $params[] = $room_number;
    }
    
    // Filter by exam_set if provided
    if ($exam_set) {
        $where[] = "q.exam_set = ?";
        $params[] = $exam_set;
        $where[] = "s.exam_set = ?";
        $params[] = $exam_set;
    }
    
    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $stmt = $pdo->prepare("
        SELECT 
            i.id,
            i.code,
            SUM(s.score_obtained) as total_obtained,
            SUM(q.max_score) as total_max
        FROM indicators i
        INNER JOIN question_indicators qi ON i.id = qi.indicator_id
        INNER JOIN questions q ON qi.question_id = q.id
        INNER JOIN scores s ON q.question_number = s.question_number AND s.exam_set = q.exam_set
        INNER JOIN students st ON s.student_id = st.student_id
        $whereClause
        GROUP BY i.id, i.code
    ");
    $stmt->execute($params);
    
    $averages = [];
    while ($row = $stmt->fetch()) {
        $total_max = $row['total_max'];
        if ($total_max > 0) {
            $averages[$row['id']] = round(($row['total_obtained'] / $total_max) * 100, 2);
        } else {
            $averages[$row['id']] = 0;
        }
    }
    
    return $averages;
}

/**
 * Count failed indicators for each student (for quadrant analysis)
 * @param PDO $pdo Database connection
 * @param string|null $grade_level Filter by grade level
 * @param string|null $room_number Filter by room number
 * @param string|null $exam_set Filter by exam set
 * @param string|null $subject Filter by subject (for subject-specific threshold)
 */
function getStudentFailedIndicators($pdo, $grade_level = null, $room_number = null, $exam_set = null, $subject = null) {
    if ($subject) {
        // Use subject-specific logic
        // Correct order: $pdo, $subject, $grade, $room, $thresholds, $exam_set
        $data = segmentStudentsBySubject($pdo, $subject, $grade_level, $room_number, null, $exam_set);
    } else {
        // Use overview logic (pass null for thresholds)
        $data = segmentStudents($pdo, $grade_level, $room_number, null, $exam_set);
    }

    $results = [];
    foreach ($data as $row) {
        // Handle different key names from segmentStudents vs segmentStudentsBySubject
        // Handle different key names from segmentStudents vs segmentStudentsBySubject
        if (array_key_exists('score', $row)) {
            // segmentStudentsBySubject returns calculated percentage as 'score' (can be null)
            $percentage = $row['score'];
        } else {
            // segmentStudents returns raw 'total_score'
            if (isset($row['total_score']) && $row['total_score'] === null) {
                $percentage = null;
            } else {
                $total_sc = isset($row['total_score']) ? $row['total_score'] : 0;
                $total_poss = isset($row['total_possible']) ? $row['total_possible'] : 100;
                if ($total_poss == 0) $total_poss = 100;
                $percentage = ($total_sc / $total_poss) * 100;
            }
        }
        
        $ind_total = isset($row['indicators_total']) ? $row['indicators_total'] : 0;
        $ind_passed = isset($row['indicators_passed']) ? $row['indicators_passed'] : 0;
        $failed = $ind_total - $ind_passed;
        if ($failed < 0) $failed = 0;

        $results[] = [
            'student_id' => $row['student_id'],
            'name' => $row['name'],
            'total_score' => $percentage,
            'failed_indicators' => $failed
        ];
    }
    
    return $results;
}

/**
 * Get all students' performance for a specific indicator
 * @param PDO $pdo Database connection
 * @param int $indicator_id Indicator ID
 * @param string|null $grade_level Filter by grade level
 * @param string|null $room_number Filter by room number
 * @param string|null $exam_set Filter by exam set
 */
function getStudentsByIndicator($pdo, $indicator_id, $grade_level = null, $room_number = null, $exam_set = null) {
    // Build query filters
    $where = ["qi.indicator_id = ?"];
    $params = [$indicator_id];
    
    if ($grade_level) {
        $where[] = "st.grade_level = ?";
        $params[] = $grade_level;
    }
    
    if ($room_number) {
        $where[] = "st.room_number = ?";
        $params[] = $room_number;
    }
    
    // Filter by exam_set if provided
    if ($exam_set) {
        $where[] = "q.exam_set = ?";
        $params[] = $exam_set;
        // Also filter the score join implicitly by q.exam_set = s.exam_set
        // But for clarity/safety on duplicates:
        $where[] = "s.exam_set = ?";
        $params[] = $exam_set;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $where);
    
    // Unified Weighted Auto-Max Logic
    $sql = "
        SELECT 
            st.student_id,
            st.prefix,
            st.name,
            st.room_number,
            st.grade_level,
            SUM(CASE 
                WHEN s.score_obtained = 1 AND q.max_score > 1 THEN q.max_score 
                ELSE s.score_obtained 
            END) as obtained_score,
            SUM(q.max_score) as max_score
        FROM students st
        JOIN scores s ON st.student_id = s.student_id
        JOIN questions q ON s.question_number = q.question_number AND s.exam_set = q.exam_set
        JOIN question_indicators qi ON q.id = qi.question_id
        $whereClause
        GROUP BY st.student_id, st.prefix, st.name, st.room_number, st.grade_level
        ORDER BY st.room_number, st.student_id
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
