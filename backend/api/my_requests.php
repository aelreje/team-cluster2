<?php
include "../config/database.php";
include "../config/auth.php";
requireRole(["admin", "coach", "employee"]);

function hasTable(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

$sessionUserId = (int)($_SESSION['user']['id'] ?? 0);
$employeeId = $sessionUserId;

if (hasTable($conn, 'employees') && hasColumn($conn, 'employees', 'user_id') && hasColumn($conn, 'employees', 'employee_id')) {
    $stmt = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $sessionUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $employeeId = (int)$result->fetch_assoc()['employee_id'];
    }
}

$items = [];

if (hasTable($conn, 'leave_requests')) {
    $stmt = $conn->prepare(
        "SELECT
            leave_id AS source_id,
            created_at AS filed_at,
            leave_type AS request_type,
            reason AS details,
            CONCAT(COALESCE(start_date, ''), CASE WHEN end_date IS NOT NULL THEN CONCAT(' to ', end_date) ELSE '' END) AS schedule_period,
            status
         FROM leave_requests
         WHERE employee_id = ?"
    );
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => 'leave-' . $row['source_id'],
            'request_source' => 'leave',
            'date_filed' => $row['filed_at'],
            'request_type' => $row['request_type'] ?: 'Leave',
            'details' => $row['details'] ?: '—',
            'schedule_period' => trim((string)$row['schedule_period']) ?: '—',
            'status' => $row['status'] ?: 'Pending'
        ];
    }
}

if (hasTable($conn, 'overtime_requests')) {
    $stmt = $conn->prepare(
        "SELECT
            ot_id AS source_id,
            created_at AS filed_at,
            ot_type AS request_type,
            purpose AS details,
            CONCAT(COALESCE(start_time, ''), CASE WHEN end_time IS NOT NULL THEN CONCAT(' to ', end_time) ELSE '' END) AS schedule_period,
            status
         FROM overtime_requests
         WHERE employee_id = ?"
    );
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => 'ot-' . $row['source_id'],
            'request_source' => 'overtime',
            'date_filed' => $row['filed_at'],
            'request_type' => $row['request_type'] ?: 'Overtime',
            'details' => $row['details'] ?: '—',
            'schedule_period' => trim((string)$row['schedule_period']) ?: '—',
            'status' => $row['status'] ?: 'Pending'
        ];
    }
}

if (hasTable($conn, 'attendance_disputes')) {
    $stmt = $conn->prepare(
        "SELECT
            dispute_id AS source_id,
            created_at AS filed_at,
            dispute_type AS request_type,
            reason AS details,
            dispute_date AS schedule_period,
            status
         FROM attendance_disputes
         WHERE employee_id = ?"
    );
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => 'dispute-' . $row['source_id'],
            'request_source' => 'dispute',
            'date_filed' => $row['filed_at'],
            'request_type' => $row['request_type'] ?: 'Attendance Dispute',
            'details' => $row['details'] ?: '—',
            'schedule_period' => $row['schedule_period'] ?: '—',
            'status' => $row['status'] ?: 'Pending'
        ];
    }
}

usort($items, function ($a, $b) {
    $left = strtotime((string)($a['date_filed'] ?? ''));
    $right = strtotime((string)($b['date_filed'] ?? ''));
    return $right <=> $left;
});

echo json_encode($items);