<?php
include "../config/database.php";
include "../config/auth.php";
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

$userColumns = getColumns($conn, 'users');
$employeeColumns = getColumns($conn, 'employees');

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

$res = $conn->query(
    "SELECT u.$userIdColumn AS id,
            $nameExpr AS fullname
     FROM users u
     LEFT JOIN cluster_members cm
       ON u.$userIdColumn = cm.employee_id
     $roleJoin
     $employeeJoin
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