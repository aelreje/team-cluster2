import { useEffect, useMemo, useState } from "react";

function DashboardHeader({ headerTime, headerDate }) {
  return (
    <section className="dashboard-header">
      <div className="dashboard-title-wrap">
        <h1>DASHBOARD</h1>
      </div>
      <div className="datetime">{headerTime}&nbsp;&nbsp;&nbsp;{headerDate}</div>
    </section>
  );
}

function TimeCard({
  autoTimeOut,
  setAutoTimeOut,
  timeInputDisplay,
  counterDisplay,
  hasActiveTimeIn,
  onToggleTimeIn,
  canToggleTimeIn,
}) {
  return (
    <div className="card time-card">
      <div className="time-panel">
        <div className="time-panel-row">
          <span className="time-auto-label">AUTO-TIME OUT</span>
          <button
            type="button"
            className={`time-switch ${autoTimeOut ? "on" : ""}`}
            aria-label="Auto time out"
            onClick={() => setAutoTimeOut(prev => !prev)}
          >
            <span className="time-switch-knob" />
          </button>
        </div>

        <div className="time-input">{timeInputDisplay}</div>
        <div className="time-counter">{counterDisplay}</div>

        <button
          type="button"
          className="time-in-btn"
          onClick={onToggleTimeIn}
          disabled={!canToggleTimeIn}
        >
          {hasActiveTimeIn ? "Time Out" : "Time In"}
        </button>
      </div>
    </div>
  );
}

function AnnouncementCard() {
  return (
    <div className="card announcement-card">
      <div className="card-top">
        <span>Announcement</span>
        <button type="button" className="pill-btn">+ Announcement</button>
      </div>
      <ul className="list-items announcement-list" aria-label="No announcements yet" />
      <div className="mini-actions">✎&nbsp;&nbsp;◷</div>
    </div>
  );
}

function ShiftCard() {
  return (
    <div className="card shift-card">
      <div className="shift-columns">
        <div>
          <div className="label">Shift Start Time</div>
          <div className="value">9:00 AM</div>
        </div>
        <div>
          <div className="label">Shift Start End</div>
          <div className="value">6:00 PM</div>
        </div>
      </div>
      <div className="remaining">8 hrs Remaining Time</div>
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

function HolidayCard() {
  return (
    <div className="card holiday-card">
      <div className="card-top">
        <span>Holidays/Birthday</span>
        <span className="plus">+</span>
      </div>
      <ul className="list-items holiday-list" aria-label="No holidays or birthdays yet" />
      <div className="mini-actions">✎&nbsp;&nbsp;◷</div>
    </div>
  );
}

function SummaryCard({ timeInStart, totalHours }) {
  return (
    <div className="card summary-card">
      <div>
        <div className="label">Today Status</div>
        <div className="small-info">Time In: {timeInStart ? timeInStart.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" }) : "--:--"}</div>
        <div className="small-info">Break: Inactive</div>
      </div>
      <div>
        <div className="label">Total Hours</div>
        <div className="big-value">{totalHours}h</div>
      </div>
      <div>
        <div className="label">Attendance</div>
        <div className="big-value">{timeInStart ? "Present" : "Absent"}</div>
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

export default function MainDashboard({ attendanceControls = null }) {
  const [autoTimeOut, setAutoTimeOut] = useState(false);
  const [timeInStart, setTimeInStart] = useState(null);
  const [now, setNow] = useState(new Date());

  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  const activeTimeIn = attendanceControls?.timeInAt ?? timeInStart;
  const activeTimeOut = attendanceControls?.timeOutAt ?? null;
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

  const totalHours = useMemo(() => {
    if (!activeTimeIn) return 0;
    const endTime = activeTimeOut ?? now;
    return Math.floor((endTime.getTime() - activeTimeIn.getTime()) / (1000 * 60 * 60));
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

  const onToggleTimeIn = () => {
    if (attendanceControls) {
      if (attendanceControls.canClickTimeOut) {
        attendanceControls.onTimeOut();
        return;
      }
      if (attendanceControls.canClickTimeIn) {
        attendanceControls.onTimeIn();
      }
      return;
    }

    setTimeInStart(prev => (prev ? null : new Date()));
  };

  return (
    <>
      <DashboardHeader
        headerTime={now.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" })}
        headerDate={now.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" })}
      />

      <div className="dashboard-grid">
        <TimeCard
          autoTimeOut={autoTimeOut}
          setAutoTimeOut={setAutoTimeOut}
          timeInputDisplay={now.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" })}
          counterDisplay={counterDisplay}
          hasActiveTimeIn={hasActiveTimeIn}
          onToggleTimeIn={onToggleTimeIn}
          canToggleTimeIn={canToggleTimeIn}
        />
        <AnnouncementCard />
        <ShiftCard />
        <CalendarCard calendarData={calendarData} />
        <HolidayCard />
        <SummaryCard timeInStart={activeTimeIn} totalHours={totalHours} />
        <MemberStatusCard />
      </div>
    </>
  );
}

export {
  AnnouncementCard,
  CalendarCard,
  DashboardHeader,
  HolidayCard,
  MemberStatusCard,
  ShiftCard,
  SummaryCard,
  TimeCard,
};