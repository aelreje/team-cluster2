<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("employee");

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

function getClusterMemberEmployeeReference(mysqli $conn): ?string {
    $sql = "SELECT REFERENCED_TABLE_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cluster_members'
              AND COLUMN_NAME = 'employee_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
            LIMIT 1";

    $result = $conn->query($sql);
    if (!$result) {
        return null;
    }

    $row = $result->fetch_assoc();
    return $row['REFERENCED_TABLE_NAME'] ?? null;
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
$clusterColumns = getColumns($conn, 'clusters');
$scheduleColumns = getColumns($conn, 'schedules');
$attendanceColumns = getColumns($conn, 'attendance_logs');
$clusterMemberEmployeeReference = getClusterMemberEmployeeReference($conn);

$userIdColumn = in_array('id', $userColumns, true) ? 'id' : 'user_id';
$userNameExpr = in_array('fullname', $userColumns, true)
    ? 'u.fullname'
    : (in_array('username', $userColumns, true) ? 'u.username' : "CONCAT('User #', u.$userIdColumn)");
$employeeIdColumn = in_array('employee_id', $employeeColumns, true) ? 'employee_id' : null;
$employeeUserIdColumn = in_array('user_id', $employeeColumns, true) ? 'user_id' : null;
$clusterIdColumn = in_array('id', $clusterColumns, true) ? 'id' : 'cluster_id';
$ownerColumn = in_array('coach_id', $clusterColumns, true) ? 'coach_id' : 'user_id';

$sessionUserId = (int)($_SESSION['user']['id'] ?? 0);
$memberEmployeeId = $sessionUserId;

if (
    $clusterMemberEmployeeReference === 'employees'
    && $employeeIdColumn
    && $employeeUserIdColumn
) {
    $employeeStmt = $conn->prepare(
        "SELECT $employeeIdColumn AS employee_id
         FROM employees
         WHERE $employeeUserIdColumn = ?
         LIMIT 1"
    );
    $employeeStmt->bind_param('i', $sessionUserId);
    $employeeStmt->execute();
    $employeeRes = $employeeStmt->get_result();

    if ($employeeRes && $employeeRes->num_rows > 0) {
        $memberEmployeeId = (int)$employeeRes->fetch_assoc()['employee_id'];
    }
}

$clusterStmt = $conn->prepare(
    "SELECT
        c.$clusterIdColumn AS cluster_id,
        c.name AS cluster_name,
        $userNameExpr AS coach_name
     FROM cluster_members cm
     JOIN clusters c ON cm.cluster_id = c.$clusterIdColumn
     JOIN users u ON c.$ownerColumn = u.$userIdColumn
     WHERE cm.employee_id = ?
       AND c.status = 'active'"
);
$clusterStmt->bind_param('i', $memberEmployeeId);
$clusterStmt->execute();
$clusterRes = $clusterStmt->get_result();

$out = [];
while ($row = $clusterRes->fetch_assoc()) {
    $clusterId = (int)$row['cluster_id'];
    $entry = [
        'cluster_id' => $clusterId,
        'cluster_name' => $row['cluster_name'],
        'coach_name' => trim((string)$row['coach_name']) ?: 'Coach',
        'schedule' => null,
        'time_in_at' => null,
        'time_out_at' => null,
        'attendance_tag' => null,
        'attendance_note' => null
    ];

    if (in_array('schedule', $scheduleColumns, true)) {
        $scheduleOrderColumn = in_array('id', $scheduleColumns, true)
            ? 'id'
            : (in_array('schedule_id', $scheduleColumns, true) ? 'schedule_id' : null);

        $scheduleSql = "SELECT schedule
                        FROM schedules
                        WHERE cluster_id = ? AND employee_id = ?";
        if ($scheduleOrderColumn) {
            $scheduleSql .= " ORDER BY $scheduleOrderColumn DESC";
        }
        $scheduleSql .= " LIMIT 1";

        $scheduleStmt = $conn->prepare($scheduleSql);
        $scheduleStmt->bind_param('ii', $clusterId, $memberEmployeeId);
        $scheduleStmt->execute();
        $scheduleRes = $scheduleStmt->get_result();
        if ($scheduleRes && $scheduleRes->num_rows > 0) {
            $entry['schedule'] = $scheduleRes->fetch_assoc()['schedule'];
        }
    } elseif (
        in_array('day_of_week', $scheduleColumns, true)
        && in_array('start_time', $scheduleColumns, true)
        && in_array('end_time', $scheduleColumns, true)
    ) {
        $scheduleOrderColumn = in_array('schedule_id', $scheduleColumns, true)
            ? 'schedule_id'
            : (in_array('id', $scheduleColumns, true) ? 'id' : null);

        $scheduleSql = "SELECT day_of_week, shift_type, start_time, end_time, work_setup, breaksched_start, breaksched_end
                        FROM schedules
                        WHERE cluster_id = ? AND employee_id = ?";
        if ($scheduleOrderColumn) {
            $scheduleSql .= " ORDER BY $scheduleOrderColumn ASC";
        }

        $scheduleStmt = $conn->prepare($scheduleSql);
        $scheduleStmt->bind_param('ii', $clusterId, $memberEmployeeId);
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

        $composed = ['days' => [], 'daySchedules' => []];
        if ($scheduleRes) {
            while ($scheduleRow = $scheduleRes->fetch_assoc()) {
                $shortDay = $dayToShort[$scheduleRow['day_of_week'] ?? ''] ?? null;
                if ($shortDay === null) continue;

                [$startTime, $startPeriod] = sqlTimeToUi($scheduleRow['start_time'] ?? null);
                [$endTime, $endPeriod] = sqlTimeToUi($scheduleRow['end_time'] ?? null);
                [$breakStartTime, $breakStartPeriod] = sqlTimeToUi($scheduleRow['breaksched_start'] ?? null);
                [$breakEndTime, $breakEndPeriod] = sqlTimeToUi($scheduleRow['breaksched_end'] ?? null);

                if (!in_array($shortDay, $composed['days'], true)) {
                    $composed['days'][] = $shortDay;
                }

                $composed['daySchedules'][$shortDay] = [
                    'shiftType' => $scheduleRow['shift_type'] ?? null,
                    'startTime' => $startTime,
                    'startPeriod' => $startPeriod,
                    'endTime' => $endTime,
                    'endPeriod' => $endPeriod,
                    'workSetup' => $scheduleRow['work_setup'] ?? null,
                    'breakStartTime' => $breakStartTime,
                    'breakStartPeriod' => $breakStartPeriod,
                    'breakEndTime' => $breakEndTime,
                    'breakEndPeriod' => $breakEndPeriod
                ];
            }
        }

        if (count($composed['days']) > 0) {
            $entry['schedule'] = $composed;
        }
    }

    $hasLegacyAttendance = in_array('id', $attendanceColumns, true)
        && in_array('time_in_at', $attendanceColumns, true)
        && in_array('time_out_at', $attendanceColumns, true)
        && in_array('tag', $attendanceColumns, true);

    $hasNewAttendance = in_array('attendance_id', $attendanceColumns, true)
        && in_array('attendance_status', $attendanceColumns, true)
        && in_array('attendance_date', $attendanceColumns, true);

    if ($hasLegacyAttendance) {
        $attendanceStmt = $conn->prepare(
            "SELECT time_in_at, time_out_at, tag, note
             FROM attendance_logs
             WHERE cluster_id = ?
               AND employee_id = ?
             ORDER BY COALESCE(time_in_at, updated_at) DESC, id DESC
             LIMIT 1"
        );
        $attendanceStmt->bind_param('ii', $clusterId, $memberEmployeeId);
        $attendanceStmt->execute();
        $attendanceRes = $attendanceStmt->get_result();

        if ($attendanceRes && $attendanceRes->num_rows > 0) {
            $attendance = $attendanceRes->fetch_assoc();
            $entry['time_in_at'] = $attendance['time_in_at'];
            $entry['time_out_at'] = $attendance['time_out_at'];
            $entry['attendance_tag'] = $attendance['tag'];
            $entry['attendance_note'] = $attendance['note'];
        }
    } elseif ($hasNewAttendance) {
        $timeLogColumns = getColumns($conn, 'time_logs');
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

        $hasTimeOut = in_array('time_out', $timeLogColumns, true);
        $hasTimeTag = in_array('tag', $timeLogColumns, true);

        $attendanceSql = "SELECT al.attendance_status,
                                 al.note,
                                 al.attendance_date";

        if ($hasTimeLogs) {
            $attendanceSql .= ", tl.time_in AS latest_time_in,
                                " . ($hasTimeOut ? "tl.time_out" : "NULL") . " AS latest_time_out,
                                " . ($hasTimeTag ? "tl.tag" : "NULL") . " AS latest_time_tag";
        }

        $attendanceSql .= "
             FROM attendance_logs al";

        if ($hasTimeLogs) {
            $attendanceSql .= "
             LEFT JOIN time_logs tl
               ON tl.$timeLogPrimaryKey = (
                   SELECT t2.$timeLogPrimaryKey
                   FROM time_logs t2
                   WHERE t2.attendance_id = al.attendance_id
                   " . ($timeLogOrderColumn ? "ORDER BY t2.$timeLogOrderColumn DESC" : "") . "
                   LIMIT 1
               )";
        }

        $attendanceSql .= "
             WHERE al.cluster_id = ?
               AND al.employee_id = ?
             ORDER BY COALESCE(al.attendance_date, al.updated_at) DESC, al.attendance_id DESC
             LIMIT 1";

        $attendanceStmt = $conn->prepare($attendanceSql);
        $attendanceStmt->bind_param('ii', $clusterId, $memberEmployeeId);
        $attendanceStmt->execute();
        $attendanceRes = $attendanceStmt->get_result();

        if ($attendanceRes && $attendanceRes->num_rows > 0) {
            $attendance = $attendanceRes->fetch_assoc();
            $entry['time_in_at'] = $attendance['latest_time_in'] ?? null;
            $entry['time_out_at'] = $attendance['latest_time_out'] ?? null;
            $entry['attendance_tag'] = $attendance['latest_time_tag'] ?? $attendance['attendance_status'];
            $entry['attendance_note'] = $attendance['note'];
        }
    }

    $out[] = $entry;
}

echo json_encode($out);