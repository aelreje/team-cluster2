import { useMemo, useState } from "react";

const filingTabs = [
  { key: "leave", label: "File Leave", icon: "🗓" },
  { key: "overtime", label: "File Overtime", icon: "◷" },
  { key: "dispute", label: "Attendance Dispute", icon: "!" }
];

export default function FilingCenterPanel() {
  const [activeTab, setActiveTab] = useState("leave");
  const [disputeType, setDisputeType] = useState("Time Correction");

  const panelTitle = useMemo(() => {
    if (activeTab === "leave") return "New Leave Request";
    if (activeTab === "overtime") return "New Overtime Request";
    return "New Dispute Request";
  }, [activeTab]);

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
                  <select defaultValue="Sick Leave">
                    <option>Sick Leave</option>
                    <option>Vacation Leave</option>
                    <option>Emergency Leave</option>
                  </select>
                </label>

                <div className="filing-grid-two">
                  <label className="filing-field">
                    <span>Start Date</span>
                    <input type="text" placeholder="mm/dd/yyyy" />
                  </label>
                  <label className="filing-field">
                    <span>End Date</span>
                    <input type="text" placeholder="mm/dd/yyyy" />
                  </label>
                </div>
              </>
            )}

            {activeTab === "overtime" && (
              <>
                <div className="filing-warning" role="alert">
                  Overtime requests must be filed for a future date. Same-day filing is not permitted to allow for prior approval by your supervisor.
                </div>
                <div className="filing-grid-three">
                  <label className="filing-field">
                    <span>Date</span>
                    <input type="text" placeholder="mm/dd/yyyy" />
                  </label>
                  <label className="filing-field">
                    <span>Start Time</span>
                    <input type="text" placeholder="--:-- --" />
                  </label>
                  <label className="filing-field">
                    <span>End Time</span>
                    <input type="text" placeholder="--:-- --" />
                  </label>
                </div>
              </>
            )}

            {activeTab === "dispute" && (
              <div className="filing-grid-two">
                <label className="filing-field">
                  <span>Dispute Date</span>
                  <input type="text" placeholder="mm/dd/yyyy" />
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
              <textarea placeholder="Provide a detailed explanation for your request..." rows={4} />
            </label>

            <button type="button" className="filing-submit-btn">Submit Request</button>
          </div>
        </section>
      </div>
    </div>
  );
}
