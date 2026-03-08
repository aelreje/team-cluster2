export default function AuthLayout({
  title,
  description,
  highlights,
  children,
  supportContent,
  showPanel = true,
}) {
  return (
    <div className="auth-page">
      <div className={`auth-shell${showPanel ? "" : " auth-shell-single"}`}>
        {showPanel ? (
          <section className="auth-panel">
            <span className="auth-pill">Team Cluster</span>
            <h1>{title}</h1>
            <p>{description}</p>

            {supportContent ? (
              <div className="auth-support">{supportContent}</div>
            ) : null}

            <div className="auth-highlights">
              {highlights.map(({ label, value }) => (
                <div key={label}>
                  <strong>{label}</strong>
                  <span>{value}</span>
                </div>
              ))}
            </div>
          </section>
        ) : null}

        {children}
      </div>
    </div>
  );
}
