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

$data = json_decode(file_get_contents("php://input"), true);

$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

$stmt = $conn->prepare("SELECT * FROM users WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($user && password_verify($password, $user['password'])) {
    $role = strtolower($user['role']);
    $redirect = "/employee";
    if ($role === "admin") {
        $redirect = "/admin";
    } elseif ($role === "coach") {
        $redirect = "/coach";
    }

    $_SESSION['user'] = [
        "id" => (int)$user["id"],
        "fullname" => $user["fullname"],
        "email" => $user["email"],
        "role" => $user["role"]
    ];
    echo json_encode([
        "success" => true,
        "role" => $user['role'],
        "redirect" => $redirect,
        "fullname" => $user["fullname"]
    ]);
} else {
    http_response_code(401);
    echo json_encode(["error" => "Invalid credentials"]);
}