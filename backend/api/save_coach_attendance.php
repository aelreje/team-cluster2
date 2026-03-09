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

$data = json_decode(file_get_contents("php://input"), true);
$clusterId = isset($data['cluster_id']) ? (int)$data['cluster_id'] : 0;
$coachId = (int)($_SESSION['user']['id'] ?? 0);
$timeInAt = $data['timeInAt'] ?? null;
$timeOutAt = $data['timeOutAt'] ?? null;
$tag = $data['tag'] ?? null;
$note = $data['note'] ?? "";

if ($clusterId <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid cluster id"]);
    exit;
}

$clusterColumns = getColumns($conn, 'clusters');
$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$clusterOwnerColumn = in_array('coach_id', $clusterColumns, true) ? 'coach_id' : 'user_id';

$clusterCheck = $conn->query("SELECT 1 FROM clusters WHERE $clusterIdColumn=$clusterId AND $clusterOwnerColumn=$coachId LIMIT 1");
if (!$clusterCheck || $clusterCheck->num_rows === 0) {
    http_response_code(403);
    echo json_encode(["error" => "You can only log attendance for your cluster."]);
    exit;
}

$attendanceColumns = getColumns($conn, 'attendance_logs');
$hasLegacy = in_array('id', $attendanceColumns, true) && in_array('time_in_at', $attendanceColumns, true);

$timeInSql = $timeInAt ? date("Y-m-d H:i:s", strtotime($timeInAt)) : null;
$timeOutSql = $timeOutAt ? date("Y-m-d H:i:s", strtotime($timeOutAt)) : null;
$timeInValue = $timeInSql ? "'" . $conn->real_escape_string($timeInSql) . "'" : "NULL";
$timeOutValue = $timeOutSql ? "'" . $conn->real_escape_string($timeOutSql) . "'" : "NULL";
$tagValue = $tag ? "'" . $conn->real_escape_string($tag) . "'" : "NULL";
$noteValue = "'" . $conn->real_escape_string((string)$note) . "'";

if ($hasLegacy) {
    if ($timeOutSql) {
        $lookup = $conn->query("SELECT id FROM attendance_logs WHERE cluster_id=$clusterId AND employee_id=$coachId AND time_out_at IS NULL ORDER BY COALESCE(time_in_at, updated_at) DESC, id DESC LIMIT 1");
        if ($lookup && $lookup->num_rows > 0) {
            $attendanceId = (int)$lookup->fetch_assoc()['id'];
            $conn->query("UPDATE attendance_logs SET time_out_at=$timeOutValue, tag=$tagValue, note=$noteValue WHERE id=$attendanceId");
        }
    } else {
        $conn->query("INSERT INTO attendance_logs (cluster_id, employee_id, time_in_at, time_out_at, tag, note) VALUES ($clusterId, $coachId, $timeInValue, NULL, $tagValue, $noteValue)");
    }

    $current = $conn->query("SELECT id, time_in_at, time_out_at, tag FROM attendance_logs WHERE cluster_id=$clusterId AND employee_id=$coachId ORDER BY COALESCE(time_in_at, updated_at) DESC, id DESC LIMIT 1")->fetch_assoc();
    echo json_encode(["success" => true, "attendance" => ["id" => (int)($current['id'] ?? 0), "timeInAt" => $current['time_in_at'] ?? null, "timeOutAt" => $current['time_out_at'] ?? null, "tag" => $current['tag'] ?? null]]);
    exit;
}

http_response_code(400);
echo json_encode(["error" => "Unsupported attendance schema for coach attendance."]);