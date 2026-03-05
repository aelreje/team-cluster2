<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("coach");

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);
$cluster_id = (int)($data["cluster_id"] ?? 0);
$coach_id = (int)$_SESSION["user"]["id"];

$clusterColumns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM clusters");
if ($columnResult) {
    while ($row = $columnResult->fetch_assoc()) {
        $clusterColumns[] = $row["Field"];
    }
}

$clusterIdColumn = in_array("id", $clusterColumns, true) ? "id" : "cluster_id";
$ownerColumn = in_array("coach_id", $clusterColumns, true) ? "coach_id" : "user_id";

if ($cluster_id === 0) {
    http_response_code(400);
    exit(json_encode(["error" => "Invalid cluster request."]));
}

$clusterCheckStmt = $conn->prepare(
    "SELECT $clusterIdColumn FROM clusters WHERE $clusterIdColumn = ? AND $ownerColumn = ? LIMIT 1"
);

if (!$clusterCheckStmt) {
    http_response_code(500);
    exit(json_encode(["error" => "Unable to validate cluster ownership."]));
}

$clusterCheckStmt->bind_param("ii", $cluster_id, $coach_id);
$clusterCheckStmt->execute();
$cluster_check = $clusterCheckStmt->get_result();

if (!$cluster_check || $cluster_check->num_rows === 0) {
    http_response_code(403);
    exit(json_encode(["error" => "Not authorized to disband this cluster."]));
}

$conn->begin_transaction();

$deleteMemberSchedulesStmt = $conn->prepare("DELETE FROM schedules WHERE cluster_id = ?");
$deleteMembersStmt = $conn->prepare("DELETE FROM cluster_members WHERE cluster_id = ?");
$deleteAttendanceStmt = $conn->prepare("DELETE FROM attendance_logs WHERE cluster_id = ?");
$deleteClusterStmt = $conn->prepare("DELETE FROM clusters WHERE $clusterIdColumn = ?");

if (!$deleteMemberSchedulesStmt || !$deleteMembersStmt || !$deleteAttendanceStmt || !$deleteClusterStmt) {
    $conn->rollback();
    http_response_code(500);
    exit(json_encode(["error" => "Unable to prepare disband operation."]));
}

$deleteMemberSchedulesStmt->bind_param("i", $cluster_id);
$deleteMembersStmt->bind_param("i", $cluster_id);
$deleteAttendanceStmt->bind_param("i", $cluster_id);
$deleteClusterStmt->bind_param("i", $cluster_id);

$deleted = $deleteMemberSchedulesStmt->execute()
    && $deleteMembersStmt->execute()
    && $deleteAttendanceStmt->execute()
    && $deleteClusterStmt->execute();

if ($deleted !== true || $deleteClusterStmt->affected_rows <= 0) {
    $conn->rollback();
    http_response_code(500);
    exit(json_encode(["error" => "Unable to disband cluster."]));
}

$conn->commit();

echo json_encode(["success" => true]);
