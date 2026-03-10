const panelConfig = {
  attendance: {
    title: "My Attendance Logs",
    filterLabel: "Filter Dates",
    searchPlaceholder: "Search...",
    columns: ["Date", "Time In", "Time Out", "Break In", "Break Out", "Total Hours", "Status", "Actions"],
    messageTitle: "Server Connection Lost",
    messageSubtitle: "Attendance logs cannot be retrieved at this moment."
  },
  requests: {
    title: "My Requests",
    filterLabel: "Filter Type",
    searchPlaceholder: "Search requests, reasons, status...",
    columns: ["Date Filed", "Request Type", "Details", "Schedule / Period", "Status", "Actions"],
    messageTitle: "Unable to load requests",
    messageSubtitle: "Please try again in a few moments."
  }
};

export default function DataPanel({ type = "attendance" }) {
  const config = panelConfig[type] ?? panelConfig.attendance;

  return (
    <div className="offline-data-panel" role="region" aria-label={`${config.title} offline view`}>
      <div className="offline-data-panel-header">
        <div className="offline-data-panel-title-wrap">
          <h3 className="offline-data-panel-title">{config.title}</h3>
          <span className="offline-pill">OFFLINE</span>
        </div>
        <div className="offline-data-panel-controls">
          <button type="button" className="offline-control-btn" disabled>{config.filterLabel}</button>
          <div className="offline-search-input" aria-hidden="true">{config.searchPlaceholder}</div>
        </div>
      </div>

      <div className={`offline-data-table offline-data-table-${type}`} role="table" aria-label={config.title}>
        <div className="offline-data-table-header" role="row">
          {config.columns.map(column => (
            <span key={column} role="columnheader">{column}</span>
          ))}
        </div>
        <div className="offline-data-table-empty" role="row">
          <div className="offline-data-empty-icon" aria-hidden="true">☰</div>
          <p className="offline-data-empty-title">{config.messageTitle}</p>
          <p className="offline-data-empty-subtitle">{config.messageSubtitle}</p>
        </div>
      </div>
    </div>
  );
}
