<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
requireRole("coach");

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

$coachId = (int)($_SESSION['user']['id'] ?? 0);
$clusterColumns = getColumns($conn, 'clusters');
$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$clusterOwnerColumn = in_array('coach_id', $clusterColumns, true) ? 'coach_id' : 'user_id';

$activeClusterRes = $conn->query("SELECT $clusterIdColumn AS id FROM clusters WHERE $clusterOwnerColumn=$coachId AND status='active' ORDER BY created_at DESC LIMIT 1");
$activeCluster = $activeClusterRes ? $activeClusterRes->fetch_assoc() : null;
if (!$activeCluster) {
    echo json_encode([]);
    exit;
}
$clusterId = (int)$activeCluster['id'];

$attendanceColumns = getColumns($conn, 'attendance_logs');
$timeLogColumns = getColumns($conn, 'time_logs');
$hasLegacy = in_array('id', $attendanceColumns, true)
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

if (!$hasLegacy && !$hasNewAttendance) {
    echo json_encode([]);
    exit;
}

$out = [];

if ($hasLegacy) {
    $res = $conn->query("SELECT id, cluster_id, employee_id, time_in_at, time_out_at, tag, note, updated_at FROM attendance_logs WHERE cluster_id=$clusterId AND employee_id=$coachId ORDER BY COALESCE(time_in_at, updated_at) DESC, id DESC");
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $out[] = $row;
    }
} else {
    $sql = "SELECT
            al.attendance_id AS id,
            al.cluster_id,
            al.employee_id,";

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
         FROM attendance_logs al";

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
         WHERE al.cluster_id = ?
           AND al.employee_id = ?
         ORDER BY COALESCE(al.attendance_date, al.updated_at) DESC, al.attendance_id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $clusterId, $coachId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $out[] = $row;
    }
}

echo json_encode($out);