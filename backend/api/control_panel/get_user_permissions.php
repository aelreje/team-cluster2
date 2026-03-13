<?php

require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid user ID"
    ]);
    exit();
}

$stmt = $conn->prepare("
SELECT
    p.permission_id,
    p.permission_name,
    COALESCE(up.is_allowed, 0) AS allowed
FROM permissions p
LEFT JOIN user_permissions up
    ON up.permission_id = p.permission_id
    AND up.user_id = ?
ORDER BY p.permission_id
");

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare permissions query"
    ]);
    exit();
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$permissions = [];
while ($row = $result->fetch_assoc()) {
    $permissions[] = [
        "permission_id" => (int)$row['permission_id'],
        "permission_name" => $row['permission_name'],
        "allowed" => (int)$row['allowed']
    ];
}

echo json_encode([
    "success" => true,
    "permissions" => $permissions
]);
