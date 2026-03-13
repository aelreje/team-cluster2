<?php

require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

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
$stmt->bind_param("i", $employee_id);
$stmt->execute();

echo json_encode([
    "success" => true
]);