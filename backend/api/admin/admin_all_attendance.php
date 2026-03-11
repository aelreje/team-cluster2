<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
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

$currentUserId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;

$clusterColumns = getColumns($conn, 'clusters');
$userColumns = getColumns($conn, 'users');
$attendanceColumns = getColumns($conn, 'attendance_logs');
$timeLogColumns = getColumns($conn, 'time_logs');

$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$userIdColumn = in_array('id', $userColumns, true) ? 'id' : 'user_id';
$attendanceUserColumn = in_array('employee_id', $attendanceColumns, true) ? 'employee_id' : (in_array('user_id', $attendanceColumns, true) ? 'user_id' : null);
$attendancePrimaryKey = in_array('attendance_id', $attendanceColumns, true) ? 'attendance_id' : (in_array('id', $attendanceColumns, true) ? 'id' : null);

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

if ($attendanceUserColumn === null || $attendancePrimaryKey === null) {
    echo json_encode([]);
    exit;
}

$escapedDate = "'" . $conn->real_escape_string($attendanceDate) . "'";
$attendanceSelect = 'NULL AS attendance_id, NULL AS time_in_at, NULL AS time_out_at, NULL AS attendance_tag, NULL AS attendance_note';
$attendanceJoin = '';

if ($hasLegacyAttendance) {
    $attendanceSelect = 'al.id AS attendance_id, al.time_in_at, al.time_out_at, al.tag AS attendance_tag, al.note AS attendance_note';
    $attendanceJoin = "LEFT JOIN attendance_logs al
        ON al.id = (
            SELECT al2.id
            FROM attendance_logs al2
            WHERE al2.$attendanceUserColumn = u.$userIdColumn
              AND DATE(COALESCE(al2.time_in_at, al2.updated_at)) = $escapedDate
            ORDER BY COALESCE(al2.time_in_at, al2.updated_at) DESC, al2.id DESC
            LIMIT 1
        )";
} elseif ($hasNewAttendance) {
    $attendanceTagExpr = $hasTimeLogs && $hasTimeLogTag ? 'COALESCE(tl.tag, al.attendance_status)' : 'al.attendance_status';
    $attendanceSelect = "al.attendance_id AS attendance_id, tl.time_in AS time_in_at, tl.time_out AS time_out_at, $attendanceTagExpr AS attendance_tag, al.note AS attendance_note";
    $attendanceJoin = "LEFT JOIN attendance_logs al
        ON al.attendance_id = (
            SELECT al2.attendance_id
            FROM attendance_logs al2
            WHERE al2.$attendanceUserColumn = u.$userIdColumn
              AND al2.attendance_date = $escapedDate
            ORDER BY al2.updated_at DESC, al2.attendance_id DESC
            LIMIT 1
        )";

    if ($hasTimeLogs && $timeLogPrimaryKey) {
        $attendanceJoin .= "\nLEFT JOIN time_logs tl
            ON tl.$timeLogPrimaryKey = (
                SELECT t2.$timeLogPrimaryKey
                FROM time_logs t2
                WHERE t2.attendance_id = al.attendance_id
                ORDER BY t2.$timeLogPrimaryKey DESC
                LIMIT 1
            )";
    } else {
        $attendanceSelect = "al.attendance_id AS attendance_id, NULL AS time_in_at, NULL AS time_out_at, $attendanceTagExpr AS attendance_tag, al.note AS attendance_note";
    }
}

$sql = "SELECT u.$userIdColumn AS user_id,
               COALESCE(NULLIF(CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name), ''), u.email) AS employee_name,
               c.name AS cluster_name,
               $attendanceSelect
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.$userIdColumn
        $attendanceJoin
        LEFT JOIN clusters c ON c.$clusterIdColumn = al.cluster_id
        WHERE u.$userIdColumn <> $currentUserId
          AND al.$attendancePrimaryKey IS NOT NULL
        ORDER BY employee_name ASC";

$res = $conn->query($sql);
if (!$res) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to load attendance records."]);
    exit;
}

$rows = [];
while ($row = $res->fetch_assoc()) {
    $row['user_id'] = (int)$row['user_id'];
    $row['attendance_id'] = isset($row['attendance_id']) ? (int)$row['attendance_id'] : null;
    $rows[] = $row;
}

echo json_encode($rows);