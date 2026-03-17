<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
requireRole("employee");

$sessionUserId = (int)($_SESSION['user']['id'] ?? 0);

// Get employee_id from employees table
$stmt = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $sessionUserId);
$stmt->execute();
$res = $stmt->get_result();
$employee = $res->fetch_assoc();

if (!$employee) {
    echo json_encode(['error' => 'Employee record not found']);
    exit;
}

$employeeId = $employee['employee_id'];

// Fetch combined attendance and time logs
$sql = "SELECT 
            al.attendance_id,
            al.attendance_date as date,
            al.attendance_status as status,
            tl.time_in,
            tl.time_out,
            tl.break_start as break_in,
            tl.break_end as break_out,
            tl.total_hours
        FROM attendance_logs al
        LEFT JOIN time_logs tl ON al.attendance_id = tl.attendance_id
        WHERE al.employee_id = ?
        ORDER BY al.attendance_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $employeeId);
$stmt->execute();
$result = $stmt->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = [
        'date' => $row['date'] ?? date('Y-m-d'),
        'time_in' => $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '--',
        'time_out' => $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '--',
        'break_in' => $row['break_in'] ? date('h:i A', strtotime($row['break_in'])) : '--',
        'break_out' => $row['break_out'] ? date('h:i A', strtotime($row['break_out'])) : '--',
        'total_hours' => $row['total_hours'] ?? '0.00',
        'status' => strtolower($row['status'] ?? 'pending')
    ];
}

echo json_encode($logs);
