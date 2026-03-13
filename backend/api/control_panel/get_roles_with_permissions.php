<?php
require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

$sql = "
SELECT 
    r.role_id,
    r.role_name,
    p.permission_id,
    p.permission_name
FROM roles r
LEFT JOIN role_permissions rp ON r.role_id = rp.role_id
LEFT JOIN permissions p ON rp.permission_id = p.permission_id
ORDER BY r.role_id
";

$result = $conn->query($sql);
$roles = [];

while ($row = $result->fetch_assoc()) {
    $role_id = $row['role_id'];
    $role_name = $row['role_name'];

    if (!isset($roles[$role_id])) {
        $roles[$role_id] = [
            "role_id" => $role_id,
            "role_name" => $role_name,
            "permissions" => []
        ];
    }

    if ($row['permission_name']) {
        $roles[$role_id]["permissions"][] = $row['permission_name'];
    }
}

echo json_encode([
    "success" => true,
    "data" => array_values($roles)
]);