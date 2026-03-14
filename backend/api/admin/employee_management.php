<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";



function resolveEffectivePermissions(mysqli $conn, int $userId): array {
    $permissions = [];

    $roleStmt = $conn->prepare(
        "SELECT DISTINCT p.permission_name
         FROM users u
         INNER JOIN role_permissions rp ON rp.role_id = u.role_id
         INNER JOIN permissions p ON p.permission_id = rp.permission_id
         WHERE u.user_id = ?"
    );

    if ($roleStmt) {
        $roleStmt->bind_param("i", $userId);
        if ($roleStmt->execute()) {
            $result = $roleStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $name = trim((string)($row['permission_name'] ?? ''));
                if ($name !== '') {
                    $permissions[$name] = true;
                }
            }
        }
    }

    $overrideStmt = $conn->prepare(
        "SELECT p.permission_name, up.is_allowed
         FROM user_permissions up
         INNER JOIN permissions p ON p.permission_id = up.permission_id
         WHERE up.user_id = ?"
    );

    if ($overrideStmt) {
        $overrideStmt->bind_param("i", $userId);
        if ($overrideStmt->execute()) {
            $result = $overrideStmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $name = trim((string)($row['permission_name'] ?? ''));
                if ($name === '') continue;
                $isAllowed = (int)($row['is_allowed'] ?? 0) === 1;

                if ($isAllowed) {
                    $permissions[$name] = true;
                } else {
                    unset($permissions[$name]);
                }
            }
        }
    }

    return array_keys($permissions);
}

function requireAnyPermission(mysqli $conn, array $permissionNames): void {
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        exit(json_encode(["error" => "Unauthorized"]));
    }

    $permissions = resolveEffectivePermissions($conn, $userId);
    foreach ($permissionNames as $permissionName) {
        if (in_array($permissionName, $permissions, true)) {
            return;
        }
    }

    http_response_code(403);
    exit(json_encode(["error" => "Forbidden"]));
}
function resolveEmployeeRoleId(mysqli $conn): ?int {
    $stmt = $conn->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) LIKE '%employee%' LIMIT 1");
    if (!$stmt) return null;
    if (!$stmt->execute()) return null;

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    return isset($row['role_id']) ? (int)$row['role_id'] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAnyPermission($conn, ['View Employee List']);
    $sql = "SELECT
                e.employee_id,
                CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name) AS fullname,
                COALESCE(e.position, '') AS position,
                COALESCE(e.account, '') AS account,
                COALESCE(e.employee_type, '') AS employee_type,
                COALESCE(e.employment_status, '') AS employment_status,
                e.date_hired,
                COALESCE(e.email, u.email, '') AS email
            FROM employees e
            LEFT JOIN users u ON u.user_id = e.user_id
            ORDER BY e.employee_id DESC";

    $result = $conn->query($sql);
    if (!$result) {
        http_response_code(500);
        exit(json_encode(["error" => "Unable to load employees."]));
    }

    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = [
            "id" => (int)$row['employee_id'],
            "fullname" => trim((string)$row['fullname']) ?: "Employee #{$row['employee_id']}",
            "position" => $row['position'],
            "account" => $row['account'],
            "employee_type" => $row['employee_type'],
            "employment_status" => $row['employment_status'],
            "date_hired" => $row['date_hired'],
            "email" => $row['email']
        ];
    }

    echo json_encode($employees);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(["success" => false, "message" => "Method not allowed."]));
}

requireAnyPermission($conn, ['Add Employee']);

$data = json_decode(file_get_contents("php://input"), true);
if (!$data || !is_array($data)) {
    http_response_code(400);
    exit(json_encode(["success" => false, "message" => "Invalid JSON"]));
}

$firstName = trim((string)($data['first_name'] ?? ''));
$middleName = trim((string)($data['middle_name'] ?? ''));
$lastName = trim((string)($data['last_name'] ?? ''));
$address = trim((string)($data['address'] ?? ''));
$birthdate = trim((string)($data['birthdate'] ?? ''));
$civilStatus = trim((string)($data['civil_status'] ?? ''));
$email = strtolower(trim((string)($data['email'] ?? $data['work_email'] ?? '')));
$personalEmail = strtolower(trim((string)($data['personal_email'] ?? '')));
$position = trim((string)($data['position'] ?? ''));
$account = trim((string)($data['account'] ?? ''));
$contactNumber = trim((string)($data['contact_number'] ?? ''));
$employeeType = trim((string)($data['employee_type'] ?? ''));

if ($firstName === '' || $lastName === '' || $email === '') {
    http_response_code(400);
    exit(json_encode(["success" => false, "message" => "First name, last name, and email are required."]));
}

$employeeRoleId = resolveEmployeeRoleId($conn);
if (!$employeeRoleId) {
    http_response_code(500);
    exit(json_encode(["success" => false, "message" => "Employee role is not configured."]));
}

$conn->begin_transaction();

try {
    $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    if (!$check) {
        throw new Exception("Unable to validate email.");
    }

    $check->bind_param("s", $email);
    if (!$check->execute()) {
        throw new Exception("Unable to validate email.");
    }

    $result = $check->get_result();
    if ($result && $result->num_rows > 0) {
        throw new Exception("Email already exists.");
    }

    $firstLetter = strtolower(substr($firstName, 0, 1));
    $generatedPassword = $firstLetter . strtolower($lastName) . "@123!";
    $hashedPassword = password_hash($generatedPassword, PASSWORD_BCRYPT);

    $stmtUser = $conn->prepare(
        "INSERT INTO users (email, password, role_id, created_at)
         VALUES (?, ?, ?, NOW())"
    );
    if (!$stmtUser) {
        throw new Exception("Unable to prepare user statement.");
    }

    $stmtUser->bind_param("ssi", $email, $hashedPassword, $employeeRoleId);
    if (!$stmtUser->execute()) {
        throw new Exception("Unable to create user account.");
    }

    $userId = (int)$stmtUser->insert_id;

    $stmtEmp = $conn->prepare(
        "INSERT INTO employees (
            user_id,
            first_name,
            middle_name,
            last_name,
            address,
            birthdate,
            civil_status,
            email,
            personal_email,
            position,
            account,
            contact_number,
            employment_status,
            employee_type,
            date_hired
        )
        VALUES (
            ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, 'Active', ?, CURDATE()
        )"
    );
    if (!$stmtEmp) {
        throw new Exception("Unable to prepare employee statement.");
    }

    $stmtEmp->bind_param(
        "issssssssssss",
        $userId,
        $firstName,
        $middleName,
        $lastName,
        $address,
        $birthdate,
        $civilStatus,
        $email,
        $personalEmail,
        $position,
        $account,
        $contactNumber,
        $employeeType
    );

    if (!$stmtEmp->execute()) {
        throw new Exception("Unable to create employee profile.");
    }

    $conn->commit();

    echo json_encode([
        "success" => true,
        "generated_account" => [
            "email" => $email,
            "password" => $generatedPassword
        ]
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $error->getMessage()
    ]);
}