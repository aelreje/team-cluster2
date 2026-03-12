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
");

$users = [];

while ($row = $result->fetch_assoc()) {
    $users[] = [
        "employee_id" => $row['employee_id'],
        "fullName" => trim($row['first_name'] . " " . ($row['middle_name'] ?? '') . " " . $row['last_name']),
        "position" => $row['position']
    ];
}

echo json_encode([
    "success" => true,
    "users" => $users
]);
