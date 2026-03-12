<?php
require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['role_id'], $data['permissions'])) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid request data"
    ]);
    exit();
}

$role_id = (int)$data['role_id'];
$permissions = $data['permissions'];

$stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
$stmt->bind_param("i", $role_id);
$stmt->execute();

foreach ($permissions as $permission_name) {
    $stmt = $conn->prepare("SELECT permission_id FROM permissions WHERE permission_name = ?");
    $stmt->bind_param("s", $permission_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $perm = $result->fetch_assoc();

    if ($perm) {
        $perm_id = $perm['permission_id'];

        $stmt2 = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        $stmt2->bind_param("ii", $role_id, $perm_id);
        $stmt2->execute();
    }
}

echo json_encode(["success" => true]);
