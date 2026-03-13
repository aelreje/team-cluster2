<?php
require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

$sql = "
SELECT
    u.user_id,
    TRIM(
      CONCAT_WS(
        ' ',
        NULLIF(e.first_name, ''),
        NULLIF(e.middle_name, ''),
        NULLIF(e.last_name, '')
      )
    ) AS full_name,
    e.position,
    r.role_name,
    GROUP_CONCAT(DISTINCT p.permission_name ORDER BY p.permission_name) AS permissions
FROM users u
LEFT JOIN employees e ON u.user_id = e.user_id
LEFT JOIN roles r ON u.role_id = r.role_id
LEFT JOIN role_permissions rp ON r.role_id = rp.role_id
LEFT JOIN permissions p ON rp.permission_id = p.permission_id
WHERE r.role_name IN ('Super Admin', 'Admin', 'Team Coach', 'Employee')
GROUP BY u.user_id, e.first_name, e.middle_name, e.last_name, e.position, r.role_name
ORDER BY u.user_id ASC
";

$result = $conn->query($sql);
$users = [];

while ($row = $result->fetch_assoc()) {
    $displayName = $row["full_name"];

    if (!$displayName) {
        $displayName = $row["role_name"];
    }

    $users[] = [
        "id" => $row["user_id"],
        "fullName" => $displayName,
        "role" => $row["role_name"],
        "position" => $row["position"] ?? "-",
        "permissions" => $row["permissions"]
            ? explode(",", $row["permissions"])
            : []
    ];
}

echo json_encode([
    "success" => true,
    "data" => $users
]);
