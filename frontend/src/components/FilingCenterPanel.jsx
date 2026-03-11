import { useMemo, useState } from "react";
import { submitRequest } from "../api/requests";

const filingTabs = [
  { key: "leave", label: "File Leave", icon: "🗓" },
  { key: "overtime", label: "File Overtime", icon: "◷" },
  { key: "dispute", label: "Attendance Dispute", icon: "!" }
];

export default function FilingCenterPanel({ onSubmitted = null }) {
  const [activeTab, setActiveTab] = useState("leave");
  const [disputeType, setDisputeType] = useState("Time Correction");
  const [leaveType, setLeaveType] = useState("Sick Leave");
  const [leaveStartDate, setLeaveStartDate] = useState("");
  const [leaveEndDate, setLeaveEndDate] = useState("");
  const [otType, setOtType] = useState("Regular Overtime");
  const [overtimeDate, setOvertimeDate] = useState("");
  const [overtimeStart, setOvertimeStart] = useState("");
  const [overtimeEnd, setOvertimeEnd] = useState("");
  const [disputeDate, setDisputeDate] = useState("");
  const [reason, setReason] = useState("");
  const [message, setMessage] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const panelTitle = useMemo(() => {
    if (activeTab === "leave") return "New Leave Request";
    if (activeTab === "overtime") return "New Overtime Request";
    return "New Dispute Request";
  }, [activeTab]);

  const resetForm = () => {
    setReason("");
    setLeaveStartDate("");
    setLeaveEndDate("");
    setOvertimeDate("");
    setOvertimeStart("");
    setOvertimeEnd("");
    setDisputeDate("");
  };

  const handleSubmit = async () => {
    if (submitting) return;
    setMessage("");

    try {
      setSubmitting(true);
      if (activeTab === "leave") {
        await submitRequest({
          type: "leave",
          leaveType,
          startDate: leaveStartDate,
          endDate: leaveEndDate,
          reason
        });
      } else if (activeTab === "overtime") {
        await submitRequest({
          type: "overtime",
          otType,
          date: overtimeDate,
          startTime: overtimeStart,
          endTime: overtimeEnd,
          reason
        });
      } else {
        await submitRequest({
          type: "dispute",
          disputeDate,
          disputeType,
          reason
        });
      }

      setMessage("Request submitted successfully.");
      resetForm();
      if (typeof onSubmitted === "function") {
        onSubmitted();
      }
    } catch (error) {
      setMessage(error?.error ?? "Unable to submit request.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="filing-center-layout">
      <div className="filing-center-header">
        <h2>Filing Center</h2>
        <p>Submit your attendance-related requests here.</p>
      </div>

      <div className="filing-center-shell">
        <nav className="filing-center-tabs" aria-label="Filing request types">
          {filingTabs.map(tab => (
            <button
              key={tab.key}
              type="button"
              className={`filing-center-tab${activeTab === tab.key ? " is-active" : ""}`}
              onClick={() => setActiveTab(tab.key)}
            >
              <span className="tab-icon" aria-hidden="true">{tab.icon}</span>
              <span>{tab.label}</span>
            </button>
          ))}
        </nav>

        <section className="filing-center-panel">
          <header className="filing-center-panel-header">{panelTitle}</header>

          <div className="filing-center-panel-body">
            {activeTab === "leave" && (
              <>
                <label className="filing-field filing-field-full">
                  <span>Leave Type</span>
                  <select value={leaveType} onChange={event => setLeaveType(event.target.value)}>
                    <option>Sick Leave</option>
                    <option>Vacation Leave</option>
                    <option>Emergency Leave</option>
                  </select>
                </label>

                <div className="filing-grid-two">
                  <label className="filing-field">
                    <span>Start Date</span>
                    <input type="date" value={leaveStartDate} onChange={event => setLeaveStartDate(event.target.value)} />
                  </label>
                  <label className="filing-field">
                    <span>End Date</span>
                    <input type="date" value={leaveEndDate} onChange={event => setLeaveEndDate(event.target.value)} />
                  </label>
                </div>
              </>
            )}

            {activeTab === "overtime" && (
              <>
                <div className="filing-warning" role="alert">
                  Overtime requests must be filed for a future date. Same-day filing is not permitted to allow for prior approval by your supervisor.
                </div>
                <label className="filing-field filing-field-full">
                  <span>Overtime Type</span>
                  <select value={otType} onChange={event => setOtType(event.target.value)}>
                    <option>Regular Overtime</option>
                    <option>Duty on Rest Day</option>
                    <option>Duty on Rest Day OT</option>
                  </select>
                </label>
                <div className="filing-grid-three">
                  <label className="filing-field">
                    <span>Date</span>
                    <input type="date" value={overtimeDate} onChange={event => setOvertimeDate(event.target.value)} />
                  </label>
                  <label className="filing-field">
                    <span>Start Time</span>
                    <input type="time" value={overtimeStart} onChange={event => setOvertimeStart(event.target.value)} />
                  </label>
                  <label className="filing-field">
                    <span>End Time</span>
                    <input type="time" value={overtimeEnd} onChange={event => setOvertimeEnd(event.target.value)} />
                  </label>
                </div>
              </>
            )}

            {activeTab === "dispute" && (
              <div className="filing-grid-two">
                <label className="filing-field">
                  <span>Dispute Date</span>
                  <input type="date" value={disputeDate} onChange={event => setDisputeDate(event.target.value)} />
                </label>
                <label className="filing-field">
                  <span>Dispute Type</span>
                  <select value={disputeType} onChange={event => setDisputeType(event.target.value)}>
                    <option>Time Correction</option>
                    <option>Status Discrepancy</option>
                    <option>Missing Log</option>
                  </select>
                </label>
              </div>
            )}

            <label className="filing-field filing-field-full">
              <span>Reason / Justification</span>
              <textarea value={reason} onChange={event => setReason(event.target.value)} placeholder="Provide a detailed explanation for your request..." rows={4} />
            </label>

            {message ? <div className="form-hint">{message}</div> : null}
            <button type="button" className="filing-submit-btn" onClick={handleSubmit} disabled={submitting}>
              {submitting ? "Submitting..." : "Submit Request"}
            </button>
          </div>
        </section>
      </div>
    </div>
  );
}