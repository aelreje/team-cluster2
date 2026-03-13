<?php

require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

$result = $conn->query("
SELECT
    employee_id,
    first_name,
    middle_name,
    last_name,
    position
FROM employees
WHERE archived = 1
ORDER BY employee_id DESC
");

if ($result === false) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to load archived users"
    ]);
    exit();
}

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = [
        "employee_id" => (int)$row['employee_id'],
        "fullName" => trim($row['first_name'] . " " . ($row['middle_name'] ?? '') . " " . $row['last_name']),
        "position" => $row['position'] ?? ""
    ];
}

echo json_encode([
    "success" => true,
    "users" => $users
]);
