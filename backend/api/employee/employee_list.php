<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
requireRole("coach");

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

$userColumns = getColumns($conn, 'users');
$employeeColumns = getColumns($conn, 'employees');
$clusterMemberEmployeeReference = getClusterMemberEmployeeReference($conn);

$userIdColumn = in_array('id', $userColumns, true) ? 'id' : 'user_id';
$roleColumn = in_array('role', $userColumns, true) ? 'role' : null;
$roleIdColumn = in_array('role_id', $userColumns, true) ? 'role_id' : null;
$nameExpr = in_array('fullname', $userColumns, true)
    ? 'u.fullname'
    : "TRIM(CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name))";

$employeeJoin = in_array('user_id', $employeeColumns, true)
    ? "LEFT JOIN employees e ON e.user_id = u.$userIdColumn"
    : '';

$whereRole = $roleColumn ? "AND u.$roleColumn='employee'" : '';
$roleJoin = '';

if ($whereRole === '' && $roleIdColumn) {
    $roleTableColumns = getColumns($conn, 'roles');
    $roleNameColumn = in_array('role_name', $roleTableColumns, true) ? 'role_name' : null;

    if ($roleNameColumn) {
        $roleJoin = "LEFT JOIN roles r ON r.role_id = u.$roleIdColumn";
        $whereRole = "AND LOWER(COALESCE(r.$roleNameColumn, '')) = 'employee'";
    }
}

$employeeIdColumn = in_array('employee_id', $employeeColumns, true) ? 'employee_id' : null;
$employeeUserIdColumn = in_array('user_id', $employeeColumns, true) ? 'user_id' : null;

$selectIdExpr = "u.$userIdColumn";
$clusterMemberJoin = "u.$userIdColumn = cm.employee_id";
$fromClause = "FROM users u\n     $roleJoin\n     $employeeJoin";

if (
    $clusterMemberEmployeeReference === 'employees'
    && $employeeIdColumn
    && $employeeUserIdColumn
) {
    $selectIdExpr = "e.$employeeIdColumn";
    $clusterMemberJoin = "e.$employeeIdColumn = cm.employee_id";
    $fromClause = "FROM employees e
     JOIN users u ON e.$employeeUserIdColumn = u.$userIdColumn
     $roleJoin";
}

$res = $conn->query(
    "SELECT $selectIdExpr AS id,
            $nameExpr AS fullname
     $fromClause
     LEFT JOIN cluster_members cm
       ON $clusterMemberJoin
     WHERE cm.employee_id IS NULL
       $whereRole
     ORDER BY fullname ASC"
);

if (!$res) {
    http_response_code(500);
    exit(json_encode(["error" => "Unable to load employees."]));
}

$employees = [];
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['fullname'] = trim((string)$row['fullname']);
    if ($row['fullname'] === '') {
        $row['fullname'] = "Employee #{$row['id']}";
    }
    $employees[] = $row;
}

echo json_encode($employees);