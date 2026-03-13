<?php

require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";
require_once "../utils/logger.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ]);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
$employee_id = (int)($payload['employee_id'] ?? ($_GET['employee_id'] ?? 0));

if ($employee_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Employee ID missing"
    ]);
    exit();
}

$stmt = $conn->prepare("DELETE FROM employees WHERE employee_id = ?");
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare delete statement"
    ]);
    exit();
}

$stmt->bind_param("i", $employee_id);
if (!$stmt->execute()) {
    echo json_encode([
        "success" => false,
        "message" => "Delete failed"
    ]);
    exit();
}

logAction(
    $conn,
    currentUserId(),
    "Deleted Employee Permanently",
    $employee_id
);

echo json_encode([
    "success" => true
]);
