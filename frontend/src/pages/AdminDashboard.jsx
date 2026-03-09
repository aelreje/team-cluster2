import { useCallback, useEffect, useState } from "react";
import { apiFetch } from "../api/api";
import DashboardSidebar from "../components/DashboardSidebar";
import MainDashboard from "./MainDashboard";
import useLiveDateTime from "../hooks/useLiveDateTime";
import useCurrentUser from "../hooks/useCurrentUser";

export default function AdminDashboard() {
  const dayOptions = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
  const workSetupOptions = ["Onsite", "Work From Home (WFH)"];
  const defaultDaySchedule = {
    shiftType: "Morning Shift",
    startTime: "9:00",
    startPeriod: "AM",
    endTime: "6:00",
    endPeriod: "PM",
    workSetup: "Onsite",
    breakStartTime: "3:00",
    breakStartPeriod: "PM",
    breakEndTime: "3:30",
    breakEndPeriod: "PM"
  };
  const timeOptions = Array.from({ length: 24 }, (_, index) => {
    const hour = Math.floor(index / 2) + 1;
    const minute = (index % 2) * 30;
    return `${hour}:${minute.toString().padStart(2, "0")}`;
  });
  const MAX_SHIFT_MINUTES = 9 * 60;
  const [clusters, setClusters] = useState([]);
  const [rejectingCluster, setRejectingCluster] = useState(null);
  const [activeNav, setActiveNav] = useState("Team");
  const [rejectionReason, setRejectionReason] = useState("");
  const [rejectError, setRejectError] = useState("");
  const [isSubmittingReject, setIsSubmittingReject] = useState(false);
  const [coachAttendance, setCoachAttendance] = useState([]);
  const [managingScheduleCluster, setManagingScheduleCluster] = useState(null);
  const [scheduleModalMessage, setScheduleModalMessage] = useState("");
  const [scheduleForm, setScheduleForm] = useState({
    days: ["Mon", "Tue", "Wed", "Thu", "Fri"],
    daySchedules: {
      Mon: { ...defaultDaySchedule },
      Tue: { ...defaultDaySchedule },
      Wed: { ...defaultDaySchedule },
      Thu: { ...defaultDaySchedule },
      Fri: { ...defaultDaySchedule }
    }
  });
  const [attendanceDate, setAttendanceDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [editingCoachAttendance, setEditingCoachAttendance] = useState(null);
  const [editForm, setEditForm] = useState({ timeInAt: "", timeOutAt: "", tag: "", note: "" });
  const dateTimeLabel = useLiveDateTime();
  const { user } = useCurrentUser();
  const navItems = [
    { label: "Dashboard", active: activeNav === "Dashboard", onClick: () => setActiveNav("Dashboard") },
    { label: "Team", active: activeNav === "Team", onClick: () => setActiveNav("Team") },
    { label: "Attendance", active: activeNav === "Attendance", onClick: () => setActiveNav("Attendance") },
    { label: "Schedule", active: activeNav === "Schedule", onClick: () => setActiveNav("Schedule") }
  ];


  const toMinutes = (time, period) => {
    const [hourPart, minutePart] = String(time).split(":");
    const hour = Number(hourPart);
    const minute = Number(minutePart);
    if (Number.isNaN(hour) || Number.isNaN(minute) || hour < 1 || hour > 12 || ![0, 30].includes(minute)) {
      return null;
    }
    const normalizedHour = hour % 12;
    return normalizedHour * 60 + minute + (period === "PM" ? 12 * 60 : 0);
  };

  const getTimeOptionsWithinRange = (startTime, startPeriod, endTime, endPeriod) => {
    const startMinutes = toMinutes(startTime, startPeriod);
    const endMinutes = toMinutes(endTime, endPeriod);
    if (startMinutes === null || endMinutes === null) return [];

    let rangeEndMinutes = endMinutes;
    if (endMinutes < startMinutes) rangeEndMinutes += 24 * 60;

    const options = [];
    let current = startMinutes;
    while (current <= rangeEndMinutes) {
      const normalizedMinutes = ((current % (24 * 60)) + 24 * 60) % (24 * 60);
      const hour24 = Math.floor(normalizedMinutes / 60);
      const minute = normalizedMinutes % 60;
      const period = hour24 >= 12 ? "PM" : "AM";
      const hour12 = hour24 % 12 || 12;
      options.push({ time: `${hour12}:${String(minute).padStart(2, "0")}`, period });
      current += 30;
    }

    return options;
  };

  const getEndTimeOptions = (startTime, startPeriod) => {
    const startMinutes = toMinutes(startTime, startPeriod);
    if (startMinutes === null) {
      return timeOptions.map(time => ({ time, period: "AM" }));
    }

    const validOptions = [];
    for (let offset = 30; offset <= MAX_SHIFT_MINUTES; offset += 30) {
      const totalMinutes = startMinutes + offset;
      const normalizedMinutes = ((totalMinutes % (24 * 60)) + 24 * 60) % (24 * 60);
      const hour24 = Math.floor(normalizedMinutes / 60);
      const minute = normalizedMinutes % 60;
      const period = hour24 >= 12 ? "PM" : "AM";
      const hour12 = hour24 % 12 || 12;
      validOptions.push({
        time: `${hour12}:${String(minute).padStart(2, "0")}`,
        period
      });
    }

    return validOptions;
  };

  const getMinutesBetween = (startTime, startPeriod, endTime, endPeriod) => {
    const startMinutes = toMinutes(startTime, startPeriod);
    const endMinutes = toMinutes(endTime, endPeriod);
    if (startMinutes === null || endMinutes === null) return 0;
    if (endMinutes < startMinutes) return endMinutes + 24 * 60 - startMinutes;
    return endMinutes - startMinutes;
  };

  const formatBreakTimeRange = (startTime, startPeriod, endTime, endPeriod) => {
    if (!startTime || !startPeriod || !endTime || !endPeriod) return "—";
    return `${startTime} ${startPeriod} - ${endTime} ${endPeriod}`;
  };

  const getAutomaticShiftType = (startTime, startPeriod) => {
    const startMinutes = toMinutes(startTime, startPeriod);
    if (startMinutes === null) return "Morning Shift";
    if (startMinutes >= 6 * 60 && startMinutes <= 11 * 60 + 30) return "Morning Shift";
    if (startMinutes >= 12 * 60 && startMinutes <= 19 * 60 + 30) return "Mid Shift";
    return "Night Shift";
  };

  const fetchClusters = useCallback(async () => {
    try {
      const data = await apiFetch("api/admin_cluster.php");
      setClusters(data);
    } catch (error) {
      console.error("Failed to load clusters", error);
    }
  }, []);

  useEffect(() => {
    fetchClusters();
    const interval = setInterval(fetchClusters, 5000);
    return () => clearInterval(interval);
  }, [fetchClusters]);

  useEffect(() => {
    if (activeNav !== "Attendance") return;
    apiFetch(`api/admin_coach_attendance.php?attendance_date=${attendanceDate}`)
      .then(data => setCoachAttendance(Array.isArray(data) ? data : []))
      .catch(() => setCoachAttendance([]));
  }, [activeNav, attendanceDate]);


  const toDateTimeLocalValue = value => {
    if (!value) return "";
    const date = new Date(value.replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return "";
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, "0");
    const day = `${date.getDate()}`.padStart(2, "0");
    const hours = `${date.getHours()}`.padStart(2, "0");
    const minutes = `${date.getMinutes()}`.padStart(2, "0");
    return `${year}-${month}-${day}T${hours}:${minutes}`;
  };

  const openAttendanceEdit = row => {
    setEditingCoachAttendance(row);
    setEditForm({
      timeInAt: toDateTimeLocalValue(row.time_in_at),
      timeOutAt: toDateTimeLocalValue(row.time_out_at),
      tag: row.attendance_tag ?? "",
      note: row.attendance_note ?? ""
    });
  };

  const saveCoachAttendanceEdit = async () => {
    if (!editingCoachAttendance?.attendance_id) return;
    await apiFetch("api/admin_update_coach_attendance.php", {
      method: "POST",
      body: JSON.stringify({
        attendance_id: editingCoachAttendance.attendance_id,
        timeInAt: editForm.timeInAt ? `${editForm.timeInAt.replace("T", " ")}:00` : null,
        timeOutAt: editForm.timeOutAt ? `${editForm.timeOutAt.replace("T", " ")}:00` : null,
        tag: editForm.tag,
        note: editForm.note
      })
    });
    setEditingCoachAttendance(null);
    const refreshed = await apiFetch(`api/admin_coach_attendance.php?attendance_date=${attendanceDate}`);
    setCoachAttendance(Array.isArray(refreshed) ? refreshed : []);
  };

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

  const handleToggleScheduleDay = day => {
    setScheduleModalMessage("");
    setScheduleForm(current => {
      const exists = current.days.includes(day);
      const nextDays = exists ? current.days.filter(item => item !== day) : [...current.days, day];
      const nextSchedules = { ...current.daySchedules };
      if (!nextSchedules[day]) nextSchedules[day] = { ...defaultDaySchedule };
      return { days: nextDays, daySchedules: nextSchedules };
    });
  };

  const handleChangeDayTime = (day, field, value) => {
    setScheduleModalMessage("");
    setScheduleForm(current => {
      const currentDay = current.daySchedules[day] ?? { ...defaultDaySchedule };
      const nextDay = { ...currentDay };
      const [time, period] = String(value).split("|");

      if (["endTime", "breakStart", "breakEnd"].includes(field)) {
        if (field === "endTime") {
          nextDay.endTime = time ?? currentDay.endTime;
          nextDay.endPeriod = period ?? currentDay.endPeriod;
        }

        if (field === "breakStart") {
          nextDay.breakStartTime = time ?? currentDay.breakStartTime;
          nextDay.breakStartPeriod = period ?? currentDay.breakStartPeriod;
        }

        if (field === "breakEnd") {
          nextDay.breakEndTime = time ?? currentDay.breakEndTime;
          nextDay.breakEndPeriod = period ?? currentDay.breakEndPeriod;
        }
      } else if (field === "startTime") {
        nextDay.startTime = time ?? currentDay.startTime;
        nextDay.startPeriod = period ?? currentDay.startPeriod;
      } else {
        nextDay[field] = value;
      }

      const endTimeOptions = getEndTimeOptions(nextDay.startTime, nextDay.startPeriod);
      const hasSelectedEndTime = endTimeOptions.some(
        option => option.time === nextDay.endTime && option.period === nextDay.endPeriod
      );
      if (!hasSelectedEndTime && endTimeOptions.length > 0) {
        nextDay.endTime = endTimeOptions[0].time;
        nextDay.endPeriod = endTimeOptions[0].period;
      }

      const shiftRangeOptions = getTimeOptionsWithinRange(
        nextDay.startTime,
        nextDay.startPeriod,
        nextDay.endTime,
        nextDay.endPeriod
      );
      const hasBreakStart = shiftRangeOptions.some(
        option => option.time === nextDay.breakStartTime && option.period === nextDay.breakStartPeriod
      );
      if (!hasBreakStart && shiftRangeOptions.length > 0) {
        const fallbackBreak = shiftRangeOptions[Math.min(1, shiftRangeOptions.length - 1)] ?? shiftRangeOptions[0];
        nextDay.breakStartTime = fallbackBreak.time;
        nextDay.breakStartPeriod = fallbackBreak.period;
      }

      const breakEndOptions = getTimeOptionsWithinRange(
        nextDay.breakStartTime,
        nextDay.breakStartPeriod,
        nextDay.endTime,
        nextDay.endPeriod
      );
      const hasBreakEnd = breakEndOptions.some(
        option => option.time === nextDay.breakEndTime && option.period === nextDay.breakEndPeriod
      );
      if (!hasBreakEnd && breakEndOptions.length > 0) {
        nextDay.breakEndTime = breakEndOptions[0].time;
        nextDay.breakEndPeriod = breakEndOptions[0].period;
      }

      nextDay.shiftType = getAutomaticShiftType(nextDay.startTime, nextDay.startPeriod);

      return {
        ...current,
        daySchedules: {
          ...current.daySchedules,
          [day]: nextDay
        }
      };
    });
  };

  const handleOpenScheduleModal = cluster => {
    setManagingScheduleCluster(cluster);
    setScheduleModalMessage("");
  };

  const handleCloseScheduleModal = () => {
    setManagingScheduleCluster(null);
    setScheduleModalMessage("");
  };

  const handleCreateSchedule = () => {
    if (!managingScheduleCluster) return;
    setScheduleModalMessage(`Schedule draft ready for ${managingScheduleCluster.coach} (${managingScheduleCluster.name}).`);
  };

  async function updateStatus(id, status, reason = "") {
    await apiFetch("api/approve_cluster.php", {
      method: "POST",
      body: JSON.stringify({
        cluster_id: id,
        status,
        rejection_reason: status === "rejected" ? reason : ""
      })
    });
    fetchClusters();
  }

const handleOpenRejectModal = cluster => {
    setRejectingCluster(cluster);
    setRejectionReason("");
    setRejectError("");
  };

  const handleCloseRejectModal = () => {
    setRejectingCluster(null);
    setRejectionReason("");
    setRejectError("");
  };

  const handleSubmitReject = async () => {
    const reason = rejectionReason.trim();
    if (!reason) {
      setRejectError("Please provide a reason before rejecting this team.");
      return;
    }

    if (!rejectingCluster) return;

    try {
      setIsSubmittingReject(true);
      await updateStatus(rejectingCluster.id, "rejected", reason);
      setRejectingCluster(null);
      setRejectionReason("");
      setRejectError("");
    } catch (error) {
      console.error("Failed to reject cluster", error);
      setRejectError("Unable to reject the cluster right now. Please try again.");
    } finally {
      setIsSubmittingReject(false);
    }
  };

  const formatDate = dateString => {
    if (!dateString) return "—";
    const parsed = new Date(dateString);
    if (Number.isNaN(parsed.valueOf())) return dateString;
    return parsed.toISOString().slice(0, 10);
  };

  return (
    <div className="dashboard">
      <DashboardSidebar
        avatar="AD"
        roleLabel="Admin"
        userName={user?.fullname}
        navItems={navItems}
        onLogout={handleLogout}
      />

      <main className="main">
        {activeNav === "Dashboard" ? (
          <section className="content">
            <MainDashboard />
          </section>
        ) : activeNav === "Team" ? (
          <>
            <header className="topbar">
              <div>
                <h2>TEAM</h2>
                <div className="section-title">Admin Dashboard</div>
              </div>
              <span className="datetime">{dateTimeLabel}</span>
            </header>

            <section className="content">
              <div className="section-title">Team clusters</div>
            {clusters.length === 0 ? (
                <div className="empty-state">No team clusters available.</div>
              ) : (
                <div className="table-card">
                  <div className="table-header">
                    <div>Cluster Name</div>
                    <div>Description</div>
                    <div>Members</div>
                    <div>Created</div>
                    <div>Status</div>
                    <div>Rejection Reason</div>
                    <div>Action</div>
                  </div>
                  {clusters.map(c => (
                    <div key={c.id} className="table-row">
                      <div className="table-cell">{c.name}</div>
                      <div className="table-cell muted">{c.description}</div>
                      <div className="table-cell">{c.members ?? 0}</div>
                      <div className="table-cell">{formatDate(c.created_at)}</div>
                      <div className="table-cell">
                        <span className={`badge ${c.status}`}>{c.status}</span>
                      </div>
                      <div className="table-cell muted">
                        {c.rejection_reason || "—"}
                      </div>
                      <div className="table-cell">
                        {c.status === "pending" ? (
                          <div className="card-actions">
                            <button
                              className="btn primary"
                              onClick={() => updateStatus(c.id, "active")}
                            >
                              Accept
                            </button>
                            <button
                              className="btn secondary"
                              onClick={() => handleOpenRejectModal(c)}
                            >
                              Reject
                            </button>
                          </div>
                        ) : (
                          <span className="table-cell muted">—</span>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
          </section>
          </>
        ) : activeNav === "Attendance" ? (
          <section className="content">
            <div className="section-title">Coach Attendance</div>
            <label className="attendance-date" htmlFor="admin-coach-attendance-date">
              <span>Date</span>
              <input id="admin-coach-attendance-date" type="date" value={attendanceDate} onChange={event => setAttendanceDate(event.target.value)} />
            </label>
            {coachAttendance.length === 0 ? (
              <div className="empty-state">No coach attendance records for selected date.</div>
            ) : (
              <div className="table-card">
                <div className="table-header">
                  <div>Coach</div><div>Cluster</div><div>Time In</div><div>Time Out</div><div>Tag</div><div>Action</div>
                </div>
                {coachAttendance.map(row => (
                  <div key={`${row.cluster_id}-${row.coach_id}`} className="table-row">
                    <div className="table-cell">{row.coach_name}</div>
                    <div className="table-cell">{row.cluster_name}</div>
                    <div className="table-cell">{row.time_in_at ?? "—"}</div>
                    <div className="table-cell">{row.time_out_at ?? "—"}</div>
                    <div className="table-cell">{row.attendance_tag ?? "—"}</div>
                    <div className="table-cell">
                      <button className="btn" type="button" disabled={!row.attendance_id} onClick={() => openAttendanceEdit(row)}>Edit</button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </section>
        ) : (
          <section className="content">
            <div className="section-title">Team Coach Schedule</div>
            {clusters.length === 0 ? (
              <div className="empty-state">No team clusters available.</div>
            ) : (
              <>
                <div className="table-card">
                  <div className="table-header">
                    <div>Cluster Name</div>
                    <div>Coach</div>
                    <div>Members</div>
                    <div>Status</div>
                    <div>Action</div>
                  </div>
                  {clusters.map(cluster => (
                    <div key={cluster.id} className="table-row">
                      <div className="table-cell">{cluster.name}</div>
                      <div className="table-cell">{cluster.coach}</div>
                      <div className="table-cell">{cluster.members ?? 0}</div>
                      <div className="table-cell">
                        <span className={`badge ${cluster.status}`}>{cluster.status}</span>
                      </div>
                      <div className="table-cell">
                        <button className="btn primary" type="button" onClick={() => handleOpenScheduleModal(cluster)}>
                          Manage
                        </button>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="section-title">Team Coach Schedule by Coach</div>
                <div className="table-card">
                  <div className="table-header">
                    <div>Coach</div>
                    <div>Cluster Name</div>
                    <div>Members</div>
                    <div>Status</div>
                    <div>Action</div>
                  </div>
                  {[...clusters]
                    .sort((a, b) => (a.coach ?? "").localeCompare(b.coach ?? ""))
                    .map(cluster => (
                      <div key={`coach-${cluster.id}`} className="table-row">
                        <div className="table-cell">{cluster.coach || "—"}</div>
                        <div className="table-cell">{cluster.name}</div>
                        <div className="table-cell">{cluster.members ?? 0}</div>
                        <div className="table-cell">
                          <span className={`badge ${cluster.status}`}>{cluster.status}</span>
                        </div>
                        <div className="table-cell">
                          <button className="btn primary" type="button" onClick={() => handleOpenScheduleModal(cluster)}>
                            Manage
                          </button>
                        </div>
                      </div>
                    ))}
                </div>
              </>
            )}
          </section>
        )}
      </main>

      {editingCoachAttendance && (
        <div className="modal-overlay" role="dialog" aria-modal="true">
          <div className="modal-card reject-modal-card">
            <div className="modal-header">
              <div className="modal-title">Edit Coach Attendance</div>
              <button className="btn link modal-close-btn" type="button" onClick={() => setEditingCoachAttendance(null)}>Close</button>
            </div>
            <div className="modal-body">
              <label className="form-field">Time In<input type="datetime-local" value={editForm.timeInAt} onChange={event => setEditForm(curr => ({ ...curr, timeInAt: event.target.value }))} /></label>
              <label className="form-field">Time Out<input type="datetime-local" value={editForm.timeOutAt} onChange={event => setEditForm(curr => ({ ...curr, timeOutAt: event.target.value }))} /></label>
              <label className="form-field">Tag<input type="text" value={editForm.tag} onChange={event => setEditForm(curr => ({ ...curr, tag: event.target.value }))} /></label>
              <label className="form-field">Note<input type="text" value={editForm.note} onChange={event => setEditForm(curr => ({ ...curr, note: event.target.value }))} /></label>
              <div className="form-actions"><button className="btn" type="button" onClick={saveCoachAttendanceEdit}>Save</button></div>
            </div>
          </div>
        </div>
      )}

      {managingScheduleCluster && (
        <div className="modal-overlay" role="dialog" aria-modal="true">
          <div className="modal-card schedule-modal">
            <div className="modal-header">
              <div>
                <div className="modal-title">Manage Schedule</div>
                <div className="modal-subtitle">
                  Cluster: {managingScheduleCluster.name} · Coach: {managingScheduleCluster.coach}
                </div>
              </div>
              <button className="btn link modal-close-btn" type="button" onClick={handleCloseScheduleModal}>
                Close
              </button>
            </div>
            <div className="modal-body">
              <div className="schedule-card">
                <div className="schedule-heading">
                  <div className="schedule-label">Schedule Details</div>
                  <p className="schedule-helper-text">
                    Turn days on or off, then update the work shift and break windows.
                  </p>
                </div>
                <div className="schedule-day-grid">
                  {dayOptions.map(day => {
                    const isWorkingDay = scheduleForm.days.includes(day);
                    const daySchedule = scheduleForm.daySchedules[day] ?? { ...defaultDaySchedule };
                    const endTimeOptions = getEndTimeOptions(daySchedule.startTime, daySchedule.startPeriod);
                    const shiftRangeOptions = getTimeOptionsWithinRange(daySchedule.startTime, daySchedule.startPeriod, daySchedule.endTime, daySchedule.endPeriod);
                    const breakEndOptions = getTimeOptionsWithinRange(daySchedule.breakStartTime, daySchedule.breakStartPeriod, daySchedule.endTime, daySchedule.endPeriod);
                    const shiftHours = getMinutesBetween(daySchedule.startTime, daySchedule.startPeriod, daySchedule.endTime, daySchedule.endPeriod);
                    const shiftHoursLabel = `${Math.floor(shiftHours / 60)}h ${shiftHours % 60}m`;
                    const breakMinutes = getMinutesBetween(daySchedule.breakStartTime, daySchedule.breakStartPeriod, daySchedule.breakEndTime, daySchedule.breakEndPeriod);
                    const breakLabel = `${Math.floor(breakMinutes / 60)}h ${breakMinutes % 60}m`;

                    return (
                      <div key={day} className="schedule-day-row">
                        <div className="schedule-day-header">
                          <label className="schedule-day-toggle">
                            <input type="checkbox" checked={isWorkingDay} onChange={() => handleToggleScheduleDay(day)} />
                            <span>{day}</span>
                          </label>
                          <span className={`schedule-day-status ${isWorkingDay ? "is-working" : "is-off"}`}>
                            {isWorkingDay ? "Working day" : "Off day"}
                          </span>
                        </div>
                        {isWorkingDay ? (
                          <div className="schedule-time-grid schedule-time-grid-layout">
                            <div className="schedule-panel">
                              <div className="schedule-panel-title">Main Shift</div>
                              <div className="schedule-time-row schedule-field">
                                <div className="schedule-time-label">Start Time</div>
                                <div className="schedule-start-time">
                                  <select value={daySchedule.startTime} onChange={event => handleChangeDayTime(day, "startTime", `${event.target.value}|${daySchedule.startPeriod}`)}>
                                    {timeOptions.map(time => (<option key={`${day}-start-${time}`} value={time}>{time}</option>))}
                                  </select>
                                  <select value={daySchedule.startPeriod} onChange={event => handleChangeDayTime(day, "startTime", `${daySchedule.startTime}|${event.target.value}`)}>
                                    <option value="AM">AM</option>
                                    <option value="PM">PM</option>
                                  </select>
                                </div>
                              </div>
                              <div className="schedule-time-row schedule-field">
                                <div className="schedule-time-label">End Time</div>
                                <select value={`${daySchedule.endTime}|${daySchedule.endPeriod}`} onChange={event => handleChangeDayTime(day, "endTime", event.target.value)}>
                                  {endTimeOptions.map(option => (<option key={`${day}-end-${option.time}-${option.period}`} value={`${option.time}|${option.period}`}>{option.time} {option.period}</option>))}
                                </select>
                              </div>
                              <div className="schedule-panel-total">Total: {shiftHoursLabel}</div>
                            </div>
                            <div className="schedule-panel">
                              <div className="schedule-panel-title">Shift Details</div>
                              <div className="schedule-time-row schedule-field">
                                <div className="schedule-time-label">Shift Type</div>
                                <input type="text" value={daySchedule.shiftType} readOnly />
                              </div>
                              <div className="schedule-time-row schedule-field">
                                <div className="schedule-time-label">Work Setup</div>
                                <select value={daySchedule.workSetup} onChange={event => handleChangeDayTime(day, "workSetup", event.target.value)}>
                                  {workSetupOptions.map(option => (<option key={`${day}-work-setup-${option}`} value={option}>{option}</option>))}
                                </select>
                              </div>
                            </div>
                            <div className="schedule-panel">
                              <div className="schedule-panel-title">Scheduled Breaks</div>
                              <div className="schedule-time-row schedule-field">
                                <div className="schedule-time-label">Break Start</div>
                                <select className="schedule-break-select" value={`${daySchedule.breakStartTime}|${daySchedule.breakStartPeriod}`} onChange={event => handleChangeDayTime(day, "breakStart", event.target.value)}>
                                  {shiftRangeOptions.map(option => (<option key={`${day}-break-start-${option.time}-${option.period}`} value={`${option.time}|${option.period}`}>{option.time} {option.period}</option>))}
                                </select>
                              </div>
                              <div className="schedule-time-row schedule-field">
                                <div className="schedule-time-label">Break End</div>
                                <select className="schedule-break-select" value={`${daySchedule.breakEndTime}|${daySchedule.breakEndPeriod}`} onChange={event => handleChangeDayTime(day, "breakEnd", event.target.value)}>
                                  {breakEndOptions.map(option => (<option key={`${day}-break-end-${option.time}-${option.period}`} value={`${option.time}|${option.period}`}>{option.time} {option.period}</option>))}
                                </select>
                              </div>
                              <div className="schedule-panel-total">Total Break: {breakLabel}</div>
                              <div className="modal-text">{formatBreakTimeRange(daySchedule.breakStartTime, daySchedule.breakStartPeriod, daySchedule.breakEndTime, daySchedule.breakEndPeriod)}</div>
                            </div>
                          </div>
                        ) : (
                          <div className="schedule-not-working">
                            Day is marked as off. Enable it to edit shift and break settings.
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
              {scheduleModalMessage && <div className="success-message">{scheduleModalMessage}</div>}
              <div className="form-actions">
                <button className="btn secondary" type="button" onClick={handleCloseScheduleModal}>
                  Cancel
                </button>
                <button className="btn primary" type="button" onClick={handleCreateSchedule}>
                  Save Schedule
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {rejectingCluster && (
        <div className="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
          <div className="modal-card reject-modal-card">
            <div className="modal-header">
              <div>
                <div id="reject-modal-title" className="modal-title reject-modal-title">Reject Team Request</div>
                <div className="modal-subtitle">{rejectingCluster.name}</div>
              </div>
              <button className="btn link modal-close-btn" type="button" onClick={handleCloseRejectModal}>
                Close
              </button>
            </div>
            <div className="modal-body">
              <p className="modal-text">Please share a clear reason so the team can improve and resubmit.</p>
              <label className="form-field" htmlFor="reject-reason">
                Rejection Reason
                <textarea
                  id="reject-reason"
                  rows={4}
                  value={rejectionReason}
                  onChange={event => {
                    setRejectionReason(event.target.value);
                    if (rejectError) setRejectError("");
                  }}
                  placeholder="Example: Team schedule overlaps with required on-site coverage."
                  autoFocus
                />
              </label>
              {rejectError && <div className="error">{rejectError}</div>}
              <div className="form-actions">
                <button className="btn" type="button" onClick={handleCloseRejectModal} disabled={isSubmittingReject}>
                  Cancel
                </button>
                <button className="btn danger" type="button" onClick={handleSubmitReject} disabled={isSubmittingReject}>
                  {isSubmittingReject ? "Rejecting..." : "Confirm Reject"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
