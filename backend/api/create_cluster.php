<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("coach");

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);
$name = trim($data["name"] ?? "");
$description = trim($data["description"] ?? "");

if ($name === "") {
    http_response_code(422);
    exit(json_encode(["error" => "Cluster name is required."]));
}

$coach_id = (int)$_SESSION["user"]["id"];

$columns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM clusters");
if ($columnResult) {
    while ($row = $columnResult->fetch_assoc()) {
        $columns[] = $row["Field"];
    }
}

$idColumn = in_array("id", $columns, true) ? "id" : "cluster_id";
$ownerColumn = in_array("coach_id", $columns, true) ? "coach_id" : "user_id";

$existingStmt = $conn->prepare(
    "SELECT $idColumn FROM clusters WHERE $ownerColumn = ? LIMIT 1"
);
$existingStmt->bind_param("i", $coach_id);
$existingStmt->execute();
$existingCluster = $existingStmt->get_result();

if ($existingCluster && $existingCluster->num_rows > 0) {
    http_response_code(409);
    exit(json_encode(["error" => "Only one team cluster is allowed per team coach."]));
}

$insertStmt = $conn->prepare(
    "INSERT INTO clusters (name, description, $ownerColumn, status, created_at)
     VALUES (?, ?, ?, 'pending', NOW())"
);
$insertStmt->bind_param("ssi", $name, $description, $coach_id);
$result = $insertStmt->execute();

if ($result !== true) {
    http_response_code(500);
    exit(json_encode(["error" => "Failed to create cluster."]));
}

$id = $conn->insert_id;

echo json_encode([
    "id" => $id,
    "name" => $name,
    "description" => $description,
    "status" => "pending",
    "members" => 0,
    "rejection_reason" => null,
    "created_at" => date("Y-m-d")
]);
