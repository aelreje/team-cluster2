<?php

require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
$employee_id = (int)($data['employee_id'] ?? 0);

if ($employee_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Employee ID missing"
    ]);
    exit();
}

$stmt = $conn->prepare("UPDATE employees SET archived = 0 WHERE employee_id = ?");
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare restore request"
    ]);
    exit();
}

$stmt->bind_param("i", $employee_id);
if (!$stmt->execute()) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to restore employee"
    ]);
    exit();
}

echo json_encode([
    "success" => true
]);
