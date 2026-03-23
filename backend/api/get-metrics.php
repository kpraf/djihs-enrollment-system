<?php
// ============================================================
// FILE: backend/api/get-metrics.php
// Purpose: Calculate DepEd Key Performance Metrics
// Updated: 2026-03-04 — Revised for normalized DB
//   - enrollment.AcademicYearID (FK int) replaces AcademicYear string
//   - section.AcademicYearID   (FK int) replaces AcademicYear string
//   - student.Age removed — derived from DateOfBirth at query time
//   - Removed SHOW COLUMNS check (column no longer exists)
//   - employee table queries wrapped in try/catch (not in current schema)
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();

    // ─── Resolve AcademicYearID from the ?sy= YearLabel parameter ──────────
    // The frontend passes a YearLabel string (e.g. "2025-2026") or "all".
    $syParam = isset($_GET['sy']) ? trim($_GET['sy']) : 'all';

    /**
     * Returns [AcademicYearID, YearLabel] for a given YearLabel string.
     * Throws if the label is not found.
     */
    $resolveYear = function (string $yearLabel) use ($conn): array {
        $stmt = $conn->prepare(
            "SELECT AcademicYearID, YearLabel FROM academicyear WHERE YearLabel = :yl LIMIT 1"
        );
        $stmt->bindValue(':yl', $yearLabel);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception("Academic year '$yearLabel' not found.");
        }
        return [(int) $row['AcademicYearID'], $row['YearLabel']];
    };

    // Resolve the requested year; fall back to active → latest when "all"
    if ($syParam !== 'all') {
        [$currentAYID, $currentSY] = $resolveYear($syParam);
    } else {
        // Find the active year, or the latest StartYear
        $stmt = $conn->prepare(
            "SELECT AcademicYearID, YearLabel FROM academicyear
             ORDER BY IsActive DESC, StartYear DESC LIMIT 1"
        );
        $stmt->execute();
        $ayRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ayRow) {
            throw new Exception('No academic year records found.');
        }
        $currentAYID = (int) $ayRow['AcademicYearID'];
        $currentSY   = $ayRow['YearLabel'];
    }

    // ─── Quick data-presence check ──────────────────────────────────────────
    $checkStmt = $conn->query("SELECT COUNT(*) FROM enrollment");
    if ((int) $checkStmt->fetchColumn() === 0) {
        echo json_encode([
            'success' => true,
            'message' => 'No enrollment data available. Please add enrollment records to see metrics.',
            'data'    => generateEmptyMetrics(),
        ]);
        exit;
    }

    // ─── Helper: resolve a previous year's AcademicYearID ───────────────────
    // Returns null when the previous year doesn't exist in the table yet.
    $getPreviousAYID = function (string $yearLabel, int $yearsBack = 1) use ($conn): ?int {
        $parts = explode('-', $yearLabel);
        if (count($parts) !== 2) return null;
        $prevLabel = ($parts[0] - $yearsBack) . '-' . ($parts[0] - $yearsBack + 1);
        $stmt = $conn->prepare(
            "SELECT AcademicYearID FROM academicyear WHERE YearLabel = :yl LIMIT 1"
        );
        $stmt->bindValue(':yl', $prevLabel);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['AcademicYearID'] : null;
    };

    $metrics = [];

    // ─── Shared base condition ───────────────────────────────────────────────
    // When "all" is selected we still scope to $currentAYID for per-SY metrics
    // (which return 0/N/A anyway). GER/NER/Promotion use this.
    $ayIDCurrent = $currentAYID;

    // ==========================================
    // 1. TOTAL ENROLLMENT for current scope
    // ==========================================
    // "all" → sum across every year; specific → filter by AcademicYearID
    if ($syParam === 'all') {
        $totalStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS total FROM enrollment
             WHERE Status IN ('Confirmed','Pending')"
        );
        $totalStmt->execute();
    } else {
        $totalStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS total FROM enrollment
             WHERE Status IN ('Confirmed','Pending')
               AND AcademicYearID = :ayID"
        );
        $totalStmt->bindValue(':ayID', $ayIDCurrent, PDO::PARAM_INT);
        $totalStmt->execute();
    }
    $totalEnrollment = (int) $totalStmt->fetchColumn();

    // ==========================================
    // 2. GROSS ENROLLMENT RATIO (GER) — Estimated
    // ==========================================
    $estimatedPopulation  = $totalEnrollment > 0 ? $totalEnrollment * 1.2 : 1;
    $metrics['ger']            = $totalEnrollment > 0
        ? round(($totalEnrollment / $estimatedPopulation) * 100, 2) : 0;
    $metrics['gerIsEstimated'] = true;

    // ==========================================
    // 3. NET ENROLLMENT RATIO (NER) — Estimated
    // ==========================================
    // Age is derived from DateOfBirth using TIMESTAMPDIFF (no stored Age column).
    if ($syParam === 'all') {
        $nerStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT e.StudentID) AS ageAppropriate
             FROM enrollment e
             INNER JOIN student   s  ON e.StudentID    = s.StudentID
             INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
             WHERE e.Status IN ('Confirmed','Pending')
               AND (
                   (gl.GradeLevelNumber = 7  AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 12 AND 13) OR
                   (gl.GradeLevelNumber = 8  AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 13 AND 14) OR
                   (gl.GradeLevelNumber = 9  AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 14 AND 15) OR
                   (gl.GradeLevelNumber = 10 AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 15 AND 16) OR
                   (gl.GradeLevelNumber = 11 AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 16 AND 17) OR
                   (gl.GradeLevelNumber = 12 AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 17 AND 18)
               )"
        );
        $nerStmt->execute();
    } else {
        $nerStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT e.StudentID) AS ageAppropriate
             FROM enrollment e
             INNER JOIN student    s  ON e.StudentID    = s.StudentID
             INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
             WHERE e.Status IN ('Confirmed','Pending')
               AND e.AcademicYearID = :ayID
               AND (
                   (gl.GradeLevelNumber = 7  AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 12 AND 13) OR
                   (gl.GradeLevelNumber = 8  AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 13 AND 14) OR
                   (gl.GradeLevelNumber = 9  AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 14 AND 15) OR
                   (gl.GradeLevelNumber = 10 AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 15 AND 16) OR
                   (gl.GradeLevelNumber = 11 AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 16 AND 17) OR
                   (gl.GradeLevelNumber = 12 AND TIMESTAMPDIFF(YEAR, s.DateOfBirth, CURDATE()) BETWEEN 17 AND 18)
               )"
        );
        $nerStmt->bindValue(':ayID', $ayIDCurrent, PDO::PARAM_INT);
        $nerStmt->execute();
    }
    $ageAppropriate       = (int) $nerStmt->fetchColumn();
    $metrics['ner']            = $totalEnrollment > 0
        ? round(($ageAppropriate / $totalEnrollment) * 100, 2) : 0;
    $metrics['nerIsEstimated'] = true;

    // ==========================================
    // 4. TRANSITION RATE (JHS → SHS)
    // ==========================================
    if ($syParam !== 'all') {
        $prevAYID = $getPreviousAYID($currentSY, 1);
        if ($prevAYID !== null) {
            $transStmt = $conn->prepare(
                "SELECT
                     (SELECT COUNT(DISTINCT e.StudentID)
                      FROM enrollment e
                      INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                      WHERE gl.GradeLevelNumber = 11
                        AND e.AcademicYearID = :currentAYID
                        AND e.Status IN ('Confirmed','Pending')) AS g11Current,
                     (SELECT COUNT(DISTINCT e.StudentID)
                      FROM enrollment e
                      INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                      WHERE gl.GradeLevelNumber = 10
                        AND e.AcademicYearID = :prevAYID
                        AND e.Status IN ('Confirmed','Pending')) AS g10Previous"
            );
            $transStmt->bindValue(':currentAYID', $ayIDCurrent, PDO::PARAM_INT);
            $transStmt->bindValue(':prevAYID',    $prevAYID,    PDO::PARAM_INT);
            $transStmt->execute();
            $transData = $transStmt->fetch(PDO::FETCH_ASSOC);

            $metrics['transitionRate'] = $transData['g10Previous'] > 0
                ? round(($transData['g11Current'] / $transData['g10Previous']) * 100, 2) : 0;
        } else {
            $metrics['transitionRate'] = 0;
        }
    } else {
        $metrics['transitionRate'] = 0;
    }

    // ==========================================
    // 5. COHORT SURVIVAL RATE (JHS: G7 → G10)
    // ==========================================
    if ($syParam !== 'all') {
        $threeYearsAgoAYID = $getPreviousAYID($currentSY, 3);
        if ($threeYearsAgoAYID !== null) {
            $csrStmt = $conn->prepare(
                "SELECT
                     (SELECT COUNT(DISTINCT e.StudentID)
                      FROM enrollment e
                      INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                      WHERE gl.GradeLevelNumber = 10
                        AND e.AcademicYearID = :currentAYID
                        AND e.Status IN ('Confirmed','Pending')) AS g10Current,
                     (SELECT COUNT(DISTINCT e.StudentID)
                      FROM enrollment e
                      INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
                      WHERE gl.GradeLevelNumber = 7
                        AND e.AcademicYearID = :threeYearsAgoAYID
                        AND e.Status IN ('Confirmed','Pending')) AS g7Previous"
            );
            $csrStmt->bindValue(':currentAYID',       $ayIDCurrent,       PDO::PARAM_INT);
            $csrStmt->bindValue(':threeYearsAgoAYID', $threeYearsAgoAYID, PDO::PARAM_INT);
            $csrStmt->execute();
            $csrData = $csrStmt->fetch(PDO::FETCH_ASSOC);

            $metrics['cohortSurvivalRateJHS'] = $csrData['g7Previous'] > 0
                ? round(($csrData['g10Current'] / $csrData['g7Previous']) * 100, 2) : 0;
        } else {
            $metrics['cohortSurvivalRateJHS'] = 0;
        }
    } else {
        $metrics['cohortSurvivalRateJHS'] = 0;
    }

    // ==========================================
    // 6. PROMOTION RATE
    // ==========================================
    if ($syParam === 'all') {
        $promStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS confirmed FROM enrollment WHERE Status = 'Confirmed'"
        );
        $promStmt->execute();
    } else {
        $promStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS confirmed FROM enrollment
             WHERE Status = 'Confirmed' AND AcademicYearID = :ayID"
        );
        $promStmt->bindValue(':ayID', $ayIDCurrent, PDO::PARAM_INT);
        $promStmt->execute();
    }
    $confirmed = (int) $promStmt->fetchColumn();
    $metrics['promotionRate'] = $totalEnrollment > 0
        ? round(($confirmed / $totalEnrollment) * 100, 2) : 0;

    // ==========================================
    // 7. RETENTION RATE
    // ==========================================
    if ($syParam !== 'all') {
        $prevAYID = $prevAYID ?? $getPreviousAYID($currentSY, 1);
        if ($prevAYID !== null) {
            $retStmt = $conn->prepare(
                "SELECT COUNT(DISTINCT e1.StudentID) AS retained
                 FROM enrollment e1
                 INNER JOIN enrollment e2 ON e1.StudentID = e2.StudentID
                 WHERE e1.AcademicYearID = :currentAYID
                   AND e2.AcademicYearID = :prevAYID
                   AND e1.Status IN ('Confirmed','Pending')
                   AND e2.Status IN ('Confirmed','Pending')"
            );
            $retStmt->bindValue(':currentAYID', $ayIDCurrent, PDO::PARAM_INT);
            $retStmt->bindValue(':prevAYID',    $prevAYID,    PDO::PARAM_INT);
            $retStmt->execute();
            $retained = (int) $retStmt->fetchColumn();

            $prevTotalStmt = $conn->prepare(
                "SELECT COUNT(DISTINCT StudentID) AS total FROM enrollment
                 WHERE AcademicYearID = :prevAYID AND Status IN ('Confirmed','Pending')"
            );
            $prevTotalStmt->bindValue(':prevAYID', $prevAYID, PDO::PARAM_INT);
            $prevTotalStmt->execute();
            $prevTotal = (int) $prevTotalStmt->fetchColumn();

            $metrics['retentionRate'] = $prevTotal > 0
                ? round(($retained / $prevTotal) * 100, 2) : 0;
        } else {
            $metrics['retentionRate'] = 0;
        }
    } else {
        $metrics['retentionRate'] = 0;
    }

    // ==========================================
    // 8. DROPOUT / SCHOOL LEAVER RATE
    // ==========================================
    if ($syParam === 'all') {
        $dropStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS dropped FROM enrollment
             WHERE Status IN ('Dropped','Transferred_Out')"
        );
        $dropStmt->execute();
    } else {
        $dropStmt = $conn->prepare(
            "SELECT COUNT(DISTINCT StudentID) AS dropped FROM enrollment
             WHERE Status IN ('Dropped','Transferred_Out') AND AcademicYearID = :ayID"
        );
        $dropStmt->bindValue(':ayID', $ayIDCurrent, PDO::PARAM_INT);
        $dropStmt->execute();
    }
    $dropped            = (int) $dropStmt->fetchColumn();
    $totalForDropout    = $totalEnrollment + $dropped;
    $metrics['dropoutRate'] = $totalForDropout > 0
        ? round(($dropped / $totalForDropout) * 100, 2) : 0;

    // ==========================================
    // 9. COEFFICIENT OF EFFICIENCY (Estimated)
    // ==========================================
    $metrics['coefficientOfEfficiency'] = $metrics['promotionRate'];
    $metrics['coeIsEstimated']          = true;

    // ==========================================
    // 10. COMPLETION RATE (Estimated via CSR)
    // ==========================================
    $metrics['completionRate']        = $metrics['cohortSurvivalRateJHS'];
    $metrics['completionIsEstimated'] = ($syParam === 'all');

    // ==========================================
    // 11. STUDENT-TEACHER RATIO
    // ==========================================
    // employee table is not in the current DB dump; wrapped in try/catch.
    $totalTeachers = 0;
    try {
        $tchStmt = $conn->prepare(
            "SELECT COUNT(*) FROM employee
             WHERE EmploymentType = 'Teaching'
               AND EmploymentStatus = 'Active'
               AND IsActive = 1"
        );
        $tchStmt->execute();
        $totalTeachers = (int) $tchStmt->fetchColumn();
    } catch (Exception $e) {
        // employee table not yet available
    }
    $metrics['studentTeacherRatio'] = $totalTeachers > 0
        ? round($totalEnrollment / $totalTeachers, 1) : 0;

    // ==========================================
    // 12. STUDENT-CLASSROOM & SECTION UTILIZATION
    // ==========================================
    // section table uses AcademicYearID (FK int).
    if ($syParam !== 'all') {
        $secStmt = $conn->prepare(
            "SELECT COUNT(*) AS totalSections,
                    COALESCE(SUM(Capacity), 0) AS totalCapacity
             FROM section
             WHERE IsActive = 1 AND AcademicYearID = :ayID"
        );
        $secStmt->bindValue(':ayID', $ayIDCurrent, PDO::PARAM_INT);
        $secStmt->execute();
    } else {
        $secStmt = $conn->prepare(
            "SELECT COUNT(*) AS totalSections,
                    COALESCE(SUM(Capacity), 0) AS totalCapacity
             FROM section WHERE IsActive = 1"
        );
        $secStmt->execute();
    }
    $secData = $secStmt->fetch(PDO::FETCH_ASSOC);

    $metrics['studentClassroomRatio'] = $secData['totalSections'] > 0
        ? round($totalEnrollment / $secData['totalSections'], 1) : 0;
    $metrics['sectionUtilization']    = $secData['totalCapacity'] > 0
        ? round(($totalEnrollment / $secData['totalCapacity']) * 100, 2) : 0;

    // ==========================================
    // 13. GRADE-LEVEL BREAKDOWN
    // ==========================================
    if ($syParam !== 'all') {
        $glStmt = $conn->prepare(
            "SELECT
                 gl.GradeLevelNumber AS gradeLevel,
                 COUNT(DISTINCT e.StudentID) AS enrollment,
                 SUM(CASE WHEN e.Status = 'Confirmed' THEN 1 ELSE 0 END) AS promoted,
                 SUM(CASE WHEN e.Status IN ('Dropped','Transferred_Out') THEN 1 ELSE 0 END) AS dropped
             FROM enrollment e
             INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
             WHERE e.AcademicYearID = :ayID
             GROUP BY gl.GradeLevelID, gl.GradeLevelNumber
             ORDER BY gl.GradeLevelNumber"
        );
        $glStmt->bindValue(':ayID', $ayIDCurrent, PDO::PARAM_INT);
        $glStmt->execute();
    } else {
        $glStmt = $conn->prepare(
            "SELECT
                 gl.GradeLevelNumber AS gradeLevel,
                 COUNT(DISTINCT e.StudentID) AS enrollment,
                 SUM(CASE WHEN e.Status = 'Confirmed' THEN 1 ELSE 0 END) AS promoted,
                 SUM(CASE WHEN e.Status IN ('Dropped','Transferred_Out') THEN 1 ELSE 0 END) AS dropped
             FROM enrollment e
             INNER JOIN gradelevel gl ON e.GradeLevelID = gl.GradeLevelID
             GROUP BY gl.GradeLevelID, gl.GradeLevelNumber
             ORDER BY gl.GradeLevelNumber"
        );
        $glStmt->execute();
    }

    $gradeLevelData = [];
    while ($row = $glStmt->fetch(PDO::FETCH_ASSOC)) {
        $enr        = (int) $row['enrollment'];
        $prom       = (int) $row['promoted'];
        $drop       = (int) $row['dropped'];
        $total      = $enr + $drop;
        $gradeLevelData[] = [
            'gradeLevel'    => (int) $row['gradeLevel'],
            'enrollment'    => $enr,
            'promotionRate' => $enr   > 0 ? round(($prom / $enr)   * 100, 2) : 0,
            'retentionRate' => $total > 0 ? round(($enr  / $total)  * 100, 2) : 0,
            'dropoutRate'   => $total > 0 ? round(($drop / $total)  * 100, 2) : 0,
        ];
    }
    $metrics['gradeLevelData'] = $gradeLevelData;

    // ==========================================
    // 14. TRENDS (year-over-year, last 5 years)
    // ==========================================
    // Join with academicyear to get the YearLabel for display.
    $trendsStmt = $conn->prepare(
        "SELECT
             ay.YearLabel AS year,
             COUNT(DISTINCT e.StudentID) AS enrollment,
             SUM(CASE WHEN e.Status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed,
             SUM(CASE WHEN e.Status IN ('Dropped','Transferred_Out') THEN 1 ELSE 0 END) AS dropped
         FROM enrollment e
         INNER JOIN academicyear ay ON e.AcademicYearID = ay.AcademicYearID
         GROUP BY ay.AcademicYearID, ay.YearLabel, ay.StartYear
         ORDER BY ay.StartYear DESC
         LIMIT 5"
    );
    $trendsStmt->execute();

    $trends = [];
    while ($row = $trendsStmt->fetch(PDO::FETCH_ASSOC)) {
        $enr    = (int) $row['enrollment'];
        $conf   = (int) $row['confirmed'];
        $drop   = (int) $row['dropped'];
        $total  = $enr + $drop;
        $estPop = $enr > 0 ? $enr * 1.2 : 1;

        $trends[] = [
            'year'           => $row['year'],
            'enrollmentRate' => round(($enr  / $estPop)  * 100, 2),  // estimated
            'promotionRate'  => $enr   > 0 ? round(($conf / $enr)   * 100, 2) : 0,
            'retentionRate'  => $total > 0 ? round(($enr  / $total)  * 100, 2) : 0,
            'dropoutRate'    => $total > 0 ? round(($drop / $total)  * 100, 2) : 0,
        ];
    }
    $metrics['trends'] = array_reverse($trends);

    echo json_encode(['success' => true, 'data' => $metrics]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'debug'   => ['file' => $e->getFile(), 'line' => $e->getLine()],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

// ─── Helper ────────────────────────────────────────────────────────────────
function generateEmptyMetrics(): array {
    return [
        'ger'                     => 0,
        'gerIsEstimated'          => true,
        'ner'                     => 0,
        'nerIsEstimated'          => true,
        'transitionRate'          => 0,
        'cohortSurvivalRateJHS'   => 0,
        'promotionRate'           => 0,
        'retentionRate'           => 0,
        'dropoutRate'             => 0,
        'coefficientOfEfficiency' => 0,
        'coeIsEstimated'          => true,
        'completionRate'          => 0,
        'completionIsEstimated'   => true,
        'studentTeacherRatio'     => 0,
        'studentClassroomRatio'   => 0,
        'sectionUtilization'      => 0,
        'gradeLevelData'          => [],
        'trends'                  => [],
    ];
}
?>