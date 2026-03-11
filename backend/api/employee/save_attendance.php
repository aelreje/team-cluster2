<?php
include __DIR__ . "/../../config/database.php";
include __DIR__ . "/../../config/auth.php";
if (!in_array($_SESSION["user"]["role"] ?? "", ["employee", "coach"], true)) {
    http_response_code(403);
    echo json_encode(["error" => "Forbidden"]);
    exit;
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

function getMemberEmployeeId(mysqli $conn): int {
    $employeeColumns = getColumns($conn, 'employees');
    $clusterMemberEmployeeReference = getClusterMemberEmployeeReference($conn);

    $sessionUserId = (int)($_SESSION['user']['id'] ?? 0);
    $memberEmployeeId = $sessionUserId;

    $employeeIdColumn = in_array('employee_id', $employeeColumns, true) ? 'employee_id' : null;
    $employeeUserIdColumn = in_array('user_id', $employeeColumns, true) ? 'user_id' : null;

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

    return $memberEmployeeId;
}



function pickColumn(array $columns, string $primary, ?string $fallback = null): ?string {
    if (in_array($primary, $columns, true)) return $primary;
    if ($fallback !== null && in_array($fallback, $columns, true)) return $fallback;
    return null;
}

function mapTagToAttendanceStatus(?string $tag): string {
    $normalizedTag = strtolower(trim((string)$tag));
    if ($normalizedTag === 'late') return 'Late';
    if ($normalizedTag === 'on time') return 'Present';
    return 'Present';
}

$data = json_decode(file_get_contents("php://input"), true);

$cluster_id = isset($data["cluster_id"]) ? (int)$data["cluster_id"] : 0;
$sessionRole = $_SESSION["user"]["role"] ?? "";
$sessionUserId = (int)($_SESSION['user']['id'] ?? 0);
$employee_id = $sessionRole === 'coach' ? $sessionUserId : getMemberEmployeeId($conn);
$timeInAt = isset($data["timeInAt"]) ? $data["timeInAt"] : null;
$timeOutAt = isset($data["timeOutAt"]) ? $data["timeOutAt"] : null;
$tag = isset($data["tag"]) ? $data["tag"] : null;
$note = isset($data["note"]) ? $data["note"] : "";

if ($cluster_id <= 0) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid cluster id"]);
    exit;
}

if ($sessionRole === 'coach') {
    $clusterColumns = getColumns($conn, 'clusters');
    $clusterIdColumn = pickColumn($clusterColumns, 'id', 'cluster_id');
    $clusterOwnerColumn = pickColumn($clusterColumns, 'coach_id', 'user_id');

    if ($clusterIdColumn === null || $clusterOwnerColumn === null) {
        http_response_code(500);
        echo json_encode(["error" => "Cluster schema is not supported."]);
        exit;
    }

    $ownershipCheck = $conn->query(
        "SELECT 1 FROM clusters WHERE $clusterIdColumn=$cluster_id AND $clusterOwnerColumn=$sessionUserId LIMIT 1"
    );

    if (!$ownershipCheck || $ownershipCheck->num_rows === 0) {
        http_response_code(403);
        echo json_encode(["error" => "You can only log attendance for your cluster."]);
        exit;
    }
} else {
    $membershipStmt = $conn->prepare(
        "SELECT 1
         FROM cluster_members
         WHERE cluster_id = ?
           AND employee_id = ?
         LIMIT 1"
    );
    $membershipStmt->bind_param('ii', $cluster_id, $employee_id);
    $membershipStmt->execute();
    $membershipRes = $membershipStmt->get_result();
    if (!$membershipRes || $membershipRes->num_rows === 0) {
        http_response_code(403);
        echo json_encode(["error" => "Employee is not assigned to this cluster"]);
        exit;
    }
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

$timeLogPrimaryKey = in_array('time_log_id', $timeLogColumns, true)
    ? 'time_log_id'
    : (in_array('id', $timeLogColumns, true) ? 'id' : null);
$timeLogOrderColumn = $timeLogPrimaryKey
    ?? (in_array('updated_at', $timeLogColumns, true)
        ? 'updated_at'
        : (in_array('time_in', $timeLogColumns, true) ? 'time_in' : null));

$hasTimeLogs = in_array('attendance_id', $timeLogColumns, true)
    && in_array('time_in', $timeLogColumns, true);

$hasTimeLogTimeOut = in_array('time_out', $timeLogColumns, true);
$hasTimeLogEmployee = in_array('employee_id', $timeLogColumns, true);
$hasTimeLogCluster = in_array('cluster_id', $timeLogColumns, true);
$hasTimeLogDate = in_array('log_date', $timeLogColumns, true);
$hasTimeLogTag = in_array('tag', $timeLogColumns, true);

$timeInSql = $timeInAt ? date("Y-m-d H:i:s", strtotime($timeInAt)) : null;
$timeOutSql = $timeOutAt ? date("Y-m-d H:i:s", strtotime($timeOutAt)) : null;

$timeInValue = $timeInSql ? "'" . $conn->real_escape_string($timeInSql) . "'" : "NULL";
$timeOutValue = $timeOutSql ? "'" . $conn->real_escape_string($timeOutSql) . "'" : "NULL";
$tagValue = $tag ? "'" . $conn->real_escape_string($tag) . "'" : "NULL";
$noteValue = "'" . $conn->real_escape_string($note) . "'";

if ($hasLegacyAttendance) {
    if ($timeOutSql) {
        $lookup = $conn->query(
            "SELECT id FROM attendance_logs
             WHERE cluster_id=$cluster_id
               AND employee_id=$employee_id
               AND time_out_at IS NULL
             ORDER BY COALESCE(time_in_at, updated_at) DESC, id DESC
             LIMIT 1"
        );

        if ($lookup && $lookup->num_rows > 0) {
            $row = $lookup->fetch_assoc();
            $attendanceId = (int)$row["id"];
            $conn->query(
                "UPDATE attendance_logs
                 SET time_out_at=$timeOutValue,
                     tag=$tagValue,
                     note=$noteValue
                 WHERE id=$attendanceId"
            );
        } else {
            $conn->query(
                "INSERT INTO attendance_logs (cluster_id, employee_id, time_in_at, time_out_at, tag, note)
                 VALUES ($cluster_id, $employee_id, $timeInValue, $timeOutValue, $tagValue, $noteValue)"
            );
        }
    } else {
        if ($timeInSql) {
            $timeInDate = date('Y-m-d', strtotime($timeInSql));
            $timeInDateEscaped = "'" . $conn->real_escape_string($timeInDate) . "'";
            $existingTimeInToday = $conn->query(
                "SELECT id FROM attendance_logs
                 WHERE cluster_id=$cluster_id
                   AND employee_id=$employee_id
                   AND DATE(COALESCE(time_in_at, updated_at)) = $timeInDateEscaped
                 ORDER BY COALESCE(time_in_at, updated_at) DESC, id DESC
                 LIMIT 1"
            );

            if ($existingTimeInToday && $existingTimeInToday->num_rows > 0) {
                http_response_code(409);
                echo json_encode(["error" => "You can only time in once per schedule."]);
                exit;
            }
        }

        $conn->query(
            "INSERT INTO attendance_logs (cluster_id, employee_id, time_in_at, time_out_at, tag, note)
             VALUES ($cluster_id, $employee_id, $timeInValue, NULL, $tagValue, $noteValue)"
        );
    }
} elseif ($hasNewAttendance) {
    $attendanceDate = $timeInSql ? date('Y-m-d', strtotime($timeInSql)) : date('Y-m-d');
    $attendanceDateEscaped = "'" . $conn->real_escape_string($attendanceDate) . "'";
    $attendanceStatus = mapTagToAttendanceStatus($tag);
    $attendanceStatusEscaped = "'" . $conn->real_escape_string($attendanceStatus) . "'";

    $existingAttendanceQuery = $conn->query(
        "SELECT attendance_id
         FROM attendance_logs
         WHERE cluster_id = $cluster_id
           AND employee_id = $employee_id
           AND attendance_date = $attendanceDateEscaped
         ORDER BY attendance_id DESC
         LIMIT 1"
    );

    $attendanceId = null;
    if ($existingAttendanceQuery && $existingAttendanceQuery->num_rows > 0) {
        $existingAttendance = $existingAttendanceQuery->fetch_assoc();
        $attendanceId = (int)$existingAttendance['attendance_id'];

        $conn->query(
            "UPDATE attendance_logs
             SET attendance_status = $attendanceStatusEscaped,
                 note = $noteValue,
                 updated_at = CURRENT_TIMESTAMP
             WHERE attendance_id = $attendanceId"
        );
    } else {
        $conn->query(
            "INSERT INTO attendance_logs (cluster_id, employee_id, note, attendance_date, attendance_status)
             VALUES ($cluster_id, $employee_id, $noteValue, $attendanceDateEscaped, $attendanceStatusEscaped)"
        );
        $attendanceId = (int)$conn->insert_id;
    }

    if ($hasTimeLogs && $attendanceId > 0) {
        if ($timeOutSql) {
            $existingTimeLogSql = "SELECT " . ($timeLogPrimaryKey ?? 'attendance_id') . " AS time_log_key
                 FROM time_logs
                 WHERE attendance_id = $attendanceId";

            if ($hasTimeLogCluster) {
                $existingTimeLogSql .= " AND cluster_id = $cluster_id";
            }
            if ($hasTimeLogEmployee) {
                $existingTimeLogSql .= " AND employee_id = $employee_id";
            }
            if ($hasTimeLogTimeOut) {
                $existingTimeLogSql .= " AND time_out IS NULL";
            }
            if ($timeLogOrderColumn) {
                $existingTimeLogSql .= " ORDER BY $timeLogOrderColumn DESC";
            }
            $existingTimeLogSql .= " LIMIT 1";

            $existingTimeLogQuery = $conn->query($existingTimeLogSql);

            if ($existingTimeLogQuery && $existingTimeLogQuery->num_rows > 0) {
                $existingTimeLog = $existingTimeLogQuery->fetch_assoc();
                $timeLogKey = (int)$existingTimeLog['time_log_key'];

                $timeLogUpdates = [];
                if ($hasTimeLogTimeOut) {
                    $timeLogUpdates[] = "time_out = $timeOutValue";
                }
                if ($hasTimeLogTag) {
                    $timeLogUpdates[] = "tag = $tagValue";
                }

                if (count($timeLogUpdates) > 0 && $timeLogPrimaryKey) {
                    $conn->query(
                        "UPDATE time_logs
                         SET " . implode(', ', $timeLogUpdates) . "
                         WHERE $timeLogPrimaryKey = $timeLogKey"
                    );
                }
            } else {
                $insertColumns = ['attendance_id', 'time_in'];
                $insertValues = [$attendanceId, $timeInValue];

                if ($hasTimeLogEmployee) {
                    $insertColumns[] = 'employee_id';
                    $insertValues[] = $employee_id;
                }
                if ($hasTimeLogCluster) {
                    $insertColumns[] = 'cluster_id';
                    $insertValues[] = $cluster_id;
                }
                if ($hasTimeLogTimeOut) {
                    $insertColumns[] = 'time_out';
                    $insertValues[] = $timeOutValue;
                }
                if ($hasTimeLogDate) {
                    $insertColumns[] = 'log_date';
                    $insertValues[] = $attendanceDateEscaped;
                }
                if ($hasTimeLogTag) {
                    $insertColumns[] = 'tag';
                    $insertValues[] = $tagValue;
                }

                $conn->query(
                    "INSERT INTO time_logs (" . implode(', ', $insertColumns) . ")
                     VALUES (" . implode(', ', $insertValues) . ")"
                );
            }
        } elseif ($timeInSql) {
            $existingTimeInQuery = $conn->query(
                "SELECT " . ($timeLogPrimaryKey ?? 'attendance_id') . "
                 FROM time_logs
                 WHERE attendance_id = $attendanceId
                 LIMIT 1"
            );

            if ($existingTimeInQuery && $existingTimeInQuery->num_rows > 0) {
                http_response_code(409);
                echo json_encode(["error" => "You can only time in once per schedule."]);
                exit;
            }

            $insertColumns = ['attendance_id', 'time_in'];
            $insertValues = [$attendanceId, $timeInValue];

            if ($hasTimeLogEmployee) {
                $insertColumns[] = 'employee_id';
                $insertValues[] = $employee_id;
            }
            if ($hasTimeLogCluster) {
                $insertColumns[] = 'cluster_id';
                $insertValues[] = $cluster_id;
            }
            if ($hasTimeLogDate) {
                $insertColumns[] = 'log_date';
                $insertValues[] = $attendanceDateEscaped;
            }
            if ($hasTimeLogTag) {
                $insertColumns[] = 'tag';
                $insertValues[] = $tagValue;
            }

            $conn->query(
                "INSERT INTO time_logs (" . implode(', ', $insertColumns) . ")
                 VALUES (" . implode(', ', $insertValues) . ")"
            );
        }
    }
} else {
    http_response_code(500);
    echo json_encode(["error" => "Attendance schema is not supported"]);
    exit;
}

echo json_encode([
    "success" => true,
    "attendance" => [
        "timeInAt" => $timeInSql,
        "timeOutAt" => $timeOutSql,
        "tag" => $tag,
        "note" => $note
    ]
]);