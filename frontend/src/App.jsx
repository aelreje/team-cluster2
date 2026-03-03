import { useEffect, useState } from "react";
import Login from "./pages/Login";
import Register from "./pages/Register";
import AdminDashboard from "./pages/AdminDashboard";
import CoachDashboard from "./pages/CoachDashboard";
import CoachAttendancePage from "./pages/CoachAttendancePage";
import EmployeeDashboard from "./pages/EmployeeDashboard.jsx";
import EmployeeAttendancePage from "./pages/EmployeeAttendancePage.jsx";

const normalizePath = path => {
  if (!path || path === "/") return "/login";
  return path;
};

const renderRoute = path => {
  switch (path) {
    case "/login":
      return <Login />;
    case "/register":
      return <Register />;
    case "/admin":
      return <AdminDashboard />;
    case "/coach":
      return <CoachDashboard />;
    case "/coach/attendance":
      return <CoachAttendancePage />;
    case "/employee":
      return <EmployeeDashboard />;
    case "/employee/attendance":
      return <EmployeeAttendancePage />;
    default:
      return <Login />;
  }
};

export default function App() {
  const [currentPath, setCurrentPath] = useState(() => normalizePath(window.location.pathname));

  useEffect(() => {
    if (window.location.pathname === "/") {
      window.history.replaceState({}, "", "/login");
    }

    const onPathChange = () => {
      setCurrentPath(normalizePath(window.location.pathname));
    };

    window.addEventListener("popstate", onPathChange);
    return () => window.removeEventListener("popstate", onPathChange);
  }, []);

        return renderRoute(currentPath);
}