import { useMemo, useState } from "react";

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

const formatDateTimeLabel = value => {
  if (!value) return "—";
  const date = new Date(String(value).replace(" ", "T"));
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleString([], {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit"
  });
};

const toDateInputValue = value => {
  if (!value) return null;
  const parsed = new Date(String(value).replace(" ", "T"));
  if (Number.isNaN(parsed.getTime())) return null;
  const year = parsed.getFullYear();
  const month = `${parsed.getMonth() + 1}`.padStart(2, "0");
  const day = `${parsed.getDate()}`.padStart(2, "0");
  return `${year}-${month}-${day}`;
};

export default function DataPanel({
  type = "attendance",
  records = [],
  personField = null,
  personLabel = "Person",
  onEditRow = null,
  externalDateFilter = null,
  onExternalDateFilterChange = null,
}) {
  const config = panelConfig[type] ?? panelConfig.attendance;
  const [searchQuery, setSearchQuery] = useState("");
  const [dateStartFilter, setDateStartFilter] = useState("");
  const [dateEndFilter, setDateEndFilter] = useState("");

  const filteredRecords = useMemo(() => {
    if (type !== "attendance") return [];

    return records.filter(item => {
      const entryDate = toDateInputValue(item.time_in_at ?? item.time_out_at ?? item.updated_at);
      if (dateStartFilter && (!entryDate || entryDate < dateStartFilter)) return false;
      if (dateEndFilter && (!entryDate || entryDate > dateEndFilter)) return false;

      const haystack = [
        item.cluster_name,
        item.attendance_tag,
        item.tag,
        item.attendance_note,
        item.note,
        personField ? item[personField] : "",
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();

      if (searchQuery && !haystack.includes(searchQuery.toLowerCase())) return false;
      return true;
    });
  }, [type, records, dateStartFilter, dateEndFilter, searchQuery, personField]);

  if (type === "attendance") {
    return (
      <div className="employee-attendance-history-table" role="table" aria-label={config.title}>
        <div className="attendance-history-range-filter" role="group" aria-label="Filter attendance history">
          <label className="attendance-history-filter">
            <span>From</span>
            <input type="date" value={dateStartFilter} onChange={event => setDateStartFilter(event.target.value)} />
          </label>
          <label className="attendance-history-filter">
            <span>To</span>
            <input type="date" value={dateEndFilter} onChange={event => setDateEndFilter(event.target.value)} />
          </label>
          {typeof onExternalDateFilterChange === "function" && (
            <label className="attendance-history-filter">
              <span>Date</span>
              <input type="date" value={externalDateFilter ?? ""} onChange={event => onExternalDateFilterChange(event.target.value)} />
            </label>
          )}
          <label className="attendance-history-filter" style={{ minWidth: "260px" }}>
            <span>Search</span>
            <input type="text" value={searchQuery} placeholder={config.searchPlaceholder} onChange={event => setSearchQuery(event.target.value)} />
          </label>
        </div>

        <div className="employee-attendance-history-header" role="row">
          <span role="columnheader">Date</span>
          <span role="columnheader">Time In</span>
          <span role="columnheader">Time Out</span>
          <span role="columnheader">Status</span>
          <span role="columnheader">Cluster</span>
          {personField && <span role="columnheader">{personLabel}</span>}
          <span role="columnheader">Note</span>
          {onEditRow && <span role="columnheader">Action</span>}
        </div>

        {filteredRecords.length > 0 ? filteredRecords.map(item => (
          <div key={`${item.id ?? item.attendance_id}-${item.updated_at ?? item.time_in_at ?? "entry"}`} className="employee-attendance-history-row" role="row">
            <span role="cell">{formatDateTimeLabel(item.time_in_at ?? item.time_out_at ?? item.updated_at)}</span>
            <span role="cell">{formatDateTimeLabel(item.time_in_at)}</span>
            <span role="cell">{formatDateTimeLabel(item.time_out_at)}</span>
            <span role="cell">{item.attendance_tag ?? item.tag ?? "Pending"}</span>
            <span role="cell">{item.cluster_name ?? "—"}</span>
            {personField && <span role="cell">{item[personField] ?? "—"}</span>}
            <span role="cell">{item.attendance_note ?? item.note ?? "—"}</span>
            {onEditRow && (
              <span role="cell">
                <button className="btn" type="button" onClick={() => onEditRow(item)}>Edit</button>
              </span>
            )}
          </div>
        )) : (
          <div className="empty-state">No attendance records match the selected filters.</div>
        )}
      </div>
    );
  }

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
