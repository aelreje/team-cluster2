<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
requireRole("employee");

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

function getClusterMemberEmployeeReference(mysqli $conn): ?string {
    $sql = "SELECT REFERENCED_TABLE_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cluster_members'
              AND COLUMN_NAME = 'employee_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1";

    $result = $conn->query($sql);
    if (!$result) {
        return null;
    }

    $row = $result->fetch_assoc();
    return $row['REFERENCED_TABLE_NAME'] ?? null;
}

$userColumns = getColumns($conn, 'users');
$employeeColumns = getColumns($conn, 'employees');
$clusterColumns = getColumns($conn, 'clusters');
$attendanceColumns = getColumns($conn, 'attendance_logs');
$timeLogColumns = getColumns($conn, 'time_logs');
$clusterMemberEmployeeReference = getClusterMemberEmployeeReference($conn);

$userIdColumn = in_array('id', $userColumns, true) ? 'id' : 'user_id';
$employeeIdColumn = in_array('employee_id', $employeeColumns, true) ? 'employee_id' : null;
$employeeUserIdColumn = in_array('user_id', $employeeColumns, true) ? 'user_id' : null;
$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';

$sessionUserId = (int)($_SESSION['user']['id'] ?? 0);
$memberEmployeeId = $sessionUserId;

if (
    $clusterMemberEmployeeReference === 'employees'
    && $employeeIdColumn
    && $employeeUserIdColumn
) {
    $employeeStmt = $conn->prepare(
        "SELECT $employeeIdColumn AS employee_id
         FROM employees
         WHERE $employeeUserIdColumn = ?
         LIMIT 1"
    );
    $employeeStmt->bind_param('i', $sessionUserId);
    $employeeStmt->execute();
    $employeeRes = $employeeStmt->get_result();

    if ($employeeRes && $employeeRes->num_rows > 0) {
        $memberEmployeeId = (int)$employeeRes->fetch_assoc()['employee_id'];
    }
}

$hasLegacyAttendance = in_array('id', $attendanceColumns, true)
    && in_array('time_in_at', $attendanceColumns, true)
    && in_array('time_out_at', $attendanceColumns, true)
    && in_array('tag', $attendanceColumns, true);

$hasNewAttendance = in_array('attendance_id', $attendanceColumns, true)
    && in_array('attendance_status', $attendanceColumns, true)
    && in_array('attendance_date', $attendanceColumns, true);

$timeLogPrimaryKey = in_array('time_log_id', $timeLogColumns, true)
    ? 'time_log_id'
    : (in_array('id', $timeLogColumns, true) ? 'id' : null);
$timeLogOrderColumn = $timeLogPrimaryKey
    ?? (in_array('updated_at', $timeLogColumns, true)
        ? 'updated_at'
        : (in_array('time_in', $timeLogColumns, true) ? 'time_in' : null));

$hasTimeLogs = $timeLogPrimaryKey
    && in_array('attendance_id', $timeLogColumns, true)
    && in_array('time_in', $timeLogColumns, true);

$hasTimeOut = in_array('time_out', $timeLogColumns, true);
$hasTimeTag = in_array('tag', $timeLogColumns, true);

$out = [];
if ($hasLegacyAttendance) {
    $stmt = $conn->prepare(
        "SELECT
            al.id,
            al.cluster_id,
            c.name AS cluster_name,
            al.time_in_at,
            al.time_out_at,
            al.tag,
            al.note,
            al.updated_at
         FROM attendance_logs al
         JOIN clusters c ON c.$clusterIdColumn = al.cluster_id
         WHERE al.employee_id = ?
         ORDER BY COALESCE(al.time_in_at, al.updated_at) DESC, al.id DESC"
    );
    $stmt->bind_param('i', $memberEmployeeId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
} elseif ($hasNewAttendance) {
    $sql = "SELECT
            al.attendance_id AS id,
            al.cluster_id,
            c.name AS cluster_name,";

    if ($hasTimeLogs) {
        $sql .= "
            tl.time_in AS time_in_at,
            " . ($hasTimeOut ? "tl.time_out" : "NULL") . " AS time_out_at,
            " . ($hasTimeTag ? "COALESCE(tl.tag, al.attendance_status)" : "al.attendance_status") . " AS tag,";
    } else {
        $sql .= "
            NULL AS time_in_at,
            NULL AS time_out_at,
            al.attendance_status AS tag,";
    }

    $sql .= "
            al.note,
            al.updated_at
         FROM attendance_logs al
         JOIN clusters c ON c.$clusterIdColumn = al.cluster_id";

    if ($hasTimeLogs) {
        $sql .= "
         LEFT JOIN time_logs tl
           ON tl.$timeLogPrimaryKey = (
               SELECT t2.$timeLogPrimaryKey
               FROM time_logs t2
               WHERE t2.attendance_id = al.attendance_id
               " . ($timeLogOrderColumn ? "ORDER BY t2.$timeLogOrderColumn DESC" : "") . "
               LIMIT 1
           )";
    }

    $sql .= "
         WHERE al.employee_id = ?
         ORDER BY COALESCE(al.attendance_date, al.updated_at) DESC, al.attendance_id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $memberEmployeeId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
}

echo json_encode($out);