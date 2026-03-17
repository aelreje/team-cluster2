import { apiFetch } from "./api";

export const parseSqlDateTime = value => {
  if (!value || typeof value !== "string") return null;
  const [datePart, timePart] = value.trim().split(" ");
  if (!datePart || !timePart) return null;
  const [year, month, day] = datePart.split("-").map(Number);
  const [hours, minutes, seconds] = timePart.split(":").map(Number);
  if ([year, month, day, hours, minutes].some(Number.isNaN)) return null;
  return new Date(year, month - 1, day, hours, minutes, Number.isNaN(seconds) ? 0 : seconds);
};

export const toLocalSqlDateTime = date => {
  const year = date.getFullYear();
  const month = `${date.getMonth() + 1}`.padStart(2, "0");
  const day = `${date.getDate()}`.padStart(2, "0");
  const hours = `${date.getHours()}`.padStart(2, "0");
  const minutes = `${date.getMinutes()}`.padStart(2, "0");
  const seconds = `${date.getSeconds()}`.padStart(2, "0");
  return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
};

export const fetchAttendanceHistory = async () => {
  return await apiFetch("api/employee/employee_attendance_history.php");
};

export const saveDashboardAttendance = async ({ clusterId, nextAttendance }) => {
  const response = await apiFetch("api/employee/save_attendance.php", {
    method: "POST",
    body: JSON.stringify({
      cluster_id: clusterId,
      ...nextAttendance,
      timeInAt: nextAttendance.timeInAt ? toLocalSqlDateTime(nextAttendance.timeInAt) : null,
      timeOutAt: nextAttendance.timeOutAt ? toLocalSqlDateTime(nextAttendance.timeOutAt) : null,
    })
  });

  return {
    timeInAt: parseSqlDateTime(response?.attendance?.timeInAt ?? null),
    timeOutAt: parseSqlDateTime(response?.attendance?.timeOutAt ?? null),
    tag: response?.attendance?.tag ?? null,
  };
};