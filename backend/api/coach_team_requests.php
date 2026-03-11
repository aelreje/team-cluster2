<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("coach");

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

$coachId = (int)($_SESSION['user']['id'] ?? 0);
$clusterColumns = getColumns($conn, 'clusters');
$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$clusterOwnerColumn = in_array('coach_id', $clusterColumns, true) ? 'coach_id' : 'user_id';

$items = [];

$loadRequests = function (string $table, string $idColumn, string $typeColumn, string $detailsColumn, string $scheduleExpr, string $alias, string $defaultType) use ($conn, $coachId, $clusterIdColumn, $clusterOwnerColumn, &$items) {
    $sql = "SELECT
                req.$idColumn AS source_id,
                req.created_at AS filed_at,
                req.$typeColumn AS request_type,
                req.$detailsColumn AS details,
                $scheduleExpr AS schedule_period,
                req.status,
                req.employee_id,
                COALESCE(u.fullname, CONCAT('Employee #', req.employee_id)) AS employee_name
            FROM $table req
            INNER JOIN cluster_members cm ON cm.employee_id = req.employee_id
            INNER JOIN clusters c ON c.$clusterIdColumn = cm.cluster_id
            LEFT JOIN users u ON u.id = req.employee_id
            WHERE c.$clusterOwnerColumn = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $coachId);
    $stmt->execute();
    $res = $stmt->get_result();

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
            'employee_name' => $row['employee_name'] ?: 'Employee'
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