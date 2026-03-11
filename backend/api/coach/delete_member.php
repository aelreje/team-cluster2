<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
requireRole("coach");

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);
$cluster_id = (int)($data['cluster_id'] ?? 0);
$employee_id = (int)($data['employee_id'] ?? 0);
$coach_id = (int)$_SESSION['user']['id'];

$clusterColumns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM clusters");
if ($columnResult) {
    while ($row = $columnResult->fetch_assoc()) {
        $clusterColumns[] = $row['Field'];
    }
}

$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$ownerColumn = in_array('coach_id', $clusterColumns, true) ? 'coach_id' : 'user_id';

if ($cluster_id === 0 || $employee_id === 0) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid member request."]);
    exit;
}

$clusterCheckStmt = $conn->prepare(
    "SELECT $clusterIdColumn FROM clusters WHERE $clusterIdColumn=? AND $ownerColumn=? LIMIT 1"
);

if (!$clusterCheckStmt) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to validate cluster ownership."]);
    exit;
}

$clusterCheckStmt->bind_param("ii", $cluster_id, $coach_id);
$clusterCheckStmt->execute();
$cluster_check = $clusterCheckStmt->get_result();

if ($cluster_check->num_rows === 0) {
    http_response_code(403);
    echo json_encode(["error" => "Not authorized to update this cluster."]);
    exit;
}

$deleteSchedulesStmt = $conn->prepare(
    "DELETE FROM schedules WHERE cluster_id=? AND employee_id=?"
);

if ($deleteSchedulesStmt) {
    $deleteSchedulesStmt->bind_param("ii", $cluster_id, $employee_id);
    $deleteSchedulesStmt->execute();
}

$deleteAttendanceStmt = $conn->prepare(
    "DELETE FROM attendance_logs WHERE cluster_id=? AND employee_id=?"
);

if ($deleteAttendanceStmt) {
    $deleteAttendanceStmt->bind_param("ii", $cluster_id, $employee_id);
    $deleteAttendanceStmt->execute();
}

$deleteMemberStmt = $conn->prepare(
    "DELETE FROM cluster_members WHERE cluster_id=? AND employee_id=?"
);

if (!$deleteMemberStmt) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to remove member."]);
    exit;
}

$deleteMemberStmt->bind_param("ii", $cluster_id, $employee_id);
$deleteMemberStmt->execute();

if ($deleteMemberStmt->affected_rows <= 0) {
    http_response_code(404);
    echo json_encode(["error" => "Member not found in this cluster."]);
    exit;
}

echo json_encode(["success" => true]);