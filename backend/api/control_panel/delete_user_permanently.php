<?php

require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";
require_once "../utils/logger.php";

if (!isset($_GET['employee_id'])) {
    echo json_encode([
        "success" => false,
        "message" => "Employee ID missing"
    ]);
    exit();
}

$employee_id = (int)$_GET['employee_id'];

$stmt = $conn->prepare("
DELETE FROM employees
WHERE employee_id = ?
");

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