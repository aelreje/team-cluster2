<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("admin");

header("Content-Type: application/json");

$clusterColumns = [];
$clusterColumnResult = $conn->query("SHOW COLUMNS FROM clusters");
if ($clusterColumnResult) {
    while ($row = $clusterColumnResult->fetch_assoc()) {
        $clusterColumns[] = $row["Field"];
    }
}

$userColumns = [];
$userColumnResult = $conn->query("SHOW COLUMNS FROM users");
if ($userColumnResult) {
    while ($row = $userColumnResult->fetch_assoc()) {
        $userColumns[] = $row["Field"];
    }
}

$clusterIdColumn = in_array("id", $clusterColumns, true) ? "id" : "cluster_id";
$ownerColumn = in_array("coach_id", $clusterColumns, true) ? "coach_id" : "user_id";
$userIdColumn = in_array("id", $userColumns, true) ? "id" : "user_id";

if (in_array("fullname", $userColumns, true)) {
    $userDisplayExpr = "u.fullname";
} elseif (in_array("email", $userColumns, true)) {
    $userDisplayExpr = "u.email";
} else {
    $userDisplayExpr = "'Unknown'";
}

$res = $conn->query(
     "SELECT c.$clusterIdColumn AS id,
            c.name,
            c.description,
            c.created_at,
            c.status,
            c.rejection_reason,
            COALESCE($userDisplayExpr, 'Unknown') AS coach,
            COUNT(cm.employee_id) AS members
     FROM clusters c
     LEFT JOIN users u ON c.$ownerColumn = u.$userIdColumn
     LEFT JOIN cluster_members cm ON c.$clusterIdColumn = cm.cluster_id
     GROUP BY c.$clusterIdColumn, c.name, c.description, c.created_at, c.status, c.rejection_reason, coach
     ORDER BY c.created_at DESC"
);

if ($res === false) {
    http_response_code(500);
    exit(json_encode(["error" => "Failed to load clusters."]));
}

$out = [];
while ($r = $res->fetch_assoc()) {
    $out[] = $r;
}

echo json_encode($out);