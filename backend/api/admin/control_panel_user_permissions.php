<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";

requireRole(["super admin", "admin"]);

function buildDisplayName(array $row): string {
    $name = trim(implode(' ', array_filter([
        trim((string)($row['first_name'] ?? '')),
        trim((string)($row['middle_name'] ?? '')),
        trim((string)($row['last_name'] ?? '')),
    ])));

    if ($name !== '') {
        return $name;
    }

    $email = trim((string)($row['email'] ?? ''));
    if ($email !== '') {
        return $email;
    }

    $userId = (int)($row['user_id'] ?? 0);
    return $userId > 0 ? "User #$userId" : 'Unknown User';
}

function getUserPermissions(mysqli $conn): array {
    $usersResult = $conn->query(
        "SELECT u.user_id,
                COALESCE(r.role_name, '') AS role_name,
                u.email,
                e.first_name,
                e.middle_name,
                e.last_name
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN employees e ON e.user_id = u.user_id
         ORDER BY u.user_id ASC"
    );

    if (!$usersResult) {
        return [];
    }

    $userMap = [];
    while ($row = $usersResult->fetch_assoc()) {
        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) continue;

        $userMap[$userId] = [
            'userId' => $userId,
            'id' => 'USR-' . str_pad((string)$userId, 3, '0', STR_PAD_LEFT),
            'name' => buildDisplayName($row),
            'role' => trim((string)($row['role_name'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'permissions' => []
        ];
    }

    if (count($userMap) === 0) {
        return [];
    }

    $rolePermissionsResult = $conn->query(
        "SELECT u.user_id,
                p.permission_name
         FROM users u
         INNER JOIN role_permissions rp ON rp.role_id = u.role_id
         INNER JOIN permissions p ON p.permission_id = rp.permission_id"
    );

    $effectivePermissionMap = [];
    if ($rolePermissionsResult) {
        while ($row = $rolePermissionsResult->fetch_assoc()) {
            $userId = (int)($row['user_id'] ?? 0);
            $permissionName = trim((string)($row['permission_name'] ?? ''));
            if ($userId <= 0 || $permissionName === '') continue;

            if (!isset($effectivePermissionMap[$userId])) {
                $effectivePermissionMap[$userId] = [];
            }

            $effectivePermissionMap[$userId][$permissionName] = true;
        }
    }

    $overridesResult = $conn->query(
        "SELECT up.user_id,
                p.permission_name,
                up.is_allowed
         FROM user_permissions up
         INNER JOIN permissions p ON p.permission_id = up.permission_id"
    );

    if ($overridesResult) {
        while ($row = $overridesResult->fetch_assoc()) {
            $userId = (int)($row['user_id'] ?? 0);
            $permissionName = trim((string)($row['permission_name'] ?? ''));
            $isAllowed = (int)($row['is_allowed'] ?? 0) === 1;
            if ($userId <= 0 || $permissionName === '') continue;

            if (!isset($effectivePermissionMap[$userId])) {
                $effectivePermissionMap[$userId] = [];
            }

            if ($isAllowed) {
                $effectivePermissionMap[$userId][$permissionName] = true;
            } else {
                unset($effectivePermissionMap[$userId][$permissionName]);
            }
        }
    }

    foreach ($userMap as $userId => $user) {
        $permissions = array_keys($effectivePermissionMap[$userId] ?? []);
        sort($permissions, SORT_NATURAL | SORT_FLAG_CASE);
        $userMap[$userId]['permissions'] = $permissions;
    }

    return array_values($userMap);
}

function getRolePermissionIdMap(mysqli $conn, int $userId): array {
    $stmt = $conn->prepare(
        "SELECT rp.permission_id
         FROM users u
         INNER JOIN role_permissions rp ON rp.role_id = u.role_id
         WHERE u.user_id = ?"
    );

    if (!$stmt) {
        throw new Exception('Failed to prepare role permission lookup statement');
    }

    $stmt->bind_param("i", $userId);
    if (!$stmt->execute()) {
        throw new Exception('Failed to execute role permission lookup');
    }

    $result = $stmt->get_result();
    $permissionMap = [];
    while ($row = $result->fetch_assoc()) {
        $permissionId = (int)($row['permission_id'] ?? 0);
        if ($permissionId > 0) {
            $permissionMap[$permissionId] = true;
        }
    }

    return $permissionMap;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$userId = (int)($payload['user_id'] ?? 0);
$permissionIds = $payload['permission_ids'] ?? null;

if ($userId <= 0 || !is_array($permissionIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'user_id and permission_ids are required']);
    exit;
}

$cleanPermissionIds = [];
foreach ($permissionIds as $permissionId) {
    $value = (int)$permissionId;
    if ($value > 0) {
        $cleanPermissionIds[$value] = true;
    }
}
$cleanPermissionIds = array_keys($cleanPermissionIds);

$conn->begin_transaction();

try {
    $deleteStmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
    if (!$deleteStmt) {
        throw new Exception('Failed to prepare user permission delete statement');
    }

    $deleteStmt->bind_param("i", $userId);
    if (!$deleteStmt->execute()) {
        throw new Exception('Failed to clear user permissions');
    }

    $rolePermissionMap = getRolePermissionIdMap($conn, $userId);
    $selectedPermissionMap = array_fill_keys($cleanPermissionIds, true);

    $overrideRows = [];

    foreach ($selectedPermissionMap as $permissionId => $_) {
        if (!isset($rolePermissionMap[$permissionId])) {
            $overrideRows[] = [(int)$permissionId, 1];
        }
    }

    foreach ($rolePermissionMap as $permissionId => $_) {
        if (!isset($selectedPermissionMap[$permissionId])) {
            $overrideRows[] = [(int)$permissionId, 0];
        }
    }

    if (count($overrideRows) > 0) {
        $insertStmt = $conn->prepare("INSERT INTO user_permissions (user_id, permission_id, is_allowed) VALUES (?, ?, ?)");
        if (!$insertStmt) {
            throw new Exception('Failed to prepare user permission insert statement');
        }

        foreach ($overrideRows as [$permissionId, $isAllowed]) {
            $insertStmt->bind_param("iii", $userId, $permissionId, $isAllowed);
            if (!$insertStmt->execute()) {
                throw new Exception('Failed to save user permissions');
            }
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'userPermissions' => getUserPermissions($conn)
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $error->getMessage()
    ]);
}
