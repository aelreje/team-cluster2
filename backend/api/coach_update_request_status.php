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

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid request payload."]);
    exit;
}

$source = trim((string)($body['request_source'] ?? ''));
$requestId = (int)($body['request_id'] ?? 0);
$status = trim((string)($body['status'] ?? ''));

if (!in_array($source, ['leave', 'overtime', 'dispute'], true) || $requestId <= 0 || !in_array($status, ['Approved', 'Rejected'], true)) {
    http_response_code(422);
    echo json_encode(["error" => "Invalid request update payload."]);
    exit;
}

$map = [
    'leave' => ['table' => 'leave_requests', 'id' => 'leave_id'],
    'overtime' => ['table' => 'overtime_requests', 'id' => 'ot_id'],
    'dispute' => ['table' => 'attendance_disputes', 'id' => 'dispute_id']
];

$clusterColumns = getColumns($conn, 'clusters');
$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$clusterOwnerColumn = in_array('coach_id', $clusterColumns, true) ? 'coach_id' : 'user_id';
$coachId = (int)($_SESSION['user']['id'] ?? 0);
$table = $map[$source]['table'];
$idColumn = $map[$source]['id'];

$checkSql = "SELECT req.employee_id
             FROM $table req
             INNER JOIN cluster_members cm ON cm.employee_id = req.employee_id
             INNER JOIN clusters c ON c.$clusterIdColumn = cm.cluster_id
             WHERE req.$idColumn = ? AND c.$clusterOwnerColumn = ?
             LIMIT 1";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param('ii', $requestId, $coachId);
$checkStmt->execute();
$allowed = $checkStmt->get_result();
if (!$allowed || $allowed->num_rows === 0) {
    http_response_code(403);
    echo json_encode(["error" => "You can only update requests from your assigned team."]);
    exit;
}

$updateStmt = $conn->prepare("UPDATE $table SET status = ? WHERE $idColumn = ?");
$updateStmt->bind_param('si', $status, $requestId);
$updateStmt->execute();

if ($conn->errno) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to update request status."]);
    exit;
}

echo json_encode(["success" => true]);