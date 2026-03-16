<?php
include __DIR__ . "/common.php";

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit(json_encode(["error" => "Method not allowed"]));
}

requireRoleOrPermission(["super admin", "admin"], $conn, ["Delete Employee", "Access Control Panel"]);

$data = json_decode(file_get_contents("php://input"), true);
$employeeId = (int)($data['employee_id'] ?? $data['id'] ?? 0);
if ($employeeId <= 0) {
    http_response_code(400);
    exit(json_encode(["success" => false, "message" => "Employee id is required."]));
}

$stmt = $conn->prepare("UPDATE employees SET archived = 1, employment_status = 'Archived' WHERE employee_id = ? AND COALESCE(archived, 0) = 0");
if (!$stmt) {
    http_response_code(500);
    exit(json_encode(["success" => false, "message" => "Unable to archive employee."]));
}

$stmt->bind_param("i", $employeeId);
if (!$stmt->execute()) {
    http_response_code(500);
    exit(json_encode(["success" => false, "message" => "Unable to archive employee."]));
}

if ($stmt->affected_rows <= 0) {
    http_response_code(404);
    exit(json_encode(["success" => false, "message" => "Employee not found."]));
}

logControlPanelAction($conn, 'archive_user', "employee:$employeeId");

echo json_encode(["success" => true]);
