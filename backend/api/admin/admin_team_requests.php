<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
requireRole(["admin", "super admin"]);

function hasTable(mysqli $conn, string $table): bool {
    $safe = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function getColumns(mysqli $conn, string $table): array {
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function getClusterMemberEmployeeReference(mysqli $conn): ?string {
    $sql = "SELECT REFERENCED_TABLE_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cluster_members'
              AND COLUMN_NAME = 'employee_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1";

    $result = $conn->query($sql);
    if (!$result) {
        return null;
    }

    $row = $result->fetch_assoc();
    return $row['REFERENCED_TABLE_NAME'] ?? null;
}

$clusterColumns = getColumns($conn, 'clusters');
$userColumns = hasTable($conn, 'users') ? getColumns($conn, 'users') : [];
$employeeColumns = hasTable($conn, 'employees') ? getColumns($conn, 'employees') : [];
$requestEmployeeReference = getClusterMemberEmployeeReference($conn);
$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$clusterOwnerColumn = in_array('coach_id', $clusterColumns, true) ? 'coach_id' : 'user_id';
$sessionUserId = (int)($_SESSION['user']['id'] ?? 0);

$usersIdColumn = in_array('id', $userColumns, true) ? 'id' : (in_array('user_id', $userColumns, true) ? 'user_id' : null);
$userDisplayColumn = in_array('fullname', $userColumns, true) ? 'fullname' : (in_array('username', $userColumns, true) ? 'username' : null);
$canJoinEmployees = in_array('user_id', $employeeColumns, true) && in_array('employee_id', $employeeColumns, true);

$currentEmployeeId = $sessionUserId;
if ($canJoinEmployees) {
    $employeeStmt = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1");
    if ($employeeStmt) {
        $employeeStmt->bind_param('i', $sessionUserId);
        $employeeStmt->execute();
        $employeeResult = $employeeStmt->get_result();
        if ($employeeResult && $employeeResult->num_rows > 0) {
            $currentEmployeeId = (int)$employeeResult->fetch_assoc()['employee_id'];
        }
    }
}

$excludeRequesterCondition = $requestEmployeeReference === 'users'
    ? "req.employee_id <> $sessionUserId"
    : "req.employee_id <> $currentEmployeeId";

$requestEmployeeExpr = 'req.employee_id';
$employeeJoinSql = '';
if ($requestEmployeeReference === 'users' && $canJoinEmployees) {
    $requestEmployeeExpr = 'COALESCE(emp.employee_id, req.employee_id)';
    $employeeJoinSql = ' LEFT JOIN employees emp ON emp.user_id = req.employee_id';
}

$employeeNameExpr = "CONCAT('Employee #', $requestEmployeeExpr)";
$userJoinSql = '';
if ($usersIdColumn !== null && $userDisplayColumn !== null) {
    if ($requestEmployeeReference === 'users') {
        $userJoinSql = " LEFT JOIN users requester ON requester.$usersIdColumn = req.employee_id";
        $employeeNameExpr = "COALESCE(requester.$userDisplayColumn, CONCAT('Employee #', $requestEmployeeExpr))";
    } else {
        $userJoinSql = " LEFT JOIN users requester ON requester.$usersIdColumn = $requestEmployeeExpr";
        $employeeNameExpr = "COALESCE(requester.$userDisplayColumn, CONCAT('Employee #', $requestEmployeeExpr))";
    }
}

$items = [];

$loadRequests = function (string $table, string $idColumn, string $typeColumn, string $detailsColumn, string $scheduleExpr, string $alias, string $defaultType) use ($conn, $clusterIdColumn, $clusterOwnerColumn, $requestEmployeeExpr, $employeeJoinSql, $userJoinSql, $employeeNameExpr, $excludeRequesterCondition, $requestEmployeeReference, $sessionUserId, $currentEmployeeId, &$items) {
                req.$idColumn AS source_id,
                req.created_at AS filed_at,
                req.$typeColumn AS request_type,
                req.$detailsColumn AS details,
                $scheduleExpr AS schedule_period,
                req.status,
                $requestEmployeeExpr AS employee_id,
                $employeeNameExpr AS employee_name,
                c.$clusterIdColumn AS cluster_id,
                c.name AS cluster_name
            FROM $table req
            $employeeJoinSql
            LEFT JOIN cluster_members cm ON cm.employee_id = $requestEmployeeExpr
            LEFT JOIN clusters c ON (c.$clusterIdColumn = cm.cluster_id OR c.$clusterOwnerColumn = req.employee_id)
                AND c.status = 'active'
            $userJoinSql
            WHERE LOWER(COALESCE(req.status, '')) = 'endorsed'
              AND $excludeRequesterCondition";

    $res = $conn->query($sql);
    if (!$res) {
        return;
    }

    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => $alias . '-' . $row['source_id'],
            'source_id' => (int)$row['source_id'],
            'request_source' => $alias,
            'date_filed' => $row['filed_at'],
            'request_type' => $row['request_type'] ?: $defaultType,
            'details' => $row['details'] ?: '—',
            'schedule_period' => trim((string)$row['schedule_period']) ?: '—',
            'status' => $row['status'] ?: 'Pending',
            'employee_id' => (int)$row['employee_id'],
            'employee_name' => $row['employee_name'] ?: 'Employee',
            'cluster_id' => isset($row['cluster_id']) ? (int)$row['cluster_id'] : null,
            'can_review' => ((int)$row['employee_id']) !== ($requestEmployeeReference === 'users' ? $sessionUserId : $currentEmployeeId),
            'cluster_name' => $row['cluster_name'] ?: '—'
        ];
    }
};

if (hasTable($conn, 'leave_requests')) {
    $loadRequests(
        'leave_requests',
        'leave_id',
        'leave_type',
        'reason',
        "CONCAT(COALESCE(req.start_date, ''), CASE WHEN req.end_date IS NOT NULL THEN CONCAT(' to ', req.end_date) ELSE '' END)",
        'leave',
        'Leave'
    );
}

if (hasTable($conn, 'overtime_requests')) {
    $loadRequests(
        'overtime_requests',
        'ot_id',
        'ot_type',
        'purpose',
        "CONCAT(COALESCE(req.start_time, ''), CASE WHEN req.end_time IS NOT NULL THEN CONCAT(' to ', req.end_time) ELSE '' END)",
        'overtime',
        'Overtime'
    );
}

if (hasTable($conn, 'attendance_disputes')) {
    $loadRequests(
        'attendance_disputes',
        'dispute_id',
        'dispute_type',
        'reason',
        'req.dispute_date',
        'dispute',
        'Attendance Dispute'
    );
}

usort($items, function ($a, $b) {
    return strtotime((string)$b['date_filed']) <=> strtotime((string)$a['date_filed']);
});

echo json_encode($items);