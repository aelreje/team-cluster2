import { useEffect, useMemo, useState } from "react";
import { apiFetch } from "../api/api";


function PermissionEditorModal({ title, selectedPermissionIds, permissionOptions, onClose, onSave, isSaving = false, errorMessage = "" }) {
  const [draftPermissionIds, setDraftPermissionIds] = useState(selectedPermissionIds);

  useEffect(() => {
    setDraftPermissionIds(selectedPermissionIds);
  }, [selectedPermissionIds]);

  const togglePermission = permissionId => {
    setDraftPermissionIds(current => {
      if (current.includes(permissionId)) {
        return current.filter(item => item !== permissionId);
      }
      return [...current, permissionId];
    });
  };

  const handleSave = () => onSave(draftPermissionIds);

  return (
    <div className="modal-overlay" role="dialog" aria-modal="true" aria-label={`${title} permission editor`}>
      <div className="modal-card permission-modal">
        <h3 className="permission-modal-title">Edit Permission</h3>
        <p className="modal-subtitle">{title}</p>

        <div className="permission-modal-list" role="group" aria-label="Permission options">
          {permissionOptions.map(permission => (
            <label key={permission.id} className="permission-modal-item">
              <input
                type="checkbox"
                checked={draftPermissionIds.includes(permission.id)}
                onChange={() => togglePermission(permission.id)}
              />
              <span>{permission.name}</span>
            </label>
          ))}
        </div>

        <div className="permission-modal-actions">
          <button className="btn secondary" type="button" onClick={onClose} disabled={isSaving}>Cancel</button>
          <button className="btn permission-save-btn" type="button" onClick={handleSave} disabled={isSaving}>
            {isSaving ? "Saving..." : "Save"}
          </button>
        </div>

        {errorMessage ? <p className="team-empty-note">{errorMessage}</p> : null}
      </div>
    </div>
  );
}

export default function ControlPanelSection() {
  const [activeTab, setActiveTab] = useState("role");
  const [searchTerm, setSearchTerm] = useState("");
  const [permissionOptions, setPermissionOptions] = useState([]);
  const [rolePermissions, setRolePermissions] = useState([]);
  const [userPermissions, setUserPermissions] = useState([]);
  const [editingRoleId, setEditingRoleId] = useState("");
  const [editingUserId, setEditingUserId] = useState("");
  const [loadingRolePermissions, setLoadingRolePermissions] = useState(true);
  const [savingUserPermissions, setSavingUserPermissions] = useState(false);
  const [userSaveError, setUserSaveError] = useState("");

  useEffect(() => {
    let mounted = true;

    const loadControlPanelPermissions = async () => {
      try {
        const response = await apiFetch("api/admin/control_panel_permissions.php");
        if (!mounted) return;

        const options = Array.isArray(response.permissionOptions) ? response.permissionOptions : [];
        const roles = Array.isArray(response.rolePermissions) ? response.rolePermissions : [];
        const users = Array.isArray(response.userPermissions) ? response.userPermissions : [];
        setPermissionOptions(options.map(item => ({ id: item.id, name: item.name })));
        setRolePermissions(roles.map(role => ({
          id: String(role.id),
          roleId: role.id,
          role: role.role,
          description: role.description,
          permissionIds: Array.isArray(role.permissionIds) ? role.permissionIds : [],
          permissions: Array.isArray(role.permissions) ? role.permissions : []
        })));
        setUserPermissions(users.map(user => ({
          id: user.id,
          userId: user.userId,
          name: user.name,
          role: user.role,
          email: user.email,
          permissions: Array.isArray(user.permissions) ? user.permissions : []
        })));
      } catch {
        if (!mounted) return;
        setPermissionOptions([]);
        setRolePermissions([]);
        setUserPermissions([]);
      } finally {
        if (mounted) setLoadingRolePermissions(false);
      }
    };

    loadControlPanelPermissions();

    return () => {
      mounted = false;
    };
  }, []);

  const filteredRoles = useMemo(() => {
    const value = searchTerm.trim().toLowerCase();
    if (!value) return rolePermissions;

    return rolePermissions.filter(item => {
      return item.role.toLowerCase().includes(value)
        || item.description.toLowerCase().includes(value)
        || item.permissions.some(permission => permission.toLowerCase().includes(value));
    });
  }, [rolePermissions, searchTerm]);

  const filteredUsers = useMemo(() => {
    const value = searchTerm.trim().toLowerCase();
    if (!value) return userPermissions;

    return userPermissions.filter(item => {
      return item.name.toLowerCase().includes(value)
        || item.role.toLowerCase().includes(value)
        || item.email.toLowerCase().includes(value)
        || item.permissions.some(permission => permission.toLowerCase().includes(value));
    });
  }, [userPermissions, searchTerm]);

  const editingRole = rolePermissions.find(item => item.id === editingRoleId);
  const editingUser = userPermissions.find(item => item.id === editingUserId);

  const handleSaveRolePermissions = async permissionIds => {
    const role = rolePermissions.find(item => item.id === editingRoleId);
    if (!role) return;

    try {
      const response = await apiFetch("api/admin/control_panel_permissions.php", {
        method: "POST",
        body: JSON.stringify({
          role_id: role.roleId,
          permission_ids: permissionIds
        })
      });

      const roles = Array.isArray(response.rolePermissions) ? response.rolePermissions : [];
      setRolePermissions(roles.map(item => ({
        id: String(item.id),
        roleId: item.id,
        role: item.role,
        description: item.description,
        permissionIds: Array.isArray(item.permissionIds) ? item.permissionIds : [],
        permissions: Array.isArray(item.permissions) ? item.permissions : []
      })));
    } finally {
      setEditingRoleId("");
    }
  };

  const handleSaveUserPermissions = async permissionIds => {
    const user = userPermissions.find(item => item.id === editingUserId);
    if (!user) return;

    setSavingUserPermissions(true);
    setUserSaveError("");

    try {
      const response = await apiFetch("api/admin/control_panel_user_permissions.php", {
        method: "POST",
        body: JSON.stringify({
          user_id: user.userId,
          permission_ids: permissionIds
        })
      });

      const users = Array.isArray(response.userPermissions) ? response.userPermissions : [];
      setUserPermissions(users.map(item => ({
        id: item.id,
        userId: item.userId,
        name: item.name,
        role: item.role,
        email: item.email,
        permissions: Array.isArray(item.permissions) ? item.permissions : []
      })));
      setEditingUserId("");
      } catch (error) {
      setUserSaveError(error?.error ?? "Unable to save user permissions.");
    } finally {
      setSavingUserPermissions(false);
    }
  };

  const showRolePermissions = activeTab === "role";
  const showIndividualAccess = activeTab === "individual";
  const showLogs = activeTab === "logs";
  const showUserArchives = activeTab === "userArchives";

  return (
    <section className="control-panel-content" aria-label="Control panel permission editor">
      <header className="control-panel-header">
        <h2>Control Panel</h2>
        <p>Manage access rights by role or assign custom permissions to a specific user.</p>
      </header>

      <div className="control-panel-tabs" role="tablist" aria-label="Permission view mode">
        <button
          className={`control-panel-tab${showRolePermissions ? " active" : ""}`}
          type="button"
          role="tab"
          aria-selected={showRolePermissions}
          onClick={() => setActiveTab("role")}
        >
          By Role
        </button>
        <button
          className={`control-panel-tab${showIndividualAccess ? " active" : ""}`}
          type="button"
          role="tab"
          aria-selected={showIndividualAccess}
          onClick={() => setActiveTab("individual")}
        >
          Individual Access
        </button>
        <button
          className={`control-panel-tab${showLogs ? " active" : ""}`}
          type="button"
          role="tab"
          aria-selected={showLogs}
          onClick={() => setActiveTab("logs")}
        >
          Logs
        </button>
        <button
          className={`control-panel-tab${showUserArchives ? " active" : ""}`}
          type="button"
          role="tab"
          aria-selected={showUserArchives}
          onClick={() => setActiveTab("userArchives")}
        >
          User Archives
        </button>
      </div>

      <input
        className="control-panel-search"
        type="search"
        value={searchTerm}
        onChange={event => setSearchTerm(event.target.value)}
        placeholder={showRolePermissions
          ? "Search role or permission..."
          : "Search user, role, or permission..."}
      />

      {showRolePermissions ? (
        loadingRolePermissions ? (
          <p className="team-empty-note">Loading role permissions...</p>
        ) : (
          <div className="permission-card-grid">
            {filteredRoles.map(roleItem => (
              <article key={roleItem.id} className="permission-card">
                <div className="permission-card-header">{roleItem.role}</div>
                <div className="permission-card-body">
                  <p className="permission-card-label">{roleItem.description}</p>
                  <ul>
                    {roleItem.permissions.map(permission => (
                      <li key={`${roleItem.id}-${permission}`}>{permission}</li>
                    ))}
                  </ul>
                  <button
                    className="btn permission-edit-btn"
                    type="button"
                    onClick={() => setEditingRoleId(roleItem.id)}
                  >
                    Edit Permission
                  </button>
                </div>
              </article>
            ))}
          </div>
        )
      ) : showIndividualAccess ? (
        <div className="control-panel-table-wrap" role="table" aria-label="Individual permission table">
          <div className="control-panel-table-header" role="row">
            <span role="columnheader">ID</span>
            <span role="columnheader">User</span>
            <span role="columnheader">Role</span>
            <span role="columnheader">Permissions</span>
            <span role="columnheader">Action</span>
          </div>

          {filteredUsers.map(userItem => (
            <div key={userItem.id} className="control-panel-table-row" role="row">
              <span role="cell">{userItem.id}</span>
              <span role="cell">{userItem.name}</span>
              <span role="cell">{userItem.role}</span>
              <span role="cell">{userItem.permissions.length}</span>
              <span role="cell">
                <button
                  className="btn permission-edit-btn"
                  type="button"
                  onClick={() => {
                    setUserSaveError("");
                    setEditingUserId(userItem.id);
                  }}
                >
                  Edit Permission
                </button>
              </span>
            </div>
          ))}
        </div>
        ) : showLogs ? (
        <p className="team-empty-note">Logs tab is ready for activity history content.</p>
      ) : (
        <p className="team-empty-note">User Archives tab is ready for archived user records.</p>
      )}

      {editingRole ? (
        <PermissionEditorModal
          title={`${editingRole.role} Role`}
          selectedPermissionIds={editingRole.permissionIds}
          permissionOptions={permissionOptions}
          onClose={() => setEditingRoleId("")}
          onSave={handleSaveRolePermissions}
        />
      ) : null}

      {editingUser ? (
        <PermissionEditorModal
          title={`${editingUser.name} (${editingUser.role})`}
          selectedPermissionIds={editingUser.permissions
            .map(name => permissionOptions.find(option => option.name === name)?.id)
            .filter(Boolean)}
          permissionOptions={permissionOptions}
          onClose={() => setEditingUserId("")}
          onSave={handleSaveUserPermissions}
          isSaving={savingUserPermissions}
          errorMessage={userSaveError}
        />
      ) : null}
    </section>
  );
}