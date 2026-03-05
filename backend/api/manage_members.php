<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("coach");

$cluster_id = isset($_GET['cluster_id']) ? (int)$_GET['cluster_id'] : 0;
if ($cluster_id <= 0) {
    http_response_code(400);
    exit(json_encode(["error" => "Invalid cluster id"]));
}

$attendance_date = isset($_GET['attendance_date']) ? trim($_GET['attendance_date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date)) {
    $attendance_date = date('Y-m-d');
}

function getColumns(mysqli $conn, string $table): array {
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function sqlTimeToUi(?string $value): array {
    if (!$value) return ['9:00', 'AM'];

    $date = date_create($value);
    if (!$date) {
        $parts = explode(':', $value);
        if (count($parts) < 2) return ['9:00', 'AM'];
        $hour24 = (int)$parts[0];
        $minute = (int)$parts[1];
    } else {
        $hour24 = (int)$date->format('G');
        $minute = (int)$date->format('i');
    }

    $period = $hour24 >= 12 ? 'PM' : 'AM';
    $hour12 = $hour24 % 12;
    if ($hour12 === 0) $hour12 = 12;

    return [sprintf('%d:%02d', $hour12, $minute), $period];
}

$userColumns = getColumns($conn, 'users');
$employeeColumns = getColumns($conn, 'employees');
$attendanceColumns = getColumns($conn, 'attendance_logs');
$timeLogColumns = getColumns($conn, 'time_logs');

$userIdColumn = in_array('id', $userColumns, true) ? 'id' : 'user_id';
$memberNameExpr = in_array('fullname', $userColumns, true)
    ? 'u.fullname'
    : "TRIM(CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name))";

$hasLegacyAttendance = in_array('id', $attendanceColumns, true)
    && in_array('time_in_at', $attendanceColumns, true)
    && in_array('time_out_at', $attendanceColumns, true)
    && in_array('tag', $attendanceColumns, true);

$hasNewAttendance = in_array('attendance_id', $attendanceColumns, true)
    && in_array('attendance_status', $attendanceColumns, true)
    && in_array('attendance_date', $attendanceColumns, true);

$timeLogPrimaryKey = in_array('time_log_id', $timeLogColumns, true)
    ? 'time_log_id'
    : (in_array('id', $timeLogColumns, true) ? 'id' : null);
$timeLogOrderColumn = $timeLogPrimaryKey
    ?? (in_array('updated_at', $timeLogColumns, true)
        ? 'updated_at'
        : (in_array('time_in', $timeLogColumns, true) ? 'time_in' : null));

$hasTimeLogs = $timeLogPrimaryKey
    && in_array('attendance_id', $timeLogColumns, true)
    && in_array('time_in', $timeLogColumns, true);

$hasTimeLogTimeOut = in_array('time_out', $timeLogColumns, true);
$hasTimeLogTag = in_array('tag', $timeLogColumns, true);

$escapedAttendanceDate = $conn->real_escape_string($attendance_date);

$attendanceJoin = 'NULL AS attendance_tag, NULL AS attendance_note, NULL AS time_in_at, NULL AS time_out_at';
if ($hasLegacyAttendance) {
    $attendanceJoin = "al.tag AS attendance_tag,
            al.note AS attendance_note,
            al.time_in_at,
            al.time_out_at";
} elseif ($hasNewAttendance) {
    $attendanceJoin = "" . ($hasTimeLogs ? ($hasTimeLogTag ? 'COALESCE(tl.tag, al.attendance_status)' : 'al.attendance_status') : 'al.attendance_status') . " AS attendance_tag,
            al.note AS attendance_note,
            " . ($hasTimeLogs ? 'tl.time_in' : 'NULL') . " AS time_in_at,
            " . ($hasTimeLogs && $hasTimeLogTimeOut ? 'tl.time_out' : 'NULL') . " AS time_out_at";
}

$attendanceLeftJoin = '';
if ($hasLegacyAttendance) {
    $attendanceLeftJoin = "LEFT JOIN attendance_logs al
        ON al.id = (
            SELECT al2.id
            FROM attendance_logs al2
            WHERE al2.cluster_id = cm.cluster_id
              AND al2.employee_id = cm.employee_id
              AND DATE(COALESCE(al2.time_in_at, al2.time_out_at, al2.updated_at)) = '$escapedAttendanceDate'
            ORDER BY COALESCE(al2.time_in_at, al2.time_out_at, al2.updated_at) DESC, al2.id DESC
            LIMIT 1
        )";
} elseif ($hasNewAttendance) {
    $attendanceLeftJoin = "LEFT JOIN attendance_logs al
        ON al.attendance_id = (
            SELECT al2.attendance_id
            FROM attendance_logs al2
            WHERE al2.cluster_id = cm.cluster_id
              AND al2.employee_id = cm.employee_id
              AND al2.attendance_date = '$escapedAttendanceDate'
            ORDER BY al2.updated_at DESC, al2.attendance_id DESC
            LIMIT 1
        )";

    if ($hasTimeLogs) {
        $attendanceLeftJoin .= "
        LEFT JOIN time_logs tl
          ON tl.$timeLogPrimaryKey = (
              SELECT t2.$timeLogPrimaryKey
              FROM time_logs t2
              WHERE t2.attendance_id = al.attendance_id
              " . ($timeLogOrderColumn ? "ORDER BY t2.$timeLogOrderColumn DESC" : "") . "
              LIMIT 1
          )";
    }
}

$userJoin = in_array('user_id', $employeeColumns, true)
    ? "LEFT JOIN employees e ON e.user_id = u.$userIdColumn"
    : '';

$res = $conn->query(
    "SELECT u.$userIdColumn AS id,
            $memberNameExpr AS fullname,
            $attendanceJoin
     FROM cluster_members cm
     JOIN users u ON cm.employee_id = u.$userIdColumn
     $userJoin
     $attendanceLeftJoin
     WHERE cm.cluster_id=$cluster_id"
);

if (!$res) {
    http_response_code(500);
    exit(json_encode(["error" => "Unable to load team members."]));
}

$members = [];
while ($m = $res->fetch_assoc()) {
    $id = (int)$m['id'];
    $m['id'] = $id;
    $m['fullname'] = trim((string)$m['fullname']);
    if ($m['fullname'] === '') {
        $m['fullname'] = "Employee #{$id}";
    }
    $m['attendance_history'] = [];
    $m['schedule'] = null;
    $members[$id] = $m;
}

$scheduleRes = $conn->query(
    "SELECT employee_id,
            day_of_week,
            shift_type,
            start_time,
            end_time,
            work_setup,
            breaksched_start,
            breaksched_end
     FROM schedules
     WHERE cluster_id=$cluster_id
     ORDER BY schedule_id ASC"
);

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

$schedulesByEmployee = [];
if ($scheduleRes) {
    while ($row = $scheduleRes->fetch_assoc()) {
        $employeeId = (int)$row['employee_id'];
        if (!isset($members[$employeeId])) continue;

        $shortDay = $dayToShort[$row['day_of_week'] ?? ''] ?? null;
        if ($shortDay === null) continue;

        if (!isset($schedulesByEmployee[$employeeId])) {
            $schedulesByEmployee[$employeeId] = [
                'days' => [],
                'daySchedules' => []
            ];
        }

        [$startTime, $startPeriod] = sqlTimeToUi($row['start_time'] ?? null);
        [$endTime, $endPeriod] = sqlTimeToUi($row['end_time'] ?? null);
        [$breakStartTime, $breakStartPeriod] = sqlTimeToUi($row['breaksched_start'] ?? null);
        [$breakEndTime, $breakEndPeriod] = sqlTimeToUi($row['breaksched_end'] ?? null);

        if (!in_array($shortDay, $schedulesByEmployee[$employeeId]['days'], true)) {
            $schedulesByEmployee[$employeeId]['days'][] = $shortDay;
        }

        $schedulesByEmployee[$employeeId]['daySchedules'][$shortDay] = [
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
}

$historyByEmployee = [];
if ($hasLegacyAttendance) {
    $historyRes = $conn->query(
        "SELECT id,
                employee_id,
                DATE_FORMAT(COALESCE(time_in_at, time_out_at, updated_at), '%Y-%m') AS month_key,
                DATE_FORMAT(COALESCE(time_in_at, time_out_at, updated_at), '%M %Y') AS month_label,
                time_in_at,
                time_out_at,
                tag,
                note
         FROM attendance_logs
         WHERE cluster_id=$cluster_id
         ORDER BY COALESCE(time_in_at, time_out_at, updated_at) DESC, id DESC"
    );

    if ($historyRes) {
        while ($history = $historyRes->fetch_assoc()) {
            $employeeId = (int)$history['employee_id'];
            $monthKey = $history['month_key'];
            if (!isset($historyByEmployee[$employeeId][$monthKey])) {
                $historyByEmployee[$employeeId][$monthKey] = [
                    'month' => $history['month_label'],
                    'entries' => []
                ];
            }

            $historyByEmployee[$employeeId][$monthKey]['entries'][] = [
                'id' => (int)$history['id'],
                'time_in_at' => $history['time_in_at'],
                'time_out_at' => $history['time_out_at'],
                'tag' => $history['tag'],
                'note' => $history['note']
            ];
        }
    }
} elseif ($hasNewAttendance) {
    $historyTimeInSelect = $hasTimeLogs ? 'tl.time_in' : 'NULL';
    $historyTimeOutSelect = ($hasTimeLogs && $hasTimeLogTimeOut) ? 'tl.time_out' : 'NULL';
    $historyTagSelect = $hasTimeLogs
        ? ($hasTimeLogTag ? 'COALESCE(tl.tag, al.attendance_status)' : 'al.attendance_status')
        : 'al.attendance_status';

    $historySql = "SELECT al.attendance_id,
                al.employee_id,
                DATE_FORMAT(COALESCE(al.attendance_date, al.updated_at), '%Y-%m') AS month_key,
                DATE_FORMAT(COALESCE(al.attendance_date, al.updated_at), '%M %Y') AS month_label,
                $historyTimeInSelect AS time_in_at,
                $historyTimeOutSelect AS time_out_at,
                $historyTagSelect AS attendance_status,
                al.note,
                al.attendance_date
         FROM attendance_logs al";

    if ($hasTimeLogs) {
        $historySql .= "
         LEFT JOIN time_logs tl
           ON tl.$timeLogPrimaryKey = (
               SELECT t2.$timeLogPrimaryKey
               FROM time_logs t2
               WHERE t2.attendance_id = al.attendance_id
               " . ($timeLogOrderColumn ? "ORDER BY t2.$timeLogOrderColumn DESC" : "") . "
               LIMIT 1
           )";
    }

    $historySql .= "
         WHERE al.cluster_id=$cluster_id
         ORDER BY COALESCE(al.attendance_date, al.updated_at) DESC, al.attendance_id DESC";

    $historyRes = $conn->query($historySql);

    if ($historyRes) {
        while ($history = $historyRes->fetch_assoc()) {
            $employeeId = (int)$history['employee_id'];
            $monthKey = $history['month_key'];
            if (!isset($historyByEmployee[$employeeId][$monthKey])) {
                $historyByEmployee[$employeeId][$monthKey] = [
                    'month' => $history['month_label'],
                    'entries' => []
                ];
            }

            $historyByEmployee[$employeeId][$monthKey]['entries'][] = [
                'id' => (int)$history['attendance_id'],
                'time_in_at' => $history['time_in_at'] ?: $history['attendance_date'],
                'time_out_at' => $history['time_out_at'],
                'tag' => $history['attendance_status'],
                'note' => $history['note']
            ];
        }
    }
}

foreach ($members as $id => &$member) {
    if (isset($schedulesByEmployee[$id])) {
        $member['schedule'] = $schedulesByEmployee[$id];
    }
    if (isset($historyByEmployee[$id])) {
        $member['attendance_history'] = array_values($historyByEmployee[$id]);
    }
}
unset($member);

echo json_encode(array_values($members));