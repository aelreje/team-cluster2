<?php
require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data) || !isset($data['role_id'], $data['permissions']) || !is_array($data['permissions'])) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid request data"
    ]);
    exit();
}

$role_id = (int)$data['role_id'];
$permissions = $data['permissions'];

if ($role_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid role ID"
    ]);
    exit();
}

$deleteStmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
$getPermissionStmt = $conn->prepare("SELECT permission_id FROM permissions WHERE permission_name = ?");
$insertStmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");

if (!$deleteStmt || !$getPermissionStmt || !$insertStmt) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare role permission statements"
    ]);
    exit();
}

$conn->begin_transaction();
try {
    $deleteStmt->bind_param("i", $role_id);
    if (!$deleteStmt->execute()) {
        throw new RuntimeException('Unable to clear existing role permissions');
    }

    foreach ($permissions as $permission_name) {
        $permission_name = trim((string)$permission_name);
        if ($permission_name === '') {
            continue;
        }

        $getPermissionStmt->bind_param("s", $permission_name);
        $getPermissionStmt->execute();
        $result = $getPermissionStmt->get_result();
        $perm = $result ? $result->fetch_assoc() : null;

        if (!$perm) {
            continue;
        }

        $perm_id = (int)$perm['permission_id'];
        $insertStmt->bind_param("ii", $role_id, $perm_id);
        if (!$insertStmt->execute()) {
            throw new RuntimeException('Unable to insert role permission');
        }
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
    exit();
}

echo json_encode(["success" => true]);
