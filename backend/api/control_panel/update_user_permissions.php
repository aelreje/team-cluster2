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

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON received"
    ]);
    exit();
}

$user_id = (int)($data['user_id'] ?? 0);
$permissions = $data['permissions'] ?? null;

if ($user_id <= 0 || !is_array($permissions)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid user permissions payload"
    ]);
    exit();
}

$stmt = $conn->prepare("
    INSERT INTO user_permissions (user_id, permission_id, is_allowed)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed)
");

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare update statement"
    ]);
    exit();
}

$conn->begin_transaction();
try {
    foreach ($permissions as $perm) {
        $permission_id = (int)($perm['permission_id'] ?? 0);
        $allowed = (int)($perm['allowed'] ?? 0);

        if ($permission_id <= 0) {
            throw new RuntimeException('Invalid permission ID');
        }

        $stmt->bind_param("iii", $user_id, $permission_id, $allowed);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to save user permissions');
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

echo json_encode([
    "success" => true
]);
