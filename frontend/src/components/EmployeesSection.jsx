import { useCallback, useEffect, useState } from "react";
import { apiFetch } from "../api/api";
import usePermissions from "../hooks/usePermissions";

const initialForm = {
  first_name: "",
  last_name: "",
  work_email: "",
  position: "",
  account: "",
  employee_type: ""
};

const formatDate = value => {
  if (!value) return "—";
  const parsed = new Date(value);
  if (Number.isNaN(parsed.valueOf())) return value;
  return parsed.toISOString().slice(0, 10);
};

export default function EmployeesSection() {
  const { hasPermission } = usePermissions();
  const canViewEmployeeList = hasPermission("View Employee List");
  const canAddEmployee = hasPermission("Add Employee");

  const [employees, setEmployees] = useState([]);
  const [employeeLoading, setEmployeeLoading] = useState(false);
  const [employeeError, setEmployeeError] = useState("");

  const [isAddEmployeeModalOpen, setIsAddEmployeeModalOpen] = useState(false);
  const [isAddingEmployee, setIsAddingEmployee] = useState(false);
  const [addEmployeeError, setAddEmployeeError] = useState("");
  const [addEmployeeSuccess, setAddEmployeeSuccess] = useState("");
  const [addEmployeeForm, setAddEmployeeForm] = useState(initialForm);

  const fetchEmployees = useCallback(async () => {
    if (!canViewEmployeeList) {
      setEmployees([]);
      setEmployeeLoading(false);
      setEmployeeError("");
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

  const handleSubmitNewEmployee = async event => {
    event.preventDefault();
    if (!canAddEmployee || isAddingEmployee) return;

    setIsAddingEmployee(true);
    setAddEmployeeError("");
    setAddEmployeeSuccess("");

    try {
      await apiFetch("api/admin/employee_management.php", {
        method: "POST",
        body: JSON.stringify(addEmployeeForm)
      });

      setAddEmployeeSuccess("Employee created successfully.");
      setAddEmployeeForm(initialForm);
      if (canViewEmployeeList) {
        await fetchEmployees();
      }
    } catch (error) {
      setAddEmployeeError(error?.message ?? error?.error ?? "Unable to add employee.");
    } finally {
      setIsAddingEmployee(false);
    }
  };

  return (
    <div className="content" aria-label="Employees page">
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
              setAddEmployeeError("");
              setAddEmployeeSuccess("");
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
            </div>
          ))}
        </div>
      )}

      {isAddEmployeeModalOpen ? (
        <div className="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="add-employee-title">
          <div className="modal-card">
            <div className="modal-header">
              <div id="add-employee-title" className="modal-title">Add Employee</div>
              <button className="btn link modal-close-btn" type="button" onClick={() => setIsAddEmployeeModalOpen(false)}>
                Close
              </button>
            </div>
            <form className="modal-body" onSubmit={handleSubmitNewEmployee}>
              <label className="form-field">First Name<input value={addEmployeeForm.first_name} onChange={event => setAddEmployeeForm(curr => ({ ...curr, first_name: event.target.value }))} required /></label>
              <label className="form-field">Last Name<input value={addEmployeeForm.last_name} onChange={event => setAddEmployeeForm(curr => ({ ...curr, last_name: event.target.value }))} required /></label>
              <label className="form-field">Work Email<input type="email" value={addEmployeeForm.work_email} onChange={event => setAddEmployeeForm(curr => ({ ...curr, work_email: event.target.value }))} required /></label>
              <label className="form-field">Position<input value={addEmployeeForm.position} onChange={event => setAddEmployeeForm(curr => ({ ...curr, position: event.target.value }))} /></label>
              <label className="form-field">Account<input value={addEmployeeForm.account} onChange={event => setAddEmployeeForm(curr => ({ ...curr, account: event.target.value }))} /></label>
              <label className="form-field">Employee Type<input value={addEmployeeForm.employee_type} onChange={event => setAddEmployeeForm(curr => ({ ...curr, employee_type: event.target.value }))} /></label>

              {addEmployeeError ? <div className="error">{addEmployeeError}</div> : null}
              {addEmployeeSuccess ? <div className="success">{addEmployeeSuccess}</div> : null}

              <div className="form-actions">
                <button className="btn" type="submit" disabled={isAddingEmployee}>
                  {isAddingEmployee ? "Saving..." : "Save Employee"}
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  );
}
