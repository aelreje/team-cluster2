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
session_start();

function normalizeRole(?string $roleName): string {
    $role = strtolower(trim((string)$roleName));
    if ($role === 'administrator') {
        return 'admin';
    }
    if (str_contains($role, 'admin')) {
        return 'admin';
    }
    if (str_contains($role, 'coach')) {
        return 'coach';
    }

    return 'employee';
}

$data = json_decode(file_get_contents("php://input"), true);

$email = strtolower(trim($data['email'] ?? ''));
$password = $data['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(["error" => "Email and password are required"]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT u.user_id,
            u.email,
            u.password,
            r.role_name,
            CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name) AS fullname
     FROM users u
     LEFT JOIN roles r ON u.role_id = r.role_id
     LEFT JOIN employees e ON e.user_id = u.user_id
     WHERE u.email = ?
     LIMIT 1"
);
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && password_verify($password, $user['password'])) {
    $role = normalizeRole($user['role_name'] ?? '');
    $redirect = '/employee';
    if ($role === 'admin') {
        $redirect = '/admin';
    } elseif ($role === 'coach') {
        $redirect = '/coach';
    }

    $fullname = trim((string)($user['fullname'] ?? ''));
    if ($fullname === '') {
        $fullname = strtok($email, '@') ?: $email;
    }

    $_SESSION['user'] = [
        'id' => (int)$user['user_id'],
        'fullname' => $fullname,
        'email' => $user['email'],
        'role' => $role
    ];

    echo json_encode([
        'success' => true,
        'role' => $role,
        'redirect' => $redirect,
        'fullname' => $fullname
    ]);
} else {
    http_response_code(401);
    echo json_encode(["error" => "Invalid credentials"]);
}