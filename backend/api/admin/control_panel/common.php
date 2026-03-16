<?php
include __DIR__ . "/../../../config/database.php";
include __DIR__ . "/../../../config/auth.php";

function requireControlPanelAccess(mysqli $conn): void {
    requireRoleOrPermission(["super admin", "admin"], $conn, ["Access Control Panel", "View Employee List", "Delete Employee"]);
}

function logControlPanelAction(mysqli $conn, string $action, string $target): void {
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, target, created_at) VALUES (?, ?, ?, NOW())");
    if (!$stmt) {
        return;
    }

    $safeAction = trim($action);
    $safeTarget = trim($target);
    $stmt->bind_param("iss", $userId, $safeAction, $safeTarget);
    $stmt->execute();
}

function archivedEmployeePayload(mysqli_result $result): array {
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = [
            "id" => (int)$row['employee_id'],
            "user_id" => isset($row['user_id']) ? (int)$row['user_id'] : null,
            "first_name" => $row['first_name'],
            "middle_name" => $row['middle_name'],
            "last_name" => $row['last_name'],
            "fullname" => trim((string)$row['fullname']) ?: "Employee #{$row['employee_id']}",
            "position" => $row['position'],
            "employment_status" => $row['employment_status'],
            "email" => $row['email']
        ];
    }

    return $employees;
}
