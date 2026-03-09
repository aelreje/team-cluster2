import { useCallback, useEffect, useState } from "react";
import { apiFetch } from "../api/api";
import DashboardSidebar from "../components/DashboardSidebar";
import MainDashboard from "./MainDashboard";
import useLiveDateTime from "../hooks/useLiveDateTime";
import useCurrentUser from "../hooks/useCurrentUser";

export default function AdminDashboard() {
  const [clusters, setClusters] = useState([]);
  const [rejectingCluster, setRejectingCluster] = useState(null);
  const [activeNav, setActiveNav] = useState("Team");
  const [rejectionReason, setRejectionReason] = useState("");
  const [rejectError, setRejectError] = useState("");
  const [isSubmittingReject, setIsSubmittingReject] = useState(false);
  const [coachAttendance, setCoachAttendance] = useState([]);
  const [attendanceDate, setAttendanceDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [editingCoachAttendance, setEditingCoachAttendance] = useState(null);
  const [editForm, setEditForm] = useState({ timeInAt: "", timeOutAt: "", tag: "", note: "" });
  const dateTimeLabel = useLiveDateTime();
  const { user } = useCurrentUser();
  const navItems = [
    { label: "Dashboard", active: activeNav === "Dashboard", onClick: () => setActiveNav("Dashboard") },
    { label: "Team", active: activeNav === "Team", onClick: () => setActiveNav("Team") },
    { label: "Attendance", active: activeNav === "Attendance", onClick: () => setActiveNav("Attendance") },
    { label: "Schedule" }
  ];

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
        ) : (
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