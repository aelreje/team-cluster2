<?php

require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

$userId = currentUserId();

$stmt = $conn->prepare("
    SELECT first_name
    FROM employees
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Employee not found"
    ]);
    exit();
}

$employee = $result->fetch_assoc();

echo json_encode([
    "success" => true,
    "user" => [
        "first_name" => $employee['first_name'],
        "role_name"  => $_SESSION['user']['role'] ?? null,
        "permissions" => $_SESSION['permissions'] ?? []
    ]
]);