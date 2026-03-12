import { useEffect, useMemo, useState } from "react";
import { apiFetch } from "../api/api";
import "../styles/ControlPanel.css";

export default function ControlPanelSection() {
  const [activeTab, setActiveTab] = useState("General");
  const [roles, setRoles] = useState([]);
  const [users, setUsers] = useState([]);
  const [logs, setLogs] = useState([]);
  const [archivedUsers, setArchivedUsers] = useState([]);
  const [searchTerm, setSearchTerm] = useState("");

  const [selectedRole, setSelectedRole] = useState(null);
  const [tempPermissions, setTempPermissions] = useState([]);

  const [selectedUser, setSelectedUser] = useState(null);
  const [userPermissions, setUserPermissions] = useState([]);

  const loadRoles = async () => {
    const response = await apiFetch("api/control_panel/get_roles_with_permissions.php");
    if (response.success) setRoles(Array.isArray(response.data) ? response.data : []);
  };

  const loadUsers = async () => {
    const response = await apiFetch("api/control_panel/get_users_with_permissions.php");
    if (response.success) setUsers(Array.isArray(response.data) ? response.data : []);
  };

  const loadLogs = async () => {
    const response = await apiFetch("api/control_panel/get_logs.php");
    if (response.success) setLogs(Array.isArray(response.logs) ? response.logs : []);
  };

  const loadArchivedUsers = async () => {
    const response = await apiFetch("api/control_panel/get_archived_users.php");
    if (response.success) setArchivedUsers(Array.isArray(response.users) ? response.users : []);
  };

  useEffect(() => {
    loadRoles();
    loadUsers();
  }, []);

  useEffect(() => {
    if (activeTab === "Logs") loadLogs();
    if (activeTab === "User Archives") loadArchivedUsers();
  }, [activeTab]);

  const filteredUsers = useMemo(
    () =>
      users.filter(user =>
        Object.values(user)
          .join(" ")
          .toLowerCase()
          .includes(searchTerm.toLowerCase())
      ),
    [users, searchTerm]
  );

  const allPermissions = useMemo(
    () => [...new Set(roles.flatMap(role => (Array.isArray(role.permissions) ? role.permissions : [])))],
    [roles]
  );

  const openRoleEditor = role => {
    setSelectedRole(role);
    setTempPermissions(Array.isArray(role.permissions) ? [...role.permissions] : []);
  };

  const toggleRolePermission = permission => {
    setTempPermissions(previous =>
      previous.includes(permission) ? previous.filter(item => item !== permission) : [...previous, permission]
    );
  };

  const saveRolePermissions = async () => {
    if (!selectedRole) return;

    await apiFetch("api/control_panel/update_role_permissions.php", {
      method: "POST",
      body: JSON.stringify({
        role_id: selectedRole.role_id,
        permissions: tempPermissions
      })
    });

    setSelectedRole(null);
    await Promise.all([loadRoles(), loadUsers()]);
  };

  const openUserPermissions = async user => {
    const response = await apiFetch(`api/control_panel/get_user_permissions.php?user_id=${user.id}`);
    if (response.success) {
      setSelectedUser(user);
      setUserPermissions(Array.isArray(response.permissions) ? response.permissions : []);
    }
  };

  const toggleUserPermission = permissionId => {
    setUserPermissions(previous =>
      previous.map(permission =>
        permission.permission_id === permissionId
          ? { ...permission, allowed: permission.allowed === 1 ? 0 : 1 }
          : permission
      )
    );
  };

  const saveUserPermissions = async () => {
    if (!selectedUser) return;

    const response = await apiFetch("api/control_panel/update_user_permissions.php", {
      method: "POST",
      body: JSON.stringify({
        user_id: selectedUser.id,
        permissions: userPermissions
      })
    });

    if (response.success) {
      setSelectedUser(null);
      await loadUsers();
    }
  };

  const restoreUser = async employeeId => {
    await apiFetch("api/control_panel/restore_user.php", {
      method: "POST",
      body: JSON.stringify({ employee_id: employeeId })
    });

    await loadArchivedUsers();
  };

  const deleteUser = async employeeId => {
    const response = await apiFetch(`api/control_panel/delete_user_permanently.php?employee_id=${employeeId}`, {
      method: "POST"
    });

    if (response.success) await loadArchivedUsers();
  };

  return (
    <section className="content control-panel-content">
      <div className="control-panel-container">
        <div className="control-panel-header">
          <h2 className="control-panel-title">Control Panel</h2>
          <p className="control-panel-subtitle">Manage role-based permissions</p>
        </div>

        <div className="control-panel-tabs" role="tablist" aria-label="Control panel tabs">
          {["General", "Search", "Logs", "User Archives"].map(tab => (
            <button
              key={tab}
              type="button"
              role="tab"
              aria-selected={tab === activeTab}
              className={`control-panel-tab ${tab === activeTab ? "active" : ""}`}
              onClick={() => setActiveTab(tab)}
            >
              {tab}
            </button>
          ))}
        </div>

        {activeTab === "General" && (
          <div className="control-panel-grid">
            {roles.map(role => (
              <article key={role.role_id} className="control-panel-card">
                <header className="control-panel-card-header">{role.role_name}</header>
                <div className="control-panel-card-body">
                  <p className="control-panel-permission-title">Permissions:</p>
                  <ul>
                    {(role.permissions ?? []).map(permission => (
                      <li key={`${role.role_id}-${permission}`}>{permission}</li>
                    ))}
                  </ul>
                  <button type="button" className="control-panel-permission-btn" onClick={() => openRoleEditor(role)}>
                    Edit Permissions
                  </button>
                </div>
              </article>
            ))}
          </div>
        )}

        {activeTab === "Search" && (
          <>
            <div className="control-panel-search-bar">
              <input
                type="search"
                placeholder="Search a User..."
                aria-label="Search a User"
                value={searchTerm}
                onChange={event => setSearchTerm(event.target.value)}
              />
            </div>
            <div className="control-panel-table-wrapper">
              <table className="control-panel-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Position</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredUsers.map(user => (
                    <tr key={`search-row-${user.id}`}>
                      <td>{user.id}</td>
                      <td>{user.fullName}</td>
                      <td>{user.role}</td>
                      <td>{user.position}</td>
                      <td>
                        <button
                          type="button"
                          className="control-panel-permission-btn"
                          onClick={() => openUserPermissions(user)}
                        >
                          Permissions
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}

        {activeTab === "Logs" && (
          <div className="control-panel-table-wrapper control-panel-logs-wrapper">
            <table className="control-panel-table control-panel-logs-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>User</th>
                  <th>Action</th>
                  <th>Target</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                {logs.map(log => (
                  <tr key={`log-${log.id}`}>
                    <td>{log.id}</td>
                    <td>{log.user}</td>
                    <td>{log.action}</td>
                    <td>{log.target}</td>
                    <td>{log.date}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {activeTab === "User Archives" && (
          <div className="control-panel-table-wrapper control-panel-archive-wrapper">
            <table className="control-panel-table control-panel-archive-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Full Name</th>
                  <th>Position</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {archivedUsers.map(user => (
                  <tr key={`archive-row-${user.employee_id}`}>
                    <td>{user.employee_id}</td>
                    <td>{user.fullName}</td>
                    <td>{user.position}</td>
                    <td className="control-panel-archive-actions">
                      <button type="button" className="control-panel-restore-btn" onClick={() => restoreUser(user.employee_id)}>
                        Restore
                      </button>
                      <button type="button" className="control-panel-delete-btn" onClick={() => deleteUser(user.employee_id)}>
                        Delete Permanently
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {selectedRole && (
        <div className="control-panel-modal-overlay" role="presentation" onClick={() => setSelectedRole(null)}>
          <div
            className="control-panel-modal"
            role="dialog"
            aria-modal="true"
            aria-label={`Edit ${selectedRole.role_name} permissions`}
            onClick={event => event.stopPropagation()}
          >
            <h3>{selectedRole.role_name}</h3>
            <div className="control-panel-permission-list" role="group" aria-label={`${selectedRole.role_name} permissions`}>
              {allPermissions.map(permission => (
                <label key={`permission-toggle-${selectedRole.role_id}-${permission}`} className="control-panel-permission-item">
                  <input
                    type="checkbox"
                    checked={tempPermissions.includes(permission)}
                    onChange={() => toggleRolePermission(permission)}
                  />
                  <span>{permission}</span>
                </label>
              ))}
            </div>
            <div className="control-panel-modal-actions">
              <button type="button" className="control-panel-cancel-btn" onClick={() => setSelectedRole(null)}>
                Cancel
              </button>
              <button type="button" className="control-panel-apply-btn" onClick={saveRolePermissions}>
                Save
              </button>
            </div>
          </div>
        </div>
      )}

      {selectedUser && (
        <div className="control-panel-modal-overlay" role="presentation" onClick={() => setSelectedUser(null)}>
          <div
            className="control-panel-modal"
            role="dialog"
            aria-modal="true"
            aria-label={`Edit ${selectedUser.fullName} permissions`}
            onClick={event => event.stopPropagation()}
          >
            <h3>{selectedUser.fullName}</h3>
            <div className="control-panel-permission-list" role="group" aria-label={`${selectedUser.fullName} permissions`}>
              {userPermissions.map(permission => (
                <label key={`user-permission-toggle-${permission.permission_id}`} className="control-panel-permission-item">
                  <input
                    type="checkbox"
                    checked={permission.allowed === 1}
                    onChange={() => toggleUserPermission(permission.permission_id)}
                  />
                  <span>{permission.permission_name}</span>
                </label>
              ))}
            </div>
            <div className="control-panel-modal-actions">
              <button type="button" className="control-panel-cancel-btn" onClick={() => setSelectedUser(null)}>
                Cancel
              </button>
              <button type="button" className="control-panel-apply-btn" onClick={saveUserPermissions}>
                Save
              </button>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}