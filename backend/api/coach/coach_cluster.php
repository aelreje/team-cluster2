<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
requireRole("coach");

header("Content-Type: application/json");

$columns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM clusters");
if ($columnResult) {
    while ($row = $columnResult->fetch_assoc()) {
        $columns[] = $row["Field"];
    }
}

$idColumn = in_array("id", $columns, true) ? "id" : "cluster_id";
$ownerColumn = in_array("coach_id", $columns, true) ? "coach_id" : "user_id";
$coachId = (int)$_SESSION["user"]["id"];

$stmt = $conn->prepare(
    "SELECT c.$idColumn AS id,
            c.name,
            c.description,
            c.status,
            c.created_at,
            c.rejection_reason,
            COUNT(cm.employee_id) AS members
     FROM clusters c
     LEFT JOIN cluster_members cm ON cm.cluster_id = c.$idColumn
     WHERE c.$ownerColumn = ?
     GROUP BY c.$idColumn
     ORDER BY c.created_at DESC"
);

$stmt->bind_param("i", $coachId);
$stmt->execute();
$res = $stmt->get_result();

function sqlTimeToUi(?string $value): array {
    if (!$value) return ['9:00', 'AM'];

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        $parts = explode(':', $value);
        if (count($parts) < 2) return ['9:00', 'AM'];

        $hour24 = (int)$parts[0];
        $minute = (int)$parts[1];
    } else {
        $hour24 = (int)date('G', $timestamp);
        $minute = (int)date('i', $timestamp);
    }

    $period = $hour24 >= 12 ? 'PM' : 'AM';
    $hour12 = $hour24 % 12;
    if ($hour12 === 0) $hour12 = 12;

    return [sprintf('%d:%02d', $hour12, $minute), $period];
}

function loadCoachSchedule(mysqli $conn, int $clusterId, int $coachId): ?array {
    $employeeStmt = $conn->prepare("SELECT employee_id FROM employees WHERE user_id = ? LIMIT 1");
    if (!$employeeStmt) return null;
    $employeeStmt->bind_param("i", $coachId);
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

$clusters = [];
while ($row = $res->fetch_assoc()) {
    $row["id"] = (int)$row["id"];
    $row["members"] = (int)$row["members"];
    $row["coach_schedule"] = loadCoachSchedule($conn, (int)$row["id"], $coachId);
    $clusters[] = $row;
}

echo json_encode($clusters);