<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("admin");

$data = json_decode(file_get_contents("php://input"), true);
$attendanceId = isset($data['attendance_id']) ? (int)$data['attendance_id'] : 0;
$timeInAt = $data['timeInAt'] ?? null;
$timeOutAt = $data['timeOutAt'] ?? null;
$tag = $data['tag'] ?? null;
$note = $data['note'] ?? "";

if ($attendanceId <= 0) {
  http_response_code(400);
  echo json_encode(["error" => "Invalid attendance id."]);
  exit;
}

$timeInSql = $timeInAt ? date("Y-m-d H:i:s", strtotime($timeInAt)) : null;
$timeOutSql = $timeOutAt ? date("Y-m-d H:i:s", strtotime($timeOutAt)) : null;
if ($timeInAt !== null && $timeInAt !== '' && !$timeInSql) {
  http_response_code(400);
  echo json_encode(["error" => "Invalid time in value."]);
  exit;
}
if ($timeOutAt !== null && $timeOutAt !== '' && !$timeOutSql) {
  http_response_code(400);
  echo json_encode(["error" => "Invalid time out value."]);
  exit;
}

$recordQuery = $conn->query("SELECT al.id, al.cluster_id, al.employee_id FROM attendance_logs al JOIN clusters c ON c.id = al.cluster_id WHERE al.id=$attendanceId AND c.user_id = al.employee_id LIMIT 1");
if (!$recordQuery || $recordQuery->num_rows === 0) {
  http_response_code(404);
  echo json_encode(["error" => "Coach attendance record not found."]);
  exit;
}

$timeInValue = $timeInSql ? "'" . $conn->real_escape_string($timeInSql) . "'" : "NULL";
$timeOutValue = $timeOutSql ? "'" . $conn->real_escape_string($timeOutSql) . "'" : "NULL";
$tagValue = ($tag !== null && $tag !== '') ? "'" . $conn->real_escape_string($tag) . "'" : "NULL";
$noteValue = "'" . $conn->real_escape_string((string)$note) . "'";

$ok = $conn->query("UPDATE attendance_logs SET time_in_at=$timeInValue, time_out_at=$timeOutValue, tag=$tagValue, note=$noteValue WHERE id=$attendanceId");
if (!$ok) {
  http_response_code(500);
  echo json_encode(["error" => "Unable to update coach attendance."]);
  exit;
}

echo json_encode(["success" => true]);