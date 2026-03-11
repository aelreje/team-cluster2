<?php
include "../config/database.php";
include "../config/auth.php";
requireRole(["admin", "coach", "employee"]);

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid request payload."]);
    exit;
}

$type = trim((string)($body['type'] ?? ''));
$reason = trim((string)($body['reason'] ?? ''));
if ($type === '' || $reason === '') {
    http_response_code(422);
    echo json_encode(["error" => "Request type and reason are required."]);
    exit;
}

$sessionUserId = (int)($_SESSION['user']['id'] ?? 0);
$employeeId = $sessionUserId;
if (hasColumn($conn, 'employees', 'user_id') && hasColumn($conn, 'employees', 'employee_id')) {
    $stmt = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $sessionUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $employeeId = (int)$result->fetch_assoc()['employee_id'];
    }
}

$clusterId = null;
if (hasColumn($conn, 'cluster_members', 'cluster_id') && hasColumn($conn, 'cluster_members', 'employee_id')) {
    $stmt = $conn->prepare("SELECT cluster_id FROM cluster_members WHERE employee_id = ? ORDER BY assigned_at DESC LIMIT 1");
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $clusterId = (int)$result->fetch_assoc()['cluster_id'];
    }
}

if ($type === 'leave') {
    $leaveType = trim((string)($body['leaveType'] ?? 'Sick Leave'));
    $startDate = trim((string)($body['startDate'] ?? ''));
    $endDate = trim((string)($body['endDate'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        http_response_code(422);
        echo json_encode(["error" => "Valid start and end dates are required."]);
        exit;
    }

    $status = 'Pending';
    $stmt = $conn->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('isssss', $employeeId, $leaveType, $startDate, $endDate, $reason, $status);
    $stmt->execute();
} elseif ($type === 'overtime') {
    $otType = trim((string)($body['otType'] ?? 'Regular Overtime'));
    $date = trim((string)($body['date'] ?? ''));
    $startTime = trim((string)($body['startTime'] ?? ''));
    $endTime = trim((string)($body['endTime'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
        http_response_code(422);
        echo json_encode(["error" => "Valid overtime date and times are required."]);
        exit;
    }

    $startDateTime = $date . ' ' . $startTime . ':00';
    $endDateTime = $date . ' ' . $endTime . ':00';
    $status = 'Pending';
    $stmt = $conn->prepare("INSERT INTO overtime_requests (employee_id, ot_type, start_time, end_time, purpose, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('isssss', $employeeId, $otType, $startDateTime, $endDateTime, $reason, $status);
    $stmt->execute();
} elseif ($type === 'dispute') {
    $disputeDate = trim((string)($body['disputeDate'] ?? ''));
    $disputeType = trim((string)($body['disputeType'] ?? 'Time Correction'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $disputeDate)) {
        http_response_code(422);
        echo json_encode(["error" => "Valid dispute date is required."]);
        exit;
    }

    $status = 'Pending';
    $remarks = '';

    if ($clusterId === null) {
        $stmt = $conn->prepare("INSERT INTO attendance_disputes (cluster_id, employee_id, dispute_date, dispute_type, reason, status, created_at, remarks) VALUES (NULL, ?, ?, ?, ?, ?, NOW(), ?)");
        $stmt->bind_param('isssss', $employeeId, $disputeDate, $disputeType, $reason, $status, $remarks);
    } else {
        $stmt = $conn->prepare("INSERT INTO attendance_disputes (cluster_id, employee_id, dispute_date, dispute_type, reason, status, created_at, remarks) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
        $stmt->bind_param('iisssss', $clusterId, $employeeId, $disputeDate, $disputeType, $reason, $status, $remarks);
    }
    $stmt->execute();
} else {
    http_response_code(422);
    echo json_encode(["error" => "Unsupported request type."]);
    exit;
}

if ($conn->errno) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to submit request."]);
    exit;
}

echo json_encode(["success" => true]);
