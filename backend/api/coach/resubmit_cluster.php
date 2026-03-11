<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
requireRole("coach");

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);
$cluster_id = (int)($data["cluster_id"] ?? 0);
$name = trim($data["name"] ?? "");
$description = trim($data["description"] ?? "");
$coach_id = (int)$_SESSION["user"]["id"];

if ($cluster_id <= 0) {
    http_response_code(422);
    exit(json_encode(["error" => "Cluster id is required."]));
}

if ($name === "") {
    http_response_code(422);
    exit(json_encode(["error" => "Cluster name is required."]));
}

$columns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM clusters");
if ($columnResult) {
    while ($row = $columnResult->fetch_assoc()) {
        $columns[] = $row["Field"];
    }
}

$idColumn = in_array("id", $columns, true) ? "id" : "cluster_id";
$ownerColumn = in_array("coach_id", $columns, true) ? "coach_id" : "user_id";

$stmt = $conn->prepare(
    "UPDATE clusters
     SET name = ?,
         description = ?,
         status = 'pending',
         rejection_reason = NULL
     WHERE $idColumn = ?
       AND $ownerColumn = ?
       AND LOWER(status) = 'rejected'"
);

if (!$stmt) {
    http_response_code(500);
    exit(json_encode(["error" => "Failed to resubmit cluster."]));
}

$stmt->bind_param("ssii", $name, $description, $cluster_id, $coach_id);
$res = $stmt->execute();

if ($res !== true) {
    http_response_code(500);
    exit(json_encode(["error" => "Failed to resubmit cluster."]));
}

if ($stmt->affected_rows === 0) {
    http_response_code(404);
    exit(json_encode(["error" => "Rejected cluster not found."]));
}

echo json_encode(["success" => true]);