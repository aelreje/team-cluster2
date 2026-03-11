<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
requireRole("admin");

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data['cluster_id'] ?? 0);
$status = $data['status'] ?? ""; // active | rejected
$rejection_reason = trim($data['rejection_reason'] ?? "");

if ($id <= 0 || !in_array($status, ["active", "rejected"], true)) {
    http_response_code(422);
    exit(json_encode(["error" => "Invalid cluster status update request."]));
}

if ($status === "rejected" && $rejection_reason === "") {
    http_response_code(422);
    exit(json_encode(["error" => "Rejection reason is required."]));
}

$columns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM clusters");
if ($columnResult) {
    while ($row = $columnResult->fetch_assoc()) {
        $columns[] = $row["Field"];
    }
}

$idColumn = in_array("id", $columns, true) ? "id" : "cluster_id";

if ($status === "rejected") {
    $stmt = $conn->prepare(
        "UPDATE clusters
         SET status='rejected', rejection_reason=?
         WHERE $idColumn=?"
    );
    $stmt->bind_param("si", $rejection_reason, $id);
} else {
    $stmt = $conn->prepare(
        "UPDATE clusters
         SET status='active', rejection_reason=NULL
         WHERE $idColumn=?"
    );
    $stmt->bind_param("i", $id);
}

$ok = $stmt->execute();

if ($ok !== true) {
    http_response_code(500);
    exit(json_encode(["error" => "Failed to update cluster status."]));
}

echo json_encode(["success" => true]);