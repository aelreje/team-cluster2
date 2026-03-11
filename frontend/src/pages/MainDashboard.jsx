import { useEffect, useMemo, useState } from "react";
import "../styles/MainDashboard.css";

function DashboardHeader({ headerTime, headerDate }) {
  return (
    <section className="dashboard-header">
      <div className="datetime">{headerTime}&nbsp;&nbsp;&nbsp;{headerDate}</div>
    </section>
  );
}

function TimeCard({
  counterDisplay,
  hasActiveTimeIn,
  onToggleTimeIn,
  canToggleTimeIn,
  hasCompletedShift = false,
}) {
  return (
    <div className="card time-card">
      <div className="time-panel">
        <div className="time-counter">{counterDisplay}</div>

        {hasCompletedShift ? (
          <p className="time-complete-message">Thank you for your hard work.</p>
        ) : (
          <button
            type="button"
            className="time-in-btn"
            onClick={onToggleTimeIn}
            disabled={!canToggleTimeIn}
          >
            {hasActiveTimeIn ? "Time Out" : "Time In"}
          </button>
        )}
      </div>
    </div>
  );
}

function AnnouncementCard({ canEdit = true }) {
  return (
    <div className="card announcement-card">
      <div className="card-top">
        <span>Announcement</span>
        {canEdit ? <button type="button" className="pill-btn">+ Announcement</button> : null}
      </div>
      <ul className="list-items announcement-list" aria-label="No announcements yet" />
      <div className="mini-actions">{canEdit ? "✎\u00A0\u00A0" : null}◷</div>
    </div>
  );
}



function formatShiftTime(time, period) {
  if (!time) return "--";
  return `${time} ${period ?? ""}`.trim();
}

function getTodayShiftSchedule(schedule) {
  if (!schedule || typeof schedule !== "object" || Array.isArray(schedule)) {
    return null;
  }

  const todayKey = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"][new Date().getDay()];
  const assignedDays = Array.isArray(schedule.days) ? schedule.days : [];
  if (assignedDays.length > 0 && !assignedDays.includes(todayKey)) {
    return null;
  }

  const daySchedule = schedule.daySchedules?.[todayKey];
  if (daySchedule && typeof daySchedule === "object") {
    return daySchedule;
  }

  return schedule;
}

function ShiftCard({ schedule = null, dashboardMeta = null }) {
  const shiftSchedule = getTodayShiftSchedule(schedule);
  const startTime = formatShiftTime(shiftSchedule?.startTime, shiftSchedule?.startPeriod);
  const endTime = formatShiftTime(shiftSchedule?.endTime, shiftSchedule?.endPeriod);
  const shiftDayName = new Date().toLocaleDateString("en-US", { weekday: "long" });

  return (
    <div className="card shift-card">
      <div className="card-top">
        <span>Current Shift</span>
        <span className="shift-day-name">{shiftDayName}</span>
      </div>
      <div className="shift-columns">
        <div className="shift-stat">
          <div className="label">Shift Start Time</div>
          <div className="value">{startTime}</div>
        </div>
        <div className="shift-stat">
          <div className="label">Shift End Time</div>
          <div className="value">{endTime}</div>
        </div>
      </div>
      <div className="shift-meta">
        <span className="shift-meta-pill">{dashboardMeta?.scheduleTag ?? "Not scheduled"}</span>
        <span className="shift-meta-break">Break: {dashboardMeta?.breakTime ?? "—"}</span>
      </div>
    </div>
  );
}

function CalendarCard({ calendarData }) {
  return (
    <div className="card calendar-card">
      <div className="card-top">
        <span>Calendar</span>
        <span className="calendar-month">{calendarData.monthLabel}</span>
      </div>
      <div className="calendar-grid weekdays">
        {calendarData.weekDays.map(weekday => (
          <div key={weekday} className="calendar-cell header">{weekday}</div>
        ))}
      </div>
      <div className="calendar-grid dates">
        {calendarData.cells.map((cell, index) => (
          <div
            key={`${cell.day}-${index}`}
            className={`calendar-cell ${cell.muted ? "muted" : ""} ${cell.isToday ? "today" : ""}`}
          >
            {cell.day}
          </div>
        ))}
      </div>
    </div>
  );
}

function HolidayCard({ canEdit = true }) {
  return (
    <div className="card holiday-card">
      <div className="card-top">
        <span>Holidays/Birthday</span>
        {canEdit ? <span className="plus">+</span> : null}
      </div>
      <ul className="list-items holiday-list" aria-label="No holidays or birthdays yet" />
      <div className="mini-actions">{canEdit ? "✎\u00A0\u00A0" : null}◷</div>
    </div>
  );
}


function SummaryCard({ timeInStart, totalHours, dashboardMeta = null }) {
  const isPresent = Boolean(timeInStart);
  const availabilityLabel = dashboardMeta?.availabilityLabel ?? "Available";
  const isAvailable = !/not\s+available|unavailable/i.test(availabilityLabel);
  return (
    <div className="card summary-card">
      <div className="summary-section summary-section-status">
        <div className="summary-label">Today Status</div>
        <div className="summary-list">
          <div className="summary-row"><span>Time In</span><strong>{timeInStart ? timeInStart.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : '--:--'}</strong></div>
          <div className="summary-row"><span>Break</span><strong>{dashboardMeta?.breakTag ?? "Break inactive"}</strong></div>
          <div className="summary-row">
            <span>Status</span>
            <strong className={`summary-status-value ${isAvailable ? "is-available" : "is-unavailable"}`}>
              <span className="summary-status-dot" aria-hidden="true" />
              {availabilityLabel}
            </strong>
          </div>
        </div>
      </div>
      <div className="summary-section">
        <div className="summary-label">Total Hours</div>
        <div className="big-value">{totalHours}h</div>
      </div>
      <div className="summary-section">
        <div className="summary-label">Attendance</div>
        <div className="big-value">{isPresent ? "Present" : "Absent"}</div>
        <div className="summary-tag">{dashboardMeta?.attendanceTag ?? (isPresent ? "On Time" : "Pending")}</div>
      </div>
    </div>
  );
}

function MemberStatusCard() {
  return (
    <div className="card member-card">
      <div className="member-title">Member Status</div>
      <div className="request-list" aria-label="No member status updates yet">
        <div className="request-row">
          <span>Kim Santos</span>
          <span className="requesting">Requesting OT</span>
          <button type="button" className="view-btn">View</button>
        </div>
      </div>
    </div>
  );
}

export default function MainDashboard({
  attendanceControls = null,
  showMemberStatusCard = false,
  schedule = null,
  canEditCards = true,
  dashboardMeta = null,
}) {
  const [timeInStart, setTimeInStart] = useState(null);
  const [now, setNow] = useState(new Date());
  const [isTimeOutConfirmOpen, setIsTimeOutConfirmOpen] = useState(false);

  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  const activeTimeIn = attendanceControls?.timeInAt ?? timeInStart;
  const activeTimeOut = attendanceControls?.timeOutAt ?? null;
  const hasCompletedShift = Boolean(attendanceControls?.hasCompletedShift);
  const hasActiveTimeIn = Boolean(activeTimeIn && !activeTimeOut);
  const canToggleTimeIn = attendanceControls
    ? Boolean(attendanceControls.canClickTimeIn || attendanceControls.canClickTimeOut)
    : true;

  const counterDisplay = useMemo(() => {
    if (!activeTimeIn) return "00:00:00";
    const counterEndTime = activeTimeOut ?? now;
    const diffInSeconds = Math.max(0, Math.floor((counterEndTime.getTime() - activeTimeIn.getTime()) / 1000));
    const hours = String(Math.floor(diffInSeconds / 3600)).padStart(2, "0");
    const minutes = String(Math.floor((diffInSeconds % 3600) / 60)).padStart(2, "0");
    const seconds = String(diffInSeconds % 60).padStart(2, "0");
    return `${hours}:${minutes}:${seconds}`;
  }, [activeTimeIn, activeTimeOut, now]);

  const calendarData = useMemo(() => {
    const currentDate = new Date();
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    const firstDayOfMonth = new Date(year, month, 1);
    const lastDayOfMonth = new Date(year, month + 1, 0);
    const weekDays = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

    const cells = [];
    for (let index = 0; index < firstDayOfMonth.getDay(); index += 1) {
      cells.push({ day: "", muted: true, isToday: false });
    }

    for (let day = 1; day <= lastDayOfMonth.getDate(); day += 1) {
      const isToday =
        day === currentDate.getDate() &&
        month === currentDate.getMonth() &&
        year === currentDate.getFullYear();
      cells.push({ day, muted: false, isToday });
    }

    return {
      monthLabel: currentDate.toLocaleDateString("en-US", { month: "long", year: "numeric" }),
      weekDays,
      cells,
    };
  }, []);


  const totalHours = useMemo(() => {
    if (!activeTimeIn) return 0;
    const counterEndTime = activeTimeOut ?? now;
    const diffInSeconds = Math.max(0, Math.floor((counterEndTime.getTime() - activeTimeIn.getTime()) / 1000));
    return (diffInSeconds / 3600).toFixed(1);
  }, [activeTimeIn, activeTimeOut, now]);

  const executeTimeOut = () => {
    if (attendanceControls) {
      attendanceControls.onTimeOut();
      return;
    }

    setTimeInStart(null);
  };

  const handleConfirmTimeOut = () => {
    executeTimeOut();
    setIsTimeOutConfirmOpen(false);
  };

  const onToggleTimeIn = () => {
    if (attendanceControls) {
      if (attendanceControls.canClickTimeOut) {
        setIsTimeOutConfirmOpen(true);
        return;
      }
      if (attendanceControls.canClickTimeIn) {
        attendanceControls.onTimeIn();
      }
      return;
    }

    if (hasActiveTimeIn) {
      setIsTimeOutConfirmOpen(true);
      return;
    }

    setTimeInStart(new Date());
  };

  return (
    <>
      <DashboardHeader
        headerTime={now.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" })}
        headerDate={now.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" })}
      />

      <div className={`dashboard-grid ${showMemberStatusCard ? "has-member-status" : "no-member-status"}`}>
        <TimeCard
          counterDisplay={counterDisplay}
          hasActiveTimeIn={hasActiveTimeIn}
          onToggleTimeIn={onToggleTimeIn}
          canToggleTimeIn={canToggleTimeIn}
          hasCompletedShift={hasCompletedShift}
        />
        <AnnouncementCard canEdit={canEditCards} />
        <ShiftCard schedule={schedule} dashboardMeta={dashboardMeta} />
        <CalendarCard calendarData={calendarData} />
        <HolidayCard canEdit={canEditCards} />
        <SummaryCard timeInStart={activeTimeIn} totalHours={totalHours} dashboardMeta={dashboardMeta} />
        {showMemberStatusCard ? <MemberStatusCard /> : null}
      </div>

      {isTimeOutConfirmOpen ? (
        <div className="time-out-modal-backdrop" role="presentation">
          <div
            className="time-out-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="time-out-confirm-title"
          >
            <h3 id="time-out-confirm-title">Confirm Time Out</h3>
            <p>Are you sure you want to time out now?</p>
            <div className="time-out-modal-actions">
              <button type="button" className="time-out-cancel-btn" onClick={() => setIsTimeOutConfirmOpen(false)}>
                Cancel
              </button>
              <button type="button" className="time-out-confirm-btn" onClick={handleConfirmTimeOut}>
                Confirm Time Out
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </>
  );
}