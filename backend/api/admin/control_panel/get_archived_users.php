<?php
include __DIR__ . "/common.php";

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit(json_encode(["error" => "Method not allowed"]));
}

requireControlPanelAccess($conn);

$sql = "SELECT
            e.employee_id,
            e.user_id,
            COALESCE(e.first_name, '') AS first_name,
            COALESCE(e.middle_name, '') AS middle_name,
            COALESCE(e.last_name, '') AS last_name,
            CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name) AS fullname,
            COALESCE(e.position, '') AS position,
            COALESCE(e.employment_status, '') AS employment_status,
            COALESCE(e.email, u.email, '') AS email
        FROM employees e
        LEFT JOIN users u ON u.user_id = e.user_id
        WHERE COALESCE(e.archived, 0) = 1
        ORDER BY e.employee_id DESC";

$result = $conn->query($sql);
if (!$result) {
    http_response_code(500);
    exit(json_encode(["error" => "Unable to load archived users."]));
}

echo json_encode([
    "success" => true,
    "users" => archivedEmployeePayload($result)
]);
