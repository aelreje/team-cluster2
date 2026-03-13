<?php

require_once __DIR__ . "/_require_superadmin.php";
require_once "../config/database.php";

$result = $conn->query("
SELECT
    l.log_id,
    u.email,
    l.action,
    l.target,
    l.created_at
FROM activity_logs l
LEFT JOIN users u
    ON u.user_id = l.user_id
ORDER BY l.created_at DESC
");

if ($result === false) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to load activity logs"
    ]);
    exit();
}

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = [
        "id" => (int)$row["log_id"],
        "user" => $row["email"] ?? "Unknown",
        "action" => $row["action"],
        "target" => $row["target"],
        "date" => $row["created_at"]
    ];
}

echo json_encode([
    "success" => true,
    "logs" => $logs
]);
