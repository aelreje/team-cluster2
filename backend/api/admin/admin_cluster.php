<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
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
            MAX(e.employee_id) AS coach_employee_id,
            COUNT(cm.employee_id) AS members
     FROM clusters c
     LEFT JOIN users u ON c.$ownerColumn = u.$userIdColumn
     LEFT JOIN employees e ON e.user_id = u.$userIdColumn
     LEFT JOIN cluster_members cm ON c.$clusterIdColumn = cm.cluster_id
     GROUP BY c.$clusterIdColumn, c.name, c.description, c.created_at, c.status, c.rejection_reason, coach
     ORDER BY c.created_at DESC"
);

if ($res === false) {
    http_response_code(500);
    exit(json_encode(["error" => "Failed to load clusters."]));
}

$out = [];

function sqlTimeToUi(?string $value): array {
    if (!$value) return ['9:00', 'AM'];

    $parts = explode(':', $value);
    if (count($parts) < 2) return ['9:00', 'AM'];

    $hour24 = (int)$parts[0];
    $minute = (int)$parts[1];
    $period = $hour24 >= 12 ? 'PM' : 'AM';
    $hour12 = $hour24 % 12;
    if ($hour12 === 0) $hour12 = 12;

    return [sprintf('%d:%02d', $hour12, $minute), $period];
}

function loadCoachSchedule(mysqli $conn, int $clusterId, int $coachUserId): ?array {
    $employeeStmt = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1");
    if (!$employeeStmt) return null;
    $employeeStmt->bind_param("i", $coachUserId);
    $employeeStmt->execute();
    $employeeRes = $employeeStmt->get_result();
    $employeeRow = $employeeRes ? $employeeRes->fetch_assoc() : null;
    $coachEmployeeId = isset($employeeRow['employee_id']) ? (int)$employeeRow['employee_id'] : 0;

    if ($coachEmployeeId <= 0) return null;

    $scheduleStmt = $conn->prepare(
        "SELECT day_of_week, shift_type, start_time, end_time, work_setup, breaksched_start, breaksched_end
         FROM schedules
         WHERE cluster_id = ? AND employee_id = ?
         ORDER BY schedule_id ASC"
    );
    if (!$scheduleStmt) return null;
    $scheduleStmt->bind_param("ii", $clusterId, $coachEmployeeId);
    $scheduleStmt->execute();
    $scheduleRes = $scheduleStmt->get_result();

    $dayToShort = [
        'Monday' => 'Mon',
        'Tuesday' => 'Tue',
        'Wednesday' => 'Wed',
        'Thursday' => 'Thu',
        'Friday' => 'Fri',
        'Saturday' => 'Sat',
        'Sunday' => 'Sun'
    ];
    $shiftToUi = [
        'Morning' => 'Morning Shift',
        'Mid' => 'Mid Shift',
        'Night' => 'Night Shift'
    ];
    $workSetupToUi = [
        'Onsite' => 'Onsite',
        'WFH' => 'Work From Home (WFH)',
        'Hybrid' => 'Hybrid'
    ];

    $schedule = ['days' => [], 'daySchedules' => []];

    while ($row = $scheduleRes->fetch_assoc()) {
        $shortDay = $dayToShort[$row['day_of_week'] ?? ''] ?? null;
        if (!$shortDay) continue;

        [$startTime, $startPeriod] = sqlTimeToUi($row['start_time'] ?? null);
        [$endTime, $endPeriod] = sqlTimeToUi($row['end_time'] ?? null);
        [$breakStartTime, $breakStartPeriod] = sqlTimeToUi($row['breaksched_start'] ?? null);
        [$breakEndTime, $breakEndPeriod] = sqlTimeToUi($row['breaksched_end'] ?? null);

        if (!in_array($shortDay, $schedule['days'], true)) {
            $schedule['days'][] = $shortDay;
        }

        $schedule['daySchedules'][$shortDay] = [
            'shiftType' => $shiftToUi[$row['shift_type'] ?? ''] ?? 'Morning Shift',
            'startTime' => $startTime,
            'startPeriod' => $startPeriod,
            'endTime' => $endTime,
            'endPeriod' => $endPeriod,
            'workSetup' => $workSetupToUi[$row['work_setup'] ?? ''] ?? 'Onsite',
            'breakStartTime' => $breakStartTime,
            'breakStartPeriod' => $breakStartPeriod,
            'breakEndTime' => $breakEndTime,
            'breakEndPeriod' => $breakEndPeriod
        ];
    }

    return count($schedule['days']) > 0 ? $schedule : null;
}

while ($r = $res->fetch_assoc()) {
    $clusterId = (int)$r['id'];
    $coachEmployeeId = isset($r['coach_employee_id']) ? (int)$r['coach_employee_id'] : 0;
    $coachUserId = 0;

    if ($coachEmployeeId > 0) {
        $coachStmt = $conn->prepare("SELECT user_id FROM employees WHERE employee_id = ? LIMIT 1");
        if ($coachStmt) {
            $coachStmt->bind_param("i", $coachEmployeeId);
            $coachStmt->execute();
            $coachRes = $coachStmt->get_result();
            $coachRow = $coachRes ? $coachRes->fetch_assoc() : null;
            $coachUserId = isset($coachRow['user_id']) ? (int)$coachRow['user_id'] : 0;
        }
    }

    $r['coach_schedule'] = $coachUserId > 0 ? loadCoachSchedule($conn, $clusterId, $coachUserId) : null;
    $out[] = $r;
}

echo json_encode($out);