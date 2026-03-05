<?php
include "../config/database.php";
include "../config/auth.php";
requireRole("coach");

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

function mapTagToAttendanceStatus(?string $tag): string {
    $normalizedTag = strtolower(trim((string)$tag));
    if ($normalizedTag === 'absent') return 'Absent';
    if ($normalizedTag === 'late') return 'Late';
    if ($normalizedTag === 'on leave') return 'On Leave';
    if ($normalizedTag === 'overtime') return 'Overtime';
    if ($normalizedTag === 'on time') return 'Present';
    return 'Present';
}

function getEnumValues(mysqli $conn, string $table, string $column): array {
    $res = $conn->query("SHOW COLUMNS FROM $table LIKE '" . $conn->real_escape_string($column) . "'");
    if (!$res || $res->num_rows === 0) return [];

    $row = $res->fetch_assoc();
    $type = $row['Type'] ?? '';
    if (!preg_match('/^enum\((.*)\)$/i', $type, $matches)) return [];

    $inner = $matches[1] ?? '';
    if ($inner === '') return [];

    $parts = str_getcsv($inner, ',', "'", '\\');
    return array_values(array_filter(array_map(static fn($v) => trim((string)$v), $parts), static fn($v) => $v !== ''));
}

function normalizeToAllowedEnum(?string $value, array $allowedValues): ?string {
    if ($value === null) return null;
    $trimmed = trim($value);
    if ($trimmed === '') return null;
    if (count($allowedValues) === 0) return $trimmed;

    $allowedMap = [];
    foreach ($allowedValues as $allowedValue) {
        $allowedMap[strtolower($allowedValue)] = $allowedValue;
    }

    $lookup = strtolower($trimmed);
    return $allowedMap[$lookup] ?? null;
}

function pickColumn(array $columns, string $primary, ?string $fallback = null): ?string {
    if (in_array($primary, $columns, true)) return $primary;
    if ($fallback !== null && in_array($fallback, $columns, true)) return $fallback;
    return null;
}

$data = json_decode(file_get_contents("php://input"), true);

$cluster_id = isset($data["cluster_id"]) ? (int)$data["cluster_id"] : 0;
$employee_id = isset($data["employee_id"]) ? (int)$data["employee_id"] : 0;
$timeInAt = isset($data["timeInAt"]) ? $data["timeInAt"] : null;
$timeOutAt = isset($data["timeOutAt"]) ? $data["timeOutAt"] : null;
$tag = isset($data["tag"]) ? $data["tag"] : null;
$note = isset($data["note"]) ? $data["note"] : "";
$attendanceId = isset($data["attendance_id"]) ? (int)$data["attendance_id"] : 0;

if ($cluster_id <= 0 || $employee_id <= 0 || $attendanceId <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid request."]);
    exit;
}

$clusterColumns = getColumns($conn, 'clusters');
$clusterIdColumn = pickColumn($clusterColumns, 'id', 'cluster_id');
$clusterOwnerColumn = pickColumn($clusterColumns, 'coach_id', 'user_id');

if ($clusterIdColumn === null || $clusterOwnerColumn === null) {
    http_response_code(500);
    echo json_encode(["error" => "Cluster schema is not supported."]);
    exit;
}

$coach_id = (int)($_SESSION["user"]["id"] ?? 0);
$ownershipCheck = $conn->query(
    "SELECT $clusterIdColumn
     FROM clusters
     WHERE $clusterIdColumn=$cluster_id
       AND $clusterOwnerColumn=$coach_id
     LIMIT 1"
);

if (!$ownershipCheck || $ownershipCheck->num_rows === 0) {
    http_response_code(403);
    echo json_encode(["error" => "You can only edit attendance for your active cluster."]);
    exit;
}

$memberCheck = $conn->query(
    "SELECT 1
     FROM cluster_members
     WHERE cluster_id=$cluster_id
       AND employee_id=$employee_id
     LIMIT 1"
);

if (!$memberCheck || $memberCheck->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["error" => "Employee is not part of this cluster."]);
    exit;
}

$attendanceColumns = getColumns($conn, 'attendance_logs');
$timeLogColumns = getColumns($conn, 'time_logs');

$hasLegacyAttendance = in_array('id', $attendanceColumns, true)
    && in_array('time_in_at', $attendanceColumns, true)
    && in_array('time_out_at', $attendanceColumns, true)
    && in_array('tag', $attendanceColumns, true);

$hasNewAttendance = in_array('attendance_id', $attendanceColumns, true)
    && in_array('attendance_status', $attendanceColumns, true)
    && in_array('attendance_date', $attendanceColumns, true);

$hasAttendanceUpdatedAt = in_array('updated_at', $attendanceColumns, true);

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

$timeInSql = $timeInAt ? date("Y-m-d H:i:s", strtotime($timeInAt)) : null;
$timeOutSql = $timeOutAt ? date("Y-m-d H:i:s", strtotime($timeOutAt)) : null;

if ($timeInAt !== null && $timeInAt !== "" && !$timeInSql) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid time-in value."]);
    exit;
}

if ($timeOutAt !== null && $timeOutAt !== "" && !$timeOutSql) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid time-out value."]);
    exit;
}

if ($timeInSql && $timeOutSql && strtotime($timeOutSql) < strtotime($timeInSql)) {
    http_response_code(400);
    echo json_encode(["error" => "Time out cannot be earlier than time in."]);
    exit;
}

$timeInValue = $timeInSql ? "'" . $conn->real_escape_string($timeInSql) . "'" : "NULL";
$timeOutValue = $timeOutSql ? "'" . $conn->real_escape_string($timeOutSql) . "'" : "NULL";
$tagValue = ($tag !== null && $tag !== "") ? "'" . $conn->real_escape_string($tag) . "'" : "NULL";
$noteValue = "'" . $conn->real_escape_string($note) . "'";

if ($hasLegacyAttendance) {
    $attendanceCheck = $conn->query(
        "SELECT id
         FROM attendance_logs
         WHERE id=$attendanceId
           AND cluster_id=$cluster_id
           AND employee_id=$employee_id
         LIMIT 1"
    );

    if (!$attendanceCheck || $attendanceCheck->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Attendance record not found for this employee."]);
        exit;
    }

    $legacyUpdateFields = [
        "time_in_at=$timeInValue",
        "time_out_at=$timeOutValue",
        "tag=$tagValue",
        "note=$noteValue"
    ];
    if ($hasAttendanceUpdatedAt) {
        $legacyUpdateFields[] = "updated_at=CURRENT_TIMESTAMP";
    }

    $updateResult = $conn->query(
        "UPDATE attendance_logs
         SET " . implode(', ', $legacyUpdateFields) . "
         WHERE id=$attendanceId"
    );

    if (!$updateResult) {
        http_response_code(500);
        echo json_encode(["error" => "Unable to update attendance record."]);
        exit;
    }

    $updatedAttendanceRes = $conn->query(
        "SELECT id,
                time_in_at,
                time_out_at,
                tag,
                note,
                " . ($hasAttendanceUpdatedAt ? "updated_at" : "NULL") . " AS updated_at
         FROM attendance_logs
         WHERE id=$attendanceId
         LIMIT 1"
    );

    $updatedAttendance = $updatedAttendanceRes ? $updatedAttendanceRes->fetch_assoc() : null;

    echo json_encode([
        "success" => true,
        "attendance" => [
            "id" => $updatedAttendance ? (int)$updatedAttendance["id"] : $attendanceId,
            "timeInAt" => $updatedAttendance["time_in_at"] ?? $timeInSql,
            "timeOutAt" => $updatedAttendance["time_out_at"] ?? $timeOutSql,
            "tag" => $updatedAttendance["tag"] ?? $tag,
            "note" => $updatedAttendance["note"] ?? $note,
            "updatedAt" => $updatedAttendance["updated_at"] ?? null
        ]
    ]);
    exit;
}

if ($hasNewAttendance) {
    $attendanceCheck = $conn->query(
        "SELECT attendance_id
         FROM attendance_logs
         WHERE attendance_id=$attendanceId
           AND cluster_id=$cluster_id
           AND employee_id=$employee_id
         LIMIT 1"
    );

    if (!$attendanceCheck || $attendanceCheck->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Attendance record not found for this employee."]);
        exit;
    }

    $attendanceStatusEnum = getEnumValues($conn, 'attendance_logs', 'attendance_status');
    $mappedAttendanceStatus = mapTagToAttendanceStatus($tag);
    $normalizedAttendanceStatus = normalizeToAllowedEnum($mappedAttendanceStatus, $attendanceStatusEnum);

    if ($normalizedAttendanceStatus === null) {
        $normalizedAttendanceStatus = count($attendanceStatusEnum) > 0 ? $attendanceStatusEnum[0] : 'Present';
    }

    $attendanceStatusEscaped = "'" . $conn->real_escape_string($normalizedAttendanceStatus) . "'";

    $newUpdateFields = [
        "attendance_status=$attendanceStatusEscaped",
        "note=$noteValue"
    ];
    if ($hasAttendanceUpdatedAt) {
        $newUpdateFields[] = "updated_at=CURRENT_TIMESTAMP";
    }

    $updateAttendanceResult = $conn->query(
        "UPDATE attendance_logs
         SET " . implode(', ', $newUpdateFields) . "
         WHERE attendance_id=$attendanceId"
    );

    if (!$updateAttendanceResult) {
        http_response_code(500);
        echo json_encode(["error" => "Unable to update attendance record."]);
        exit;
    }

    if ($hasTimeLogs) {
        $timeLogCheck = $conn->query(
            "SELECT $timeLogPrimaryKey AS time_log_key
             FROM time_logs
             WHERE attendance_id=$attendanceId
             " . ($timeLogOrderColumn ? "ORDER BY $timeLogOrderColumn DESC" : "") . "
             LIMIT 1"
        );

        if ($timeLogCheck && $timeLogCheck->num_rows > 0) {
            $timeLog = $timeLogCheck->fetch_assoc();
            $timeLogKey = (int)$timeLog['time_log_key'];
            $timeLogUpdates = [
                "time_in=$timeInValue"
            ];

            if ($hasTimeLogTimeOut) {
                $timeLogUpdates[] = "time_out=$timeOutValue";
            }
            if ($hasTimeLogTag) {
                $timeLogTagEnum = getEnumValues($conn, 'time_logs', 'tag');
                $normalizedTimeLogTag = normalizeToAllowedEnum($tag, $timeLogTagEnum);

                if ($normalizedTimeLogTag !== null) {
                    $timeLogUpdates[] = "tag='" . $conn->real_escape_string($normalizedTimeLogTag) . "'";
                } elseif (count($timeLogTagEnum) === 0) {
                    $timeLogUpdates[] = "tag=$tagValue";
                } else {
                    $timeLogUpdates[] = "tag=NULL";
                }
            }

            $updateTimeLogResult = $conn->query(
                "UPDATE time_logs
                 SET " . implode(', ', $timeLogUpdates) . "
                 WHERE $timeLogPrimaryKey=$timeLogKey"
            );

            if (!$updateTimeLogResult) {
                http_response_code(500);
                echo json_encode(["error" => "Unable to update attendance record."]);
                exit;
            }
        }
    }

    $updatedAttendanceRes = $conn->query(
        "SELECT al.attendance_id,
                al.note,
                " . ($hasAttendanceUpdatedAt ? "al.updated_at" : "NULL") . " AS updated_at,
                " . ($hasTimeLogs ? "tl.time_in" : "NULL") . " AS time_in_at,
                " . (($hasTimeLogs && $hasTimeLogTimeOut) ? "tl.time_out" : "NULL") . " AS time_out_at,
                " . ($hasTimeLogs && $hasTimeLogTag ? "COALESCE(tl.tag, al.attendance_status)" : "al.attendance_status") . " AS tag
         FROM attendance_logs al
         " . ($hasTimeLogs ? "LEFT JOIN time_logs tl
               ON tl.$timeLogPrimaryKey = (
                   SELECT t2.$timeLogPrimaryKey
                   FROM time_logs t2
                   WHERE t2.attendance_id = al.attendance_id
                   " . ($timeLogOrderColumn ? "ORDER BY t2.$timeLogOrderColumn DESC" : "") . "
                   LIMIT 1
               )" : "") . "
         WHERE al.attendance_id=$attendanceId
         LIMIT 1"
    );

    $updatedAttendance = $updatedAttendanceRes ? $updatedAttendanceRes->fetch_assoc() : null;

    echo json_encode([
        "success" => true,
        "attendance" => [
            "id" => $updatedAttendance ? (int)$updatedAttendance["attendance_id"] : $attendanceId,
            "timeInAt" => $updatedAttendance["time_in_at"] ?? $timeInSql,
            "timeOutAt" => $updatedAttendance["time_out_at"] ?? $timeOutSql,
            "tag" => $updatedAttendance["tag"] ?? $tag,
            "note" => $updatedAttendance["note"] ?? $note,
            "updatedAt" => $updatedAttendance["updated_at"] ?? null
        ]
    ]);
    exit;
}

http_response_code(500);
echo json_encode(["error" => "Attendance schema is not supported."]);