import { useEffect, useState } from "react";
import { apiFetch } from "../api/api";
import { saveDashboardAttendance } from "../api/attendance";
import DashboardSidebar from "../components/DashboardSidebar";
import MainDashboard from "./MainDashboard";
import AttendanceHistoryHighlights from "../components/AttendanceHistoryHighlights";
import FilingCenterPanel from "../components/FilingCenterPanel";
import DataPanel from "../components/DataPanel";
import { buildRequestHighlights, fetchMyRequests } from "../api/requests";
import useLiveDateTime from "../hooks/useLiveDateTime";
import useCurrentUser from "../hooks/useCurrentUser";
import { resolveAttendanceMainTag } from "../utils/attendanceTags";


export default function EmployeeDashboard() {
  const navItems = ["Dashboard", "Team", "Attendance", "Schedule"];
  const attendanceNavItems = ["My Attendance", "My Requests", "My Filing Center"];
  const [data, setData] = useState([]);
  const [activeNav, setActiveNav] = useState("Dashboard");
  const [attendanceExpanded, setAttendanceExpanded] = useState(true);
  const isAttendanceView = attendanceNavItems.includes(activeNav);
  const sidebarNavItems = navItems.map(item => {
    if (item === "Attendance") {
      return {
        label: item,
        active: isAttendanceView,
        expanded: attendanceExpanded,
        onClick: () => setAttendanceExpanded(prev => !prev),
        children: attendanceNavItems.map(label => ({
          label,
          active: activeNav === label,
          onClick: () => setActiveNav(label)
        }))
      };
    }

    return {
      label: item,
      active: activeNav === item,
      onClick: () => setActiveNav(item)
    };
  });
  const [attendanceLog, setAttendanceLog] = useState({
    timeInAt: null,
    timeOutAt: null,
    tag: null
  });
  const [attendanceHistory, setAttendanceHistory] = useState([]);
  const [myRequests, setMyRequests] = useState([]);
  const activeCluster = data[0];
  const dateTimeLabel = useLiveDateTime();
  const { user } = useCurrentUser();

  const normalizeSchedule = schedule => {
    if (!schedule) return schedule;
    if (typeof schedule === "string") {
      try {
        return JSON.parse(schedule);
      } catch {
        return schedule;
      }
    }
    return schedule;
  };

  const formatScheduleTime = schedule => {
    if (!schedule || typeof schedule !== "object" || Array.isArray(schedule)) {
      return "Time TBD";
    }
    const startTime = schedule.startTime ?? "9:00";
    const startPeriod = schedule.startPeriod ?? "AM";
    const endTime = schedule.endTime ?? "5:00";
    const endPeriod = schedule.endPeriod ?? "PM";
    return `${startTime} ${startPeriod}–${endTime} ${endPeriod}`;
  };

  const formatBreakTimeRange = (
    startTime,
    startPeriod,
    endTime,
    endPeriod
  ) => {
    if (!startTime || !endTime) return "—";
    return `${startTime} ${startPeriod ?? ""}–${endTime} ${endPeriod ?? ""}`.trim();
  };

  const formatEmployeeDayTime = day => {
    const schedule = activeCluster?.schedule;
    if (!schedule || typeof schedule !== "object" || Array.isArray(schedule)) {
      return "—";
    }

    const assignedDays = Array.isArray(schedule.days) ? schedule.days : [];
    if (!assignedDays.includes(day)) return "—";

    const daySchedule = schedule.daySchedules?.[day];
    if (!daySchedule || typeof daySchedule !== "object") {
      return {
        shift: formatScheduleTime(schedule),
        breakTime: "—"
      };
    }

    return {
      shift: formatScheduleTime(daySchedule),
      breakTime: formatBreakTimeRange(
        daySchedule.breakStartTime,
        daySchedule.breakStartPeriod,
        daySchedule.breakEndTime,
        daySchedule.breakEndPeriod
      )
    };
  };

  const toMinutes = (time, period) => {
    const [hourPart, minutePart] = String(time).split(":");
    const hour = Number(hourPart);
    const minute = Number(minutePart);
    if (
      Number.isNaN(hour) ||
      Number.isNaN(minute) ||
      hour < 1 ||
      hour > 12 ||
      ![0, 30].includes(minute)
    ) {
      return null;
    }

    const normalizedHour = hour % 12;
    const periodOffset = period === "PM" ? 12 * 60 : 0;
    return normalizedHour * 60 + minute + periodOffset;
  };

  const getTodaySchedule = () => {
    const schedule = activeCluster?.schedule;
    if (!schedule || typeof schedule !== "object" || Array.isArray(schedule)) {
      return null;
    }

    const currentDay = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"][new Date().getDay()];
    const assignedDays = Array.isArray(schedule.days) ? schedule.days : [];
    if (!assignedDays.includes(currentDay)) return null;

    return schedule.daySchedules?.[currentDay] ?? null;
  };

  const isTimeWithinRange = (nowMinutes, startTime, startPeriod, endTime, endPeriod) => {
    const startMinutes = toMinutes(startTime, startPeriod);
    const endMinutes = toMinutes(endTime, endPeriod);

    if (startMinutes === null || endMinutes === null || startMinutes === endMinutes) {
      return false;
    }

    if (endMinutes < startMinutes) {
      return nowMinutes >= startMinutes || nowMinutes < endMinutes;
    }

    return nowMinutes >= startMinutes && nowMinutes < endMinutes;
  };

  const getCurrentStatus = () => {
    const daySchedule = getTodaySchedule();
    if (!daySchedule) {
      return { label: "Not available", className: "status-not-available" };
    }

    const now = new Date();
    const nowMinutes = now.getHours() * 60 + now.getMinutes();

    if (
      !isTimeWithinRange(
        nowMinutes,
        daySchedule.startTime,
        daySchedule.startPeriod,
        daySchedule.endTime,
        daySchedule.endPeriod
      )
    ) {
      return { label: "Not available", className: "status-not-available" };
    }

    if (
      isTimeWithinRange(
        nowMinutes,
        daySchedule.breakStartTime,
        daySchedule.breakStartPeriod,
        daySchedule.breakEndTime,
        daySchedule.breakEndPeriod
      )
    ) {
      return { label: "On break time", className: "status-break" };
    }

    return { label: "Available", className: "status-available" };
  };

  const formatDateTimeLabel = value => {
    const parsedDate = value instanceof Date ? value : parseSqlDateTime(value);
    if (!parsedDate || Number.isNaN(parsedDate.getTime())) return "—";

    return new Intl.DateTimeFormat("en-US", {
      month: "short",
      day: "numeric",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit"
    }).format(parsedDate);
  };

  const toDateInputValue = value => {
    const parsedDate = value instanceof Date ? value : parseSqlDateTime(value);
    if (!parsedDate || Number.isNaN(parsedDate.getTime())) return null;

    const year = parsedDate.getFullYear();
    const month = `${parsedDate.getMonth() + 1}`.padStart(2, "0");
    const day = `${parsedDate.getDate()}`.padStart(2, "0");
    return `${year}-${month}-${day}`;
  };

  const parseSqlDateTime = value => {
    if (!value || typeof value !== "string") return null;
    const trimmedValue = value.trim();
    const [datePart, timePart] = trimmedValue.split(" ");

    if (datePart && !timePart && /^\d{4}-\d{2}-\d{2}$/.test(datePart)) {
      const [year, month, day] = datePart.split("-").map(Number);
      if ([year, month, day].some(Number.isNaN)) {
        return new Date(trimmedValue);
      }
      return new Date(year, month - 1, day);
    }

    if (!datePart || !timePart) return new Date(trimmedValue);

    const [year, month, day] = datePart.split("-").map(Number);
    const [hours, minutes, seconds] = timePart.split(":").map(Number);

    if ([year, month, day, hours, minutes].some(Number.isNaN)) {
      return new Date(value);
    }

    return new Date(year, month - 1, day, hours, minutes, Number.isNaN(seconds) ? 0 : seconds);
  };

  const isSameCalendarDay = (firstDate, secondDate) => {
    if (!(firstDate instanceof Date) || Number.isNaN(firstDate.getTime())) return false;
    if (!(secondDate instanceof Date) || Number.isNaN(secondDate.getTime())) return false;

    return (
      firstDate.getFullYear() === secondDate.getFullYear() &&
      firstDate.getMonth() === secondDate.getMonth() &&
      firstDate.getDate() === secondDate.getDate()
    );
  };

  const persistAttendance = async nextAttendance => {
    if (!activeCluster?.cluster_id) {
      setAttendanceLog(nextAttendance);
      return;
    }

    const savedAttendance = await saveDashboardAttendance({
      clusterId: activeCluster.cluster_id,
      nextAttendance
    });

    setAttendanceLog(savedAttendance);

    const history = await apiFetch("api/employee_attendance_history.php");
    setAttendanceHistory(history);
  };

  const handleTimeIn = async () => {
    if (!canUseAttendanceControls) return;
    if (attendanceLog.timeInAt && !attendanceLog.timeOutAt) return;

    const now = new Date();
    const daySchedule = getTodaySchedule();

    if (!daySchedule) {
      await persistAttendance({
        timeInAt: now,
        timeOutAt: null,
        tag: "Late"
      });
      return;
    }

    const scheduledStartMinutes = toMinutes(daySchedule.startTime, daySchedule.startPeriod);
    if (scheduledStartMinutes === null) {
      await persistAttendance({
        timeInAt: now,
        timeOutAt: null,
        tag: "Late"
      });
      return;
    }

    const nowMinutes = now.getHours() * 60 + now.getMinutes();
    const lateThreshold = scheduledStartMinutes + 15;
    const tag = nowMinutes <= lateThreshold ? "On Time" : "Late";

   await persistAttendance({
      timeInAt: now,
      timeOutAt: null,
      tag
    });
  };

  const handleTimeOut = async () => {
    if (!canUseAttendanceControls) return;
    if (!attendanceLog.timeInAt || attendanceLog.timeOutAt) return;

    const nextAttendance = {
      ...attendanceLog,
      timeOutAt: new Date()
    };
    await persistAttendance(nextAttendance);
  };

  const getStatusTag = (statusLabel, isScheduledToday) => {
    if (statusLabel === "On break time") return "Break Time";
    if (statusLabel === "Not available") {
      return isScheduledToday ? "Scheduled" : "Not scheduled";
    }
    if (statusLabel === "Available") return "On Time";
    return null;
  };

  const getActiveDays = schedule => {
    if (!schedule) return [];
    if (Array.isArray(schedule)) {
      return schedule.map(day => day.slice(0, 3));
    }
    if (typeof schedule === "object") {
      const days = Array.isArray(schedule.days) ? schedule.days : [];
      return days.map(day => day.slice(0, 3));
    }
    return [];
  };

  const scheduleDays = getActiveDays(activeCluster?.schedule);
  const dayLabels = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
  const todaySchedule = getTodaySchedule();
  const hasScheduleToday = Boolean(todaySchedule);
  const currentStatus = getCurrentStatus();
  const activeAttendanceTag = resolveAttendanceMainTag({
    attendanceTag: attendanceLog.tag,
    schedule: activeCluster?.schedule,
    timeInAt: attendanceLog.timeInAt,
    fallbackTag: getStatusTag(currentStatus.label, hasScheduleToday)
  });
  const hasActiveTimeIn = Boolean(attendanceLog.timeInAt && !attendanceLog.timeOutAt);
  const hasTeamCluster = Boolean(activeCluster?.cluster_id);
  const canUseAttendanceControls = hasTeamCluster && hasScheduleToday;
  const hasTimedOutToday = isSameCalendarDay(attendanceLog.timeOutAt, new Date());
  const hasCompletedShift = hasTimedOutToday && !hasActiveTimeIn;
  const canClickTimeIn = canUseAttendanceControls && !hasActiveTimeIn && !hasTimedOutToday;
  const canClickTimeOut = hasActiveTimeIn;
  const breakTimeToday = todaySchedule
    ? formatBreakTimeRange(
        todaySchedule.breakStartTime,
        todaySchedule.breakStartPeriod,
        todaySchedule.breakEndTime,
        todaySchedule.breakEndPeriod
      )
    : "—";

  useEffect(() => {
    apiFetch("api/employee_clusters.php").then(response => {
      const normalized = response.map(cluster => ({
        ...cluster,
        schedule: normalizeSchedule(cluster.schedule)
      }));
      setData(normalized);
      const active = normalized[0];
      if (active) {
        setAttendanceLog({
          timeInAt: parseSqlDateTime(active.time_in_at),
          timeOutAt: parseSqlDateTime(active.time_out_at),
          tag: active.attendance_tag ?? null
        });
      }
    });

    apiFetch("api/employee_attendance_history.php").then(response => {
      setAttendanceHistory(response);
    });

    fetchMyRequests().then(response => {
      setMyRequests(Array.isArray(response) ? response : []);
    }).catch(() => setMyRequests([]));
  }, []);


  const myRequestHighlights = buildRequestHighlights(myRequests);

  const handleLogout = async () => {
    try {
      await apiFetch("auth/logout.php", { method: "POST" });
    } catch (error) {
      console.error("Logout failed", error);
    } finally {
      localStorage.removeItem("teamClusterUser");
      window.location.href = "/login";
    }
  };

  return (
    <div className="dashboard">
      <DashboardSidebar
        avatar="EM"
        roleLabel="Employee"
        userName={user?.fullname}
        navItems={sidebarNavItems}
        onLogout={handleLogout}
      />

      <main className="main">
        <header className="topbar">
          <div>
            <h2>{activeNav.toUpperCase()}</h2>
            <div className="section-title">
              {activeNav === "Dashboard"
                ? "Employee time tracking"
                : activeNav === "My Attendance"
                  ? "Attendance history"
                  : activeNav === "My Requests"
                    ? "My requests"
                    : activeNav === "My Filing Center"
                      ? "My filing center"
                      : "My team cluster overview"}
            </div>
          </div>
          <span className="datetime">{dateTimeLabel}</span>
        </header>

        <section className="content content-muted">
            {activeNav === "Dashboard" && (
            <MainDashboard
              attendanceControls={{
                timeInAt: attendanceLog.timeInAt,
                timeOutAt: attendanceLog.timeOutAt,
                canClickTimeIn,
                canClickTimeOut,
                hasCompletedShift,
                onTimeIn: handleTimeIn,
                onTimeOut: handleTimeOut
              }}
              schedule={activeCluster?.schedule ?? null}
              dashboardMeta={{
                attendanceTag: activeAttendanceTag,
                scheduleTag: hasScheduleToday ? "Scheduled today" : "Not scheduled",
                breakTag: currentStatus.label === "On break time" ? "Break time" : "Break inactive",
                breakTime: breakTimeToday,
                availabilityLabel: currentStatus.label
              }}
              canEditCards={false}
            />
          )}

          {data.length === 0 && !isAttendanceView && (
            <div className="empty-state">No team cluster details available.</div>
          )}

          {(isAttendanceView || data.length > 0) && activeNav !== "Dashboard" && (
            <div className="employee-panel">
              {activeNav === "My Attendance" && (
                <div className="employee-card">
                  <div className="employee-card-header">
                    <div className="employee-card-title">My Attendance</div>
                  </div>
                  <div className="employee-card-body">
                    <AttendanceHistoryHighlights />
                    <DataPanel type="attendance" records={attendanceHistory} />
                  </div>
                </div>
                 )}

              {activeNav === "My Requests" && (
                <div className="employee-card">
                  <div className="employee-card-header">
                    <div className="employee-card-title">My Requests</div>
                  </div>
                  <div className="employee-card-body">
                    <AttendanceHistoryHighlights highlights={myRequestHighlights} />
                    <DataPanel type="requests" records={myRequests} />
                  </div>
                </div>
              )}

              {activeNav === "My Filing Center" && (
                <FilingCenterPanel onSubmitted={() => fetchMyRequests().then(response => setMyRequests(Array.isArray(response) ? response : [])).catch(() => setMyRequests([]))} />
              )}

              {!isAttendanceView && (
                <>
              <div className="employee-card">
                <div className="employee-card-header">
                  <div className="employee-card-title">My Team Cluster Details</div>
                </div>
                <div className="employee-card-body">
                  <div className="employee-overview-grid">
                    <div className="employee-field employee-highlight-field">
                      <div className="employee-field-label">Cluster Name</div>
                      <div className="employee-field-value">
                        {activeCluster?.cluster_name ?? "Not assigned"}
                      </div>
                    </div>
                  <div className="employee-field employee-highlight-field">
                      <div className="employee-field-label">Team Coach</div>
                      <div className="employee-field-value">
                        {activeCluster?.coach_name ?? "Pending"}
                      </div>
                    </div>
                    <div className="employee-field employee-inline-stat">
                      <div className="employee-field-label">Assigned Days</div>
                      <div className="employee-field-value employee-stat-value">
                        {scheduleDays.length}
                      </div>
                    </div>
                    <div className="employee-field employee-inline-stat">
                      <div className="employee-field-label">Weekly Status</div>
                      <div className="employee-field-value employee-stat-value">
                        {scheduleDays.length > 0 ? "Schedule set" : "Pending"}
                      </div>
                    </div>
                  </div>
                </div>
                <div className="employee-card-footer">
                </div>
              </div>

          <div className="employee-card">
                <div className="employee-card-header">
                  <div className="employee-card-title">My Schedule</div>
                </div>
                <div className="employee-card-body">
                  <div className="active-members-schedule-table employee-schedule-table" role="table" aria-label="My schedule">
                    <div className="active-members-schedule-header" role="row">
                      <span role="columnheader">Member</span>
                      {dayLabels.map(day => (
                        <span key={`${day}-header`} role="columnheader">{day}</span>
                      ))}
                      <span role="columnheader">Status and Tags</span>
                    </div>
                    <div className="active-members-schedule-row" role="row">
                      <div className="active-members-owner" role="cell">
                        {user?.fullname ?? "Employee"}
                      </div>
                      {dayLabels.map(day => {
                        const dayInfo = formatEmployeeDayTime(day);

                        if (typeof dayInfo === "string") {
                          return (
                            <div key={`${day}-value`} role="cell">{dayInfo}</div>
                          );
                        }

                        return (
                          <div key={`${day}-value`} role="cell" className="active-day-cell">
                            <div>{dayInfo.shift}</div>
                            <span className="active-day-tag break-tag">
                              Break time: {dayInfo.breakTime}
                            </span>
                          </div>
                        );
                      })}
                      <div role="cell" className="member-status-and-tags-cell">
                        <span className={`member-status-pill ${currentStatus.className}`}>
                          {currentStatus.label}
                        </span>
                        <div className="member-status-tag-list" aria-label="Status tags">
                          <span className="member-status-tag is-active">
                            {activeAttendanceTag ?? "Pending"}
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div className="employee-schedule-caption">
                  </div>
                </div>
              </div>
              </>
              )}
            </div>
          )}
        </section>
      </main>
    </div>
  );
}