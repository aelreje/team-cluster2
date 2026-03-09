<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("admin");

function getColumns(mysqli $conn, string $table): array {
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

$attendanceDate = isset($_GET['attendance_date']) ? trim((string)$_GET['attendance_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate)) {
    $attendanceDate = date('Y-m-d');
}

$clusterColumns = getColumns($conn, 'clusters');
$userColumns = getColumns($conn, 'users');
$attendanceColumns = getColumns($conn, 'attendance_logs');
$timeLogColumns = getColumns($conn, 'time_logs');

$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$clusterOwnerColumn = in_array('coach_id', $clusterColumns, true) ? 'coach_id' : 'user_id';
$userIdColumn = in_array('id', $userColumns, true) ? 'id' : 'user_id';
$userRoleColumn = in_array('role', $userColumns, true) ? 'role' : (in_array('role_id', $userColumns, true) ? 'role_id' : null);

$hasLegacyAttendance = in_array('id', $attendanceColumns, true)
    && in_array('time_in_at', $attendanceColumns, true)
    && in_array('time_out_at', $attendanceColumns, true)
    && in_array('tag', $attendanceColumns, true);
$hasNewAttendance = in_array('attendance_id', $attendanceColumns, true)
    && in_array('attendance_status', $attendanceColumns, true)
    && in_array('attendance_date', $attendanceColumns, true);

$hasTimeLogs = in_array('attendance_id', $timeLogColumns, true)
    && in_array('time_in', $timeLogColumns, true);
$hasTimeLogTag = in_array('tag', $timeLogColumns, true);
$timeLogPrimaryKey = in_array('time_log_id', $timeLogColumns, true) ? 'time_log_id' : (in_array('id', $timeLogColumns, true) ? 'id' : null);

$coachFilter = '';
if ($userRoleColumn === 'role') {
    $coachFilter = "LOWER(u.role) LIKE '%coach%'";
} elseif ($userRoleColumn === 'role_id') {
    $coachFilter = "EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.role_id AND LOWER(r.role_name) LIKE '%coach%')";
}
if ($coachFilter === '') {
    $coachFilter = '1=1';
}

$escapedDate = "'" . $conn->real_escape_string($attendanceDate) . "'";
$attendanceJoin = 'NULL AS attendance_id, NULL AS time_in_at, NULL AS time_out_at, NULL AS attendance_tag, NULL AS attendance_note';
$attendanceLeftJoin = '';

if ($hasLegacyAttendance) {
    $attendanceJoin = 'al.id AS attendance_id, al.time_in_at, al.time_out_at, al.tag AS attendance_tag, al.note AS attendance_note';
    $attendanceLeftJoin = "LEFT JOIN attendance_logs al
        ON al.id = (
            SELECT al2.id
            FROM attendance_logs al2
            WHERE al2.cluster_id = c.$clusterIdColumn
              AND al2.employee_id = u.$userIdColumn
              AND DATE(COALESCE(al2.time_in_at, al2.updated_at)) = $escapedDate
            ORDER BY COALESCE(al2.time_in_at, al2.updated_at) DESC, al2.id DESC
            LIMIT 1
        )";
} elseif ($hasNewAttendance) {
    $attendanceTagExpr = $hasTimeLogs && $hasTimeLogTag ? 'COALESCE(tl.tag, al.attendance_status)' : 'al.attendance_status';
    $attendanceJoin = "al.attendance_id AS attendance_id, tl.time_in AS time_in_at, tl.time_out AS time_out_at, $attendanceTagExpr AS attendance_tag, al.note AS attendance_note";
    $attendanceLeftJoin = "LEFT JOIN attendance_logs al
        ON al.attendance_id = (
            SELECT al2.attendance_id
            FROM attendance_logs al2
            WHERE al2.cluster_id = c.$clusterIdColumn
              AND al2.employee_id = u.$userIdColumn
              AND al2.attendance_date = $escapedDate
            ORDER BY al2.updated_at DESC, al2.attendance_id DESC
            LIMIT 1
        )";
    if ($hasTimeLogs && $timeLogPrimaryKey) {
        $attendanceLeftJoin .= "\nLEFT JOIN time_logs tl
            ON tl.$timeLogPrimaryKey = (
                SELECT t2.$timeLogPrimaryKey
                FROM time_logs t2
                WHERE t2.attendance_id = al.attendance_id
                ORDER BY t2.$timeLogPrimaryKey DESC
                LIMIT 1
            )";
    } else {
        $attendanceJoin = "al.attendance_id AS attendance_id, NULL AS time_in_at, NULL AS time_out_at, $attendanceTagExpr AS attendance_tag, al.note AS attendance_note";
    }
}

$sql = "SELECT c.$clusterIdColumn AS cluster_id,
               c.name AS cluster_name,
               u.$userIdColumn AS coach_id,
               COALESCE(NULLIF(CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name), ''), u.email) AS coach_name,
               $attendanceJoin
        FROM clusters c
        JOIN users u ON u.$userIdColumn = c.$clusterOwnerColumn
        LEFT JOIN employees e ON e.user_id = u.$userIdColumn
        $attendanceLeftJoin
        WHERE $coachFilter
        ORDER BY coach_name ASC";

$res = $conn->query($sql);
if (!$res) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to load coach attendance."]);
    exit;
}

$rows = [];
while ($row = $res->fetch_assoc()) {
    $row['cluster_id'] = (int)$row['cluster_id'];
    $row['coach_id'] = (int)$row['coach_id'];
    $row['attendance_id'] = isset($row['attendance_id']) ? (int)$row['attendance_id'] : null;
    $rows[] = $row;
}

echo json_encode($rows);