<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include "../config/database.php";

function resolveRoleId(mysqli $conn, string $role): ?int {
    $stmt = $conn->prepare(
        "SELECT role_id, role_name
         FROM roles"
    );
    if (!$stmt || !$stmt->execute()) {
        return null;
    }

    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dbRole = strtolower((string)($row['role_name'] ?? ''));
        if ($dbRole === $role || str_contains($dbRole, $role)) {
            return (int)$row['role_id'];
        }
        if ($role === 'admin' && str_contains($dbRole, 'administrator')) {
            return (int)$row['role_id'];
        }
    }

    return null;
}

$data = json_decode(file_get_contents("php://input"), true);

$fullname = trim($data['fullname'] ?? '');
$email = strtolower(trim($data['email'] ?? ''));
$password = $data['password'] ?? '';
$role = strtolower(trim($data['role'] ?? ''));

if (!$fullname || !$email || !$password || !$role) {
    http_response_code(400);
    echo json_encode(["error" => "All fields required"]);
    exit;
}

if (!in_array($role, ["coach", "employee", "admin"], true)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid role"]);
    exit;
}

$check = $conn->prepare("SELECT user_id FROM users WHERE email=?");
$check->bind_param("s", $email);
if (!$check->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to validate email"]);
    exit;
}
$check->store_result();

if ($check->num_rows > 0) {
    http_response_code(409);
    echo json_encode(["error" => "Email already exists"]);
    exit;
}

$roleId = resolveRoleId($conn, $role);
if ($roleId === null) {
    http_response_code(500);
    echo json_encode(["error" => "Role configuration not found in database"]);
    exit;
}

$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO users (email, password, role_id, created_at)
     VALUES (?, ?, ?, NOW())"
);
$stmt->bind_param("ssi", $email, $hashed, $roleId);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Unable to create account"]);
    exit;
}

$userId = (int)$stmt->insert_id;

$nameParts = preg_split('/\s+/', $fullname) ?: [];
$firstName = array_shift($nameParts) ?? $fullname;
$lastName = implode(' ', $nameParts) ?: null;

$employeeStmt = $conn->prepare(
    "INSERT INTO employees (user_id, first_name, last_name, email)
     VALUES (?, ?, ?, ?)"
);
if ($employeeStmt) {
    $employeeStmt->bind_param("isss", $userId, $firstName, $lastName, $email);
    $employeeStmt->execute();
}

echo json_encode([
    "success" => true,
    "user_id" => $userId
]);