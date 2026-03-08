import { useState } from "react";
import { apiFetch } from "../api/api";
import AuthLayout from "../components/AuthLayout";

export default function Login() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setError("");
    setIsSubmitting(true);

    try {
      const data = await apiFetch("auth/login.php", {
        method: "POST",
        body: JSON.stringify({ email, password })
      });

      const normalizedRole = String(data.role || "").toLowerCase();
      if (data.fullname) {
        localStorage.setItem("teamClusterUser", JSON.stringify({
          fullname: data.fullname,
          role: data.role
        }));
      }
      const redirectPath = data.redirect
        || (normalizedRole.includes("admin")
          ? "/admin"
          : normalizedRole.includes("coach")
            ? "/coach"
            : "/employee");

      window.location.href = redirectPath;
    } catch (err) {
      setError(err.error || "Login failed");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <AuthLayout
      showPanel={false}
      title="Welcome back"
      description="Access your dashboard and keep your team aligned in real time."
      highlights={[]}
    >
      <form className="auth-card" onSubmit={handleSubmit}>
        <div>
          <h2 className="auth-heading">Sign in</h2>
          <p className="auth-subtitle">Use your registered email and password.</p>
        </div>

        {error && <p className="auth-error">{error}</p>}

        <label className="auth-field">
          Email address
          <input
            className="auth-input"
            type="email"
            placeholder="you@company.com"
            value={email}
            onChange={e => setEmail(e.target.value)}
            required
          />
        </label>

        <label className="auth-field">
          Password
          <input
            className="auth-input"
            type="password"
            placeholder="Enter your password"
            value={password}
            onChange={e => setPassword(e.target.value)}
            required
          />
        </label>
        <button type="submit" className="btn primary auth-submit" disabled={isSubmitting}>
          {isSubmitting ? "Signing in..." : "Login"}
        </button>

        <p className="auth-footer">
          No account? <a href="/register">Register</a>
        </p>
      </form>
    </AuthLayout>
  );
}
