<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("coach");

$data = json_decode(file_get_contents("php://input"), true);

$cluster_id = isset($data['cluster_id']) ? (int)$data['cluster_id'] : 0;
$employee_id = isset($data['employee_id']) ? (int)$data['employee_id'] : 0;
$schedule = $data['schedule'] ?? null;

if ($cluster_id <= 0 || $employee_id <= 0 || !is_array($schedule)) {
    http_response_code(400);
    exit(json_encode(["error" => "Invalid schedule payload."]));
}

$validDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$dayMap = [
    'Mon' => 'Monday',
    'Tue' => 'Tuesday',
    'Wed' => 'Wednesday',
    'Thu' => 'Thursday',
    'Fri' => 'Friday',
    'Sat' => 'Saturday',
    'Sun' => 'Sunday'
];

$shiftMap = [
    'Morning Shift' => 'Morning',
    'Mid Shift' => 'Mid',
    'Night Shift' => 'Night',
    'Morning' => 'Morning',
    'Mid' => 'Mid',
    'Night' => 'Night'
];

$workSetupMap = [
    'Onsite' => 'Onsite',
    'Work From Home (WFH)' => 'WFH',
    'WFH' => 'WFH',
    'Hybrid' => 'Hybrid'
];

function toSqlTime(?string $time, ?string $period): ?string {
    if ($time === null || $period === null) return null;

    $parts = explode(':', $time);
    if (count($parts) !== 2) return null;

    $hour = (int)$parts[0];
    $minute = (int)$parts[1];
    if ($hour < 1 || $hour > 12 || !in_array($minute, [0, 30], true)) {
        return null;
    }

    $normalizedHour = $hour % 12;
    if (strtoupper($period) === 'PM') {
        $normalizedHour += 12;
    }

    return sprintf('%02d:%02d:00', $normalizedHour, $minute);
}

function toSqlDatetime(?string $time, ?string $period): ?string {
    $sqlTime = toSqlTime($time, $period);
    if ($sqlTime === null) return null;
    return '2000-01-01 ' . $sqlTime;
}

$days = array_values(array_filter(
    array_unique(is_array($schedule['days'] ?? null) ? $schedule['days'] : []),
    fn($day) => in_array($day, $validDays, true)
));
$daySchedules = is_array($schedule['daySchedules'] ?? null) ? $schedule['daySchedules'] : [];

$conn->begin_transaction();

try {
    $deleteStmt = $conn->prepare("DELETE FROM schedules WHERE cluster_id=? AND employee_id=?");
    if (!$deleteStmt) {
        throw new Exception("Unable to prepare schedule cleanup.");
    }
    $deleteStmt->bind_param("ii", $cluster_id, $employee_id);
    if (!$deleteStmt->execute()) {
        throw new Exception("Unable to clear existing schedule.");
    }

    if (count($days) > 0) {
        $insertStmt = $conn->prepare(
            "INSERT INTO schedules (
                cluster_id,
                employee_id,
                day_of_week,
                shift_type,
                start_time,
                end_time,
                work_setup,
                breaksched_start,
                breaksched_end
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$insertStmt) {
            throw new Exception("Unable to prepare schedule save.");
        }

        foreach ($days as $day) {
            $entry = is_array($daySchedules[$day] ?? null) ? $daySchedules[$day] : [];

            $dayOfWeek = $dayMap[$day];
            $shiftType = $shiftMap[$entry['shiftType'] ?? 'Morning Shift'] ?? 'Morning';
            $startTime = toSqlTime($entry['startTime'] ?? '9:00', $entry['startPeriod'] ?? 'AM');
            $endTime = toSqlTime($entry['endTime'] ?? '6:00', $entry['endPeriod'] ?? 'PM');
            $workSetup = $workSetupMap[$entry['workSetup'] ?? 'Onsite'] ?? 'Onsite';
            $breakStart = toSqlDatetime($entry['breakStartTime'] ?? '3:00', $entry['breakStartPeriod'] ?? 'PM');
            $breakEnd = toSqlDatetime($entry['breakEndTime'] ?? '3:30', $entry['breakEndPeriod'] ?? 'PM');

            if ($startTime === null || $endTime === null || $breakStart === null || $breakEnd === null) {
                throw new Exception("Invalid schedule time values.");
            }

            $insertStmt->bind_param(
                "iisssssss",
                $cluster_id,
                $employee_id,
                $dayOfWeek,
                $shiftType,
                $startTime,
                $endTime,
                $workSetup,
                $breakStart,
                $breakEnd
            );

            if (!$insertStmt->execute()) {
                throw new Exception("Unable to save schedule.");
            }
        }
    }

    $conn->commit();
    echo json_encode(["success" => true]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}