<?php
include __DIR__ . "/common.php";

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit(json_encode(["error" => "Method not allowed"]));
}

requireControlPanelAccess($conn);

$sql = "SELECT
            a.log_id,
            a.user_id,
            COALESCE(a.action, '') AS action,
            COALESCE(a.target, '') AS target,
            a.created_at,
            COALESCE(e.first_name, '') AS first_name,
            COALESCE(e.middle_name, '') AS middle_name,
            COALESCE(e.last_name, '') AS last_name,
            COALESCE(u.email, '') AS email
        FROM activity_logs a
        LEFT JOIN users u ON u.user_id = a.user_id
        LEFT JOIN employees e ON e.user_id = a.user_id
        ORDER BY a.created_at DESC, a.log_id DESC
        LIMIT 100";

$result = $conn->query($sql);
if (!$result) {
    http_response_code(500);
    exit(json_encode(["error" => "Unable to load logs."]));
}

$logs = [];
while ($row = $result->fetch_assoc()) {
    $name = trim(implode(' ', array_filter([
        trim((string)($row['first_name'] ?? '')),
        trim((string)($row['middle_name'] ?? '')),
        trim((string)($row['last_name'] ?? '')),
    ])));

    if ($name === '') {
        $name = trim((string)($row['email'] ?? ''));
    }

    if ($name === '') {
        $name = 'Unknown User';
    }

    $logs[] = [
        'id' => (int)$row['log_id'],
        'user_id' => (int)$row['user_id'],
        'user' => $name,
        'action' => (string)$row['action'],
        'target' => (string)$row['target'],
        'created_at' => $row['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'logs' => $logs
]);
