<?php
include "../config/auth.php";
include "../config/database.php";

$user = $_SESSION['user'] ?? null;

if (!$user) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$permissionNames = [];
$userId = isset($user["id"]) ? (int)$user["id"] : 0;
$roleId = isset($user["role_id"]) ? (int)$user["role_id"] : 0;

if ($userId > 0 && $roleId > 0) {
    $permissionStmt = $conn->prepare(
        "SELECT p.permission_name,
                CASE
                    WHEN up.is_allowed IS NOT NULL THEN up.is_allowed
                    WHEN rp.permission_id IS NOT NULL THEN 1
                    ELSE 0
                END AS is_allowed
         FROM permissions p
         LEFT JOIN role_permissions rp
             ON rp.permission_id = p.permission_id
             AND rp.role_id = ?
         LEFT JOIN user_permissions up
             ON up.permission_id = p.permission_id
             AND up.user_id = ?"
    );

    if ($permissionStmt) {
        $permissionStmt->bind_param("ii", $roleId, $userId);
        $permissionStmt->execute();
        $result = $permissionStmt->get_result();

        while ($row = $result->fetch_assoc()) {
            if ((int)($row["is_allowed"] ?? 0) === 1 && !empty($row["permission_name"])) {
                $permissionNames[] = $row["permission_name"];
            }
        }
    }
}

echo json_encode([
    "id" => $user["id"],
    "fullname" => $user["fullname"],
    "email" => $user["email"],
    "role" => $user["role"],
    "permissions" => array_values(array_unique($permissionNames))
]);
