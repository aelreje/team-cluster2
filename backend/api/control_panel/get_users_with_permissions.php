<?php
require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

$sql = "
SELECT
    u.user_id,
    CONCAT(
        e.first_name,
        ' ',
        IFNULL(CONCAT(e.middle_name, ' '), ''),
        e.last_name
    ) AS full_name,
    e.position,
    r.role_name,
    GROUP_CONCAT(DISTINCT p.permission_name ORDER BY p.permission_name SEPARATOR ',') AS permissions
FROM users u
LEFT JOIN employees e ON u.user_id = e.user_id
LEFT JOIN roles r ON u.role_id = r.role_id
LEFT JOIN role_permissions rp ON r.role_id = rp.role_id
LEFT JOIN permissions p ON rp.permission_id = p.permission_id
GROUP BY u.user_id, full_name, e.position, r.role_name
ORDER BY u.user_id DESC
";

$result = $conn->query($sql);
if ($result === false) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to load users"
    ]);
    exit();
}

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = [
        "id" => (int)$row["user_id"],
        "fullName" => $row["full_name"],
        "role" => $row["role_name"] ?? "",
        "position" => $row["position"] ?? "",
        "permissions" => !empty($row["permissions"]) ? explode(",", $row["permissions"]) : []
    ];
}

echo json_encode([
    "success" => true,
    "data" => $users
]);
