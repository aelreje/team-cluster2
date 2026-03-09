<?php
include "../config/database.php";
include "../config/auth.php";
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
$hasLegacy = in_array('id', $attendanceColumns, true) && in_array('time_in_at', $attendanceColumns, true);
if (!$hasLegacy) {
    echo json_encode([]);
    exit;
}

$res = $conn->query("SELECT id, cluster_id, employee_id, time_in_at, time_out_at, tag, note, updated_at FROM attendance_logs WHERE cluster_id=$clusterId AND employee_id=$coachId ORDER BY COALESCE(time_in_at, updated_at) DESC, id DESC");
$out = [];
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $out[] = $row;
}
echo json_encode($out);