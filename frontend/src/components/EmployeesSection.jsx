import { useCallback, useEffect, useState } from "react";
import { apiFetch } from "../api/api";
import usePermissions from "../hooks/usePermissions";

const initialEmployeeForm = {
  first_name: "",
  middle_name: "",
  last_name: "",
  address: "",
  birthdate: "",
  contact_number: "",
  civil_status: "",
  personal_email: "",
  work_email: "",
  position: "",
  account: "",
  employee_type: "",
  employment_status: "Active",
  date_hired: ""
};

const employmentPositions = [
  "President",
  "HR Lead",
  "Service Delivery Manager",
  "HR Coordinator",
  "IT Administrator",
  "Administrative Support",
  "Accounting",
  "Accounting Associate",
  "Sr. Recruitment Specialist",
  "Jr. Recruitment Specialist",
  "Head of Training",
  "Tier 1 Technical Support",
  "Tier 2 Technical Support",
  "Tier 3 Technical Support",
  "NOC Tier 1 Support",
  "NOC Tier 2 Support",
  "NOC Tier 3 Support",
  "SIP NOC Support Engineer",
  "VOIP Support Technician 1",
  "VOIP Support Technician 2",
  "Help Desk Support 1",
  "Help Desk Support 2",
  "Junior Support Engineer",
  "Software QA Engineer",
  "Project Coordinator",
  "Pre Sales Support",
  "LNP Specialist",
  "Carrier Specialist",
  "Order Manager",
  "Customer Support Representative",
  "Billing Coordinator",
  "PHP Developer",
  "Full Stack Developer",
  "JAVA Developer",
  "Technical Support Engineer",
  "Graphic Designer",
  "Bookkeeper",
  "Technical Trainer",
  "Junior IT Technician"
];

const employmentAccounts = [
  "iReply Back Office Services",
  "In-Telecom Consulting",
  "SIPPIO",
  "Teammate Technology LLC",
  "Viirtue LLC",
  "RingLogix Technologies",
  "RabbitRun",
  "Telco Experts",
  "Crexendo",
  "Advanced Network Solutions",
  "NUSO",
  "Sourcetoad",
  "ATL Communications",
  "Total CX",
  "Element IQ",
  "Telepath",
  "Vitale ENT",
  "Cloud Service Networks",
  "Business VOIP",
  "Rotolo - Bravo 1",
  "Advanced Data Infrastructure",
  "Rotolo - Oxfresh",
  "Level1 - YDC",
  "VoxRush",
  "Clarity Voice",
  "Spectrum VOIP",
  "Rotolo",
  "test client",
  "VoIP CX",
  "VOIP.MS",
  "Rotolo - Rainbow Restoration",
  "UnitedCloud Inc.",
  "Sonicetel",
  "YD Level 1",
  "Palmers Relocations",
  "Atheral",
  "Numhub",
  "Internship",
  "Advanced Network Services",
  "Rotolo (Valet Waste)",
  "Recent Communication",
  "Kevlar IT Solutions",
  "Smart Choice"
];

const employeeTypes = ["Regular", "Probationary", "Contractual", "Intern"];

const formatDate = dateString => {
  if (!dateString) return "—";
  const date = new Date(dateString);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleDateString();
};

const mapEmployeeToForm = employee => ({
  first_name: employee?.first_name ?? "",
  middle_name: employee?.middle_name ?? "",
  last_name: employee?.last_name ?? "",
  address: employee?.address ?? "",
  birthdate: employee?.birthdate ?? "",
  contact_number: employee?.contact_number ?? "",
  civil_status: employee?.civil_status ?? "",
  personal_email: employee?.personal_email ?? "",
  work_email: employee?.email ?? "",
  position: employee?.position ?? "",
  account: employee?.account ?? "",
  employee_type: employee?.employee_type ?? "",
  employment_status: employee?.employment_status || "Active",
  date_hired: employee?.date_hired ?? ""
});

export default function EmployeesSection() {
  const { hasPermission } = usePermissions();
  const canViewEmployeeList = hasPermission("View Employee List");
  const canAddEmployee = hasPermission("Add Employee");
  const canEditEmployee = hasPermission("Edit Employee");
  const canDeleteEmployee = hasPermission("Delete Employee");

  const [employees, setEmployees] = useState([]);
  const [employeeError, setEmployeeError] = useState("");
  const [employeeLoading, setEmployeeLoading] = useState(false);

  const [isAddEmployeeModalOpen, setIsAddEmployeeModalOpen] = useState(false);
  const [isAddingEmployee, setIsAddingEmployee] = useState(false);
  const [addEmployeeError, setAddEmployeeError] = useState("");
  const [addEmployeeActiveTab, setAddEmployeeActiveTab] = useState("personal");
  const [addEmployeeForm, setAddEmployeeForm] = useState(initialEmployeeForm);

  const [editingEmployeeId, setEditingEmployeeId] = useState(null);
  const [isEditEmployeeModalOpen, setIsEditEmployeeModalOpen] = useState(false);
  const [isSavingEditEmployee, setIsSavingEditEmployee] = useState(false);
  const [editEmployeeError, setEditEmployeeError] = useState("");
  const [editEmployeeActiveTab, setEditEmployeeActiveTab] = useState("personal");
  const [editEmployeeForm, setEditEmployeeForm] = useState(initialEmployeeForm);
  const [deletingEmployeeId, setDeletingEmployeeId] = useState(null);

  const fetchEmployees = useCallback(async () => {
    if (!canViewEmployeeList) {
      setEmployees([]);
      setEmployeeError("");
      setEmployeeLoading(false);
      return;
    }

    setEmployeeLoading(true);
    setEmployeeError("");
    try {
      const data = await apiFetch("api/admin/employee_management.php");
      setEmployees(Array.isArray(data) ? data : []);
    } catch (error) {
      setEmployees([]);
      setEmployeeError(error?.error ?? "Unable to load employees.");
    } finally {
      setEmployeeLoading(false);
    }
  }, [canViewEmployeeList]);

  useEffect(() => {
    fetchEmployees();
  }, [fetchEmployees]);

  const handleAddEmployeeChange = event => {
    const { name, value } = event.target;
    setAddEmployeeForm(current => ({ ...current, [name]: value }));
  };

  const handleCloseAddEmployeeModal = () => {
    setIsAddEmployeeModalOpen(false);
    setIsAddingEmployee(false);
    setAddEmployeeError("");
    setAddEmployeeActiveTab("personal");
    setAddEmployeeForm(initialEmployeeForm);
  };

  const handleSubmitAddEmployee = async event => {
    event.preventDefault();
    if (!canAddEmployee || isAddingEmployee) return;

    setIsAddingEmployee(true);
    setAddEmployeeError("");
    try {
      const response = await apiFetch("api/admin/employee_management.php", {
        method: "POST",
        body: JSON.stringify({
          ...addEmployeeForm,
          email: addEmployeeForm.work_email,
          employment_status: addEmployeeForm.employment_status || "Active"
        })
      });

      const generatedEmail = response?.generated_account?.email;
      const generatedPassword = response?.generated_account?.password;
      if (generatedEmail && generatedPassword) {
        window.alert(`Employee created.\nEmail: ${generatedEmail}\nPassword: ${generatedPassword}`);
      }

      handleCloseAddEmployeeModal();
      await fetchEmployees();
    } catch (error) {
      setAddEmployeeError(error?.error ?? error?.message ?? "Unable to add employee.");
    } finally {
      setIsAddingEmployee(false);
    }
  };

  const handleEditEmployeeChange = event => {
    const { name, value } = event.target;
    setEditEmployeeForm(current => ({ ...current, [name]: value }));
  };

  const handleOpenEditEmployeeModal = employee => {
    setEditingEmployeeId(employee.id);
    setEditEmployeeForm(mapEmployeeToForm(employee));
    setEditEmployeeError("");
    setEditEmployeeActiveTab("personal");
    setIsEditEmployeeModalOpen(true);
  };

  const handleCloseEditEmployeeModal = () => {
    setIsEditEmployeeModalOpen(false);
    setEditingEmployeeId(null);
    setIsSavingEditEmployee(false);
    setEditEmployeeError("");
    setEditEmployeeActiveTab("personal");
    setEditEmployeeForm(initialEmployeeForm);
  };

  const handleSubmitEditEmployee = async event => {
    event.preventDefault();
    if (!canEditEmployee || isSavingEditEmployee || !editingEmployeeId) return;

    setIsSavingEditEmployee(true);
    setEditEmployeeError("");
    try {
      await apiFetch("api/admin/employee_management.php", {
        method: "PUT",
        body: JSON.stringify({
          employee_id: editingEmployeeId,
          ...editEmployeeForm,
          email: editEmployeeForm.work_email,
          employment_status: editEmployeeForm.employment_status || "Active"
        })
      });

      handleCloseEditEmployeeModal();
      await fetchEmployees();
    } catch (error) {
      setEditEmployeeError(error?.error ?? error?.message ?? "Unable to update employee.");
    } finally {
      setIsSavingEditEmployee(false);
    }
  };

  const handleDeleteEmployee = async employee => {
    if (!canDeleteEmployee || deletingEmployeeId) return;

    const shouldDelete = window.confirm(`Archive ${employee.fullname || employee.email || "this employee"}?`);
    if (!shouldDelete) return;

    setDeletingEmployeeId(employee.id);
    setEmployeeError("");
    try {
      await apiFetch("api/admin/control_panel/archive_user.php", {
        method: "POST",
        body: JSON.stringify({ employee_id: employee.id })
      });
      await fetchEmployees();
    } catch (error) {
      setEmployeeError(error?.error ?? error?.message ?? "Unable to archive employee.");
    } finally {
      setDeletingEmployeeId(null);
    }
  };

  return (
    <section className="content">
      <div className="employee-list-toolbar">
        <div>
          <div className="section-title">EMPLOYEE LIST</div>
          <div className="employee-list-count">{employees.length} Employees</div>
        </div>
        {canAddEmployee ? (
          <button
            className="btn primary"
            type="button"
            onClick={() => {
              setAddEmployeeActiveTab("personal");
              setAddEmployeeError("");
              setIsAddEmployeeModalOpen(true);
            }}
          >
            + Add Employee
          </button>
        ) : null}
      </div>

      {employeeError && <div className="error">{employeeError}</div>}

      {!canViewEmployeeList ? (
        <div className="empty-state">You do not have permission to view the employee list.</div>
      ) : employeeLoading ? (
        <div className="empty-state">Loading employees...</div>
      ) : employees.length === 0 ? (
        <div className="empty-state">No employees found.</div>
      ) : (
        <div className="table-card">
          <div className="table-header employee-list-header">
            <div>ID</div>
            <div>Name</div>
            <div>Position</div>
            <div>Account</div>
            <div>Type</div>
            <div>Status</div>
            <div>Hired</div>
            <div>Info</div>
            <div className="employee-actions-cell">Actions</div>
          </div>
          {employees.map(employee => (
            <div key={employee.id} className="table-row employee-list-row">
              <div className="table-cell">{employee.id}</div>
              <div className="table-cell">{employee.fullname || "—"}</div>
              <div className="table-cell">{employee.position || "—"}</div>
              <div className="table-cell">{employee.account || "—"}</div>
              <div className="table-cell">{employee.employee_type || "—"}</div>
              <div className="table-cell">{employee.employment_status || "—"}</div>
              <div className="table-cell">{formatDate(employee.date_hired)}</div>
              <div className="table-cell muted">{employee.email || "—"}</div>
              <div className="table-cell employee-actions-cell">
                <div className="employee-actions" role="group" aria-label={`Actions for ${employee.fullname || employee.email || "employee"}`}>
                  {canEditEmployee ? (
                    <button className="btn secondary" type="button" onClick={() => handleOpenEditEmployeeModal(employee)}>
                      Edit
                    </button>
                  ) : null}
                  {canDeleteEmployee ? (
                    <button className="btn danger" type="button" onClick={() => handleDeleteEmployee(employee)} disabled={deletingEmployeeId === employee.id}>
                      {deletingEmployeeId === employee.id ? "Archiving..." : "Delete"}
                    </button>
                  ) : null}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {isAddEmployeeModalOpen && (
        <div className="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="add-employee-title">
          <div className="modal-card add-employee-modal">
            <div className="modal-header">
              <div>
                <div id="add-employee-title" className="modal-title">Add Employee</div>
                <div className="modal-subtitle">Create a new employee profile and generate account credentials.</div>
              </div>
              <button className="btn link modal-close-btn" type="button" onClick={handleCloseAddEmployeeModal}>
                Close
              </button>
            </div>
            <form className="modal-body add-employee-management-form" onSubmit={handleSubmitAddEmployee}>
              <div className="add-employee-tabs" role="tablist" aria-label="Add employee sections">
                <button type="button" role="tab" aria-selected={addEmployeeActiveTab === "personal"} className={`add-employee-tab ${addEmployeeActiveTab === "personal" ? "active" : ""}`} onClick={() => setAddEmployeeActiveTab("personal")}>Personal Information</button>
                <button type="button" role="tab" aria-selected={addEmployeeActiveTab === "employment"} className={`add-employee-tab ${addEmployeeActiveTab === "employment" ? "active" : ""}`} onClick={() => setAddEmployeeActiveTab("employment")}>Employment Details</button>
                <button type="button" role="tab" aria-selected={addEmployeeActiveTab === "benefits"} className={`add-employee-tab ${addEmployeeActiveTab === "benefits" ? "active" : ""}`} onClick={() => setAddEmployeeActiveTab("benefits")}>Benefit Details</button>
              </div>

              {addEmployeeActiveTab === "personal" && (
                <div className="add-employee-tab-panel" role="tabpanel">
                  <div className="add-employee-grid">
                    <label className="form-field" htmlFor="employee-first-name"><input id="employee-first-name" name="first_name" placeholder="First Name" value={addEmployeeForm.first_name} onChange={handleAddEmployeeChange} required /></label>
                    <label className="form-field" htmlFor="employee-middle-name"><input id="employee-middle-name" name="middle_name" placeholder="Middle Name" value={addEmployeeForm.middle_name} onChange={handleAddEmployeeChange} /></label>
                    <label className="form-field add-employee-last-name" htmlFor="employee-last-name"><input id="employee-last-name" name="last_name" placeholder="Last Name" value={addEmployeeForm.last_name} onChange={handleAddEmployeeChange} required /></label>
                    <label className="form-field add-employee-full-width" htmlFor="employee-address"><input id="employee-address" name="address" placeholder="Address" value={addEmployeeForm.address} onChange={handleAddEmployeeChange} /></label>
                    <label className="form-field" htmlFor="employee-birthdate"><input id="employee-birthdate" type="date" name="birthdate" value={addEmployeeForm.birthdate} onChange={handleAddEmployeeChange} /></label>
                    <label className="form-field" htmlFor="employee-contact-number"><input id="employee-contact-number" name="contact_number" placeholder="Contact Number" value={addEmployeeForm.contact_number} onChange={handleAddEmployeeChange} /></label>
                    <label className="form-field" htmlFor="employee-civil-status">
                      <select id="employee-civil-status" name="civil_status" value={addEmployeeForm.civil_status} onChange={handleAddEmployeeChange}>
                        <option value="">Civil Status</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option>
                      </select>
                    </label>
                    <label className="form-field" htmlFor="employee-personal-email"><input id="employee-personal-email" type="email" name="personal_email" placeholder="Personal Email" value={addEmployeeForm.personal_email} onChange={handleAddEmployeeChange} /></label>
                    <label className="form-field" htmlFor="employee-work-email"><input id="employee-work-email" type="email" name="work_email" placeholder="Work Email" value={addEmployeeForm.work_email} onChange={handleAddEmployeeChange} required /></label>
                  </div>
                </div>
              )}

              {addEmployeeActiveTab === "employment" && (
                <div className="add-employee-tab-panel" role="tabpanel">
                  <div className="add-employee-grid">
                    <label className="form-field" htmlFor="employee-position">
                      <select id="employee-position" name="position" value={addEmployeeForm.position} onChange={handleAddEmployeeChange}>
                        <option value="">Select Position</option>
                        {employmentPositions.map(position => (
                          <option key={position} value={position}>{position}</option>
                        ))}
                      </select>
                    </label>
                    <label className="form-field" htmlFor="employee-account">
                      <select id="employee-account" name="account" value={addEmployeeForm.account} onChange={handleAddEmployeeChange}>
                        <option value="">Select Account</option>
                        {employmentAccounts.map(account => (
                          <option key={account} value={account}>{account}</option>
                        ))}
                      </select>
                    </label>
                    <label className="form-field" htmlFor="employee-type">
                      <select id="employee-type" name="employee_type" value={addEmployeeForm.employee_type} onChange={handleAddEmployeeChange}>
                        <option value="">Select Employee Type</option>
                        {employeeTypes.map(employeeType => (
                          <option key={employeeType} value={employeeType}>{employeeType}</option>
                        ))}
                      </select>
                    </label>
                  </div>
                </div>
              )}

              {addEmployeeActiveTab === "benefits" && <div className="add-employee-tab-panel" role="tabpanel"><p className="modal-text">Benefits module coming soon...</p></div>}
              {addEmployeeError && <div className="error add-employee-form-error">{addEmployeeError}</div>}

              <div className="add-employee-footer-actions">
                <button className="btn secondary" type="button" onClick={handleCloseAddEmployeeModal} disabled={isAddingEmployee}>Close</button>
                <button className="btn primary" type="submit" disabled={isAddingEmployee}>{isAddingEmployee ? "Creating..." : "Create"}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {isEditEmployeeModalOpen && (
        <div className="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="edit-employee-title">
          <div className="modal-card add-employee-modal">
            <div className="modal-header">
              <div>
                <div id="edit-employee-title" className="modal-title">Edit Employee</div>
                <div className="modal-subtitle">Update employee profile details.</div>
              </div>
              <button className="btn link modal-close-btn" type="button" onClick={handleCloseEditEmployeeModal}>Close</button>
            </div>
            <form className="modal-body add-employee-management-form" onSubmit={handleSubmitEditEmployee}>
              <div className="add-employee-tabs" role="tablist" aria-label="Edit employee sections">
                <button type="button" role="tab" aria-selected={editEmployeeActiveTab === "personal"} className={`add-employee-tab ${editEmployeeActiveTab === "personal" ? "active" : ""}`} onClick={() => setEditEmployeeActiveTab("personal")}>Personal Information</button>
                <button type="button" role="tab" aria-selected={editEmployeeActiveTab === "employment"} className={`add-employee-tab ${editEmployeeActiveTab === "employment" ? "active" : ""}`} onClick={() => setEditEmployeeActiveTab("employment")}>Employment Details</button>
              </div>

              {editEmployeeActiveTab === "personal" && (
                <div className="add-employee-tab-panel" role="tabpanel">
                  <div className="add-employee-grid">
                    <label className="form-field" htmlFor="edit-employee-first-name"><input id="edit-employee-first-name" name="first_name" placeholder="First Name" value={editEmployeeForm.first_name} onChange={handleEditEmployeeChange} required /></label>
                    <label className="form-field" htmlFor="edit-employee-middle-name"><input id="edit-employee-middle-name" name="middle_name" placeholder="Middle Name" value={editEmployeeForm.middle_name} onChange={handleEditEmployeeChange} /></label>
                    <label className="form-field add-employee-last-name" htmlFor="edit-employee-last-name"><input id="edit-employee-last-name" name="last_name" placeholder="Last Name" value={editEmployeeForm.last_name} onChange={handleEditEmployeeChange} required /></label>
                    <label className="form-field add-employee-full-width" htmlFor="edit-employee-address"><input id="edit-employee-address" name="address" placeholder="Address" value={editEmployeeForm.address} onChange={handleEditEmployeeChange} /></label>
                    <label className="form-field" htmlFor="edit-employee-birthdate"><input id="edit-employee-birthdate" type="date" name="birthdate" value={editEmployeeForm.birthdate} onChange={handleEditEmployeeChange} /></label>
                    <label className="form-field" htmlFor="edit-employee-contact-number"><input id="edit-employee-contact-number" name="contact_number" placeholder="Contact Number" value={editEmployeeForm.contact_number} onChange={handleEditEmployeeChange} /></label>
                    <label className="form-field" htmlFor="edit-employee-civil-status">
                      <select id="edit-employee-civil-status" name="civil_status" value={editEmployeeForm.civil_status} onChange={handleEditEmployeeChange}>
                        <option value="">Civil Status</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option>
                      </select>
                    </label>
                    <label className="form-field" htmlFor="edit-employee-personal-email"><input id="edit-employee-personal-email" type="email" name="personal_email" placeholder="Personal Email" value={editEmployeeForm.personal_email} onChange={handleEditEmployeeChange} /></label>
                    <label className="form-field" htmlFor="edit-employee-work-email"><input id="edit-employee-work-email" type="email" name="work_email" placeholder="Work Email" value={editEmployeeForm.work_email} onChange={handleEditEmployeeChange} required /></label>
                  </div>
                </div>
              )}

              {editEmployeeActiveTab === "employment" && (
                <div className="add-employee-tab-panel" role="tabpanel">
                  <div className="add-employee-grid">
                    <label className="form-field" htmlFor="edit-employee-position">
                      <select id="edit-employee-position" name="position" value={editEmployeeForm.position} onChange={handleEditEmployeeChange}>
                        <option value="">Select Position</option>
                        {employmentPositions.map(position => (
                          <option key={position} value={position}>{position}</option>
                        ))}
                      </select>
                    </label>
                    <label className="form-field" htmlFor="edit-employee-account">
                      <select id="edit-employee-account" name="account" value={editEmployeeForm.account} onChange={handleEditEmployeeChange}>
                        <option value="">Select Account</option>
                        {employmentAccounts.map(account => (
                          <option key={account} value={account}>{account}</option>
                        ))}
                      </select>
                    </label>
                    <label className="form-field" htmlFor="edit-employee-type">
                      <select id="edit-employee-type" name="employee_type" value={editEmployeeForm.employee_type} onChange={handleEditEmployeeChange}>
                        <option value="">Select Employee Type</option>
                        {employeeTypes.map(employeeType => (
                          <option key={employeeType} value={employeeType}>{employeeType}</option>
                        ))}
                      </select>
                    </label>
                  </div>
                </div>
              )}

              {editEmployeeError && <div className="error add-employee-form-error">{editEmployeeError}</div>}

              <div className="add-employee-footer-actions">
                <button className="btn secondary" type="button" onClick={handleCloseEditEmployeeModal} disabled={isSavingEditEmployee}>Close</button>
                <button className="btn primary" type="submit" disabled={isSavingEditEmployee}>{isSavingEditEmployee ? "Saving..." : "Save"}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </section>
  );
}