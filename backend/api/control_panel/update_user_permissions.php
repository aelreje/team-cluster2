<?php

require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON received",
        "raw_input" => $raw
    ]);
    exit();
}

$user_id = (int)($data['user_id'] ?? 0);
$permissions = $data['permissions'] ?? [];

foreach ($permissions as $perm) {
    $permission_id = (int)($perm['permission_id'] ?? 0);
    $allowed = (int)($perm['allowed'] ?? 0);

    $stmt = $conn->prepare("
        INSERT INTO user_permissions (user_id, permission_id, is_allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE is_allowed = ?
    ");

    $stmt->bind_param("iiii", $user_id, $permission_id, $allowed, $allowed);
    $stmt->execute();
}

echo json_encode([
    "success" => true
]);