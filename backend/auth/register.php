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

$data = json_decode(file_get_contents("php://input"), true);

$fullname = trim($data['fullname'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$role = $data['role'] ?? '';

if (!$fullname || !$email || !$password || !$role) {
    http_response_code(400);
    echo json_encode(["error" => "All fields required"]);
    exit;
}

if (!in_array($role, ["coach", "employee", "admin"])) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid role"]);
    exit;
}

$check = $conn->prepare("SELECT id FROM users WHERE email=?");
$check->bind_param("s", $email);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    http_response_code(409);
    echo json_encode(["error" => "Email already exists"]);
    exit;
}

$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO users (fullname, email, password, role)
     VALUES (?, ?, ?, ?)"
);
$stmt->bind_param("ssss", $fullname, $email, $hashed, $role);
$stmt->execute();

echo json_encode(["success" => true]);