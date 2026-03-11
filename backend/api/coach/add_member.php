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

$data = json_decode(file_get_contents("php://input"), true) ?? [];
$cluster_id = (int)($data['cluster_id'] ?? 0);
$coachId = (int)($_SESSION['user']['id'] ?? 0);

$employeeIds = [];
if (isset($data['employee_ids']) && is_array($data['employee_ids'])) {
    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $data['employee_ids']), fn($id) => $id > 0)));
} elseif (isset($data['employee_id'])) {
    $employeeId = (int)$data['employee_id'];
    if ($employeeId > 0) {
        $employeeIds = [$employeeId];
    }
}

if ($cluster_id === 0 || count($employeeIds) === 0) {
    http_response_code(400);
    exit(json_encode(["error" => "Missing cluster or employee(s)."]));
}

$clusterColumns = getColumns($conn, 'clusters');
$userColumns = getColumns($conn, 'users');
$employeeColumns = getColumns($conn, 'employees');

$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$ownerColumn = in_array('coach_id', $clusterColumns, true) ? 'coach_id' : 'user_id';
$userIdColumn = in_array('id', $userColumns, true) ? 'id' : 'user_id';
$roleColumn = in_array('role', $userColumns, true) ? 'role' : null;
$employeeIdColumn = in_array('employee_id', $employeeColumns, true) ? 'employee_id' : null;
$employeeUserIdColumn = in_array('user_id', $employeeColumns, true) ? 'user_id' : null;
$clusterMemberEmployeeReference = getClusterMemberEmployeeReference($conn);

$clusterStmt = $conn->prepare("SELECT $clusterIdColumn FROM clusters WHERE $clusterIdColumn = ? AND $ownerColumn = ? LIMIT 1");
$clusterStmt->bind_param("ii", $cluster_id, $coachId);
$clusterStmt->execute();
$clusterRes = $clusterStmt->get_result();
if (!$clusterRes || $clusterRes->num_rows === 0) {
    http_response_code(403);
    exit(json_encode(["error" => "Not authorized to update this cluster."]));
}

$insertStmt = $conn->prepare(
    "INSERT IGNORE INTO cluster_members (cluster_id, employee_id)
     VALUES (?, ?)"
);

    $addedMembers = [];

foreach ($employeeIds as $employee_id) {
    $addedMember = null;

    if (
        $clusterMemberEmployeeReference === 'employees'
        && $employeeIdColumn
        && $employeeUserIdColumn
    ) {
        $employeeStmt = $conn->prepare(
            "SELECT e.$employeeIdColumn AS id,
                    " . (in_array('fullname', $userColumns, true)
                        ? "u.fullname"
                        : "TRIM(CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name))") . " AS fullname
            FROM employees e
            JOIN users u ON u.$userIdColumn = e.$employeeUserIdColumn
            WHERE e.$employeeIdColumn = ?"
            . ($roleColumn ? " AND u.$roleColumn = 'employee'" : '') .
            " LIMIT 1"
        );
    } else {
        $nameExpr = in_array('fullname', $userColumns, true)
            ? 'u.fullname'
            : "TRIM(CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name))";

        $employeeJoin = in_array('user_id', $employeeColumns, true)
            ? "LEFT JOIN employees e ON e.user_id = u.$userIdColumn"
            : '';

        $employeeStmt = $conn->prepare(
            "SELECT u.$userIdColumn AS id,
                    $nameExpr AS fullname
            FROM users u
            $employeeJoin
            WHERE u.$userIdColumn = ?"
            . ($roleColumn ? " AND u.$roleColumn = 'employee'" : '') .
            " LIMIT 1"
        );
    }

    $employeeStmt->bind_param("i", $employee_id);
    $employeeStmt->execute();
    $employeeRes = $employeeStmt->get_result();

    if (!$employeeRes || $employeeRes->num_rows === 0) {
        continue;
    }

    $addedMember = $employeeRes->fetch_assoc();

$insertStmt->bind_param("ii", $cluster_id, $employee_id);
    $insertStmt->execute();

if ($insertStmt->errno) {
        http_response_code(500);
        exit(json_encode(["error" => "Unable to add member(s)."]));
    }

if ($insertStmt->affected_rows > 0) {
        $addedMember['id'] = (int)$addedMember['id'];
        $addedMember['fullname'] = trim((string)$addedMember['fullname']);
        if ($addedMember['fullname'] === '') {
            $addedMember['fullname'] = "Employee #{$addedMember['id']}";
        }

        $addedMembers[] = $addedMember;
    }
}

echo json_encode([
    "added" => $addedMembers,
    "added_count" => count($addedMembers)
]);