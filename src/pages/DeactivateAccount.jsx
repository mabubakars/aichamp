import { useState } from "react";
import "../styles/auth.css";

const DeactivateAccount = () => {
  const [password, setPassword] = useState("");
  const [message, setMessage] = useState("");

  const handleDeactivate = (e) => {
    e.preventDefault();
    setMessage("Your account has been deactivated.");
  };

  return (
    <div className="page active">
      <div className="auth-container">
        <div className="auth-card">
          <h1 className="auth-title">Deactivate Account</h1>
          <p className="auth-subtitle">
            Enter your password to confirm deactivation.
          </p>

          <form onSubmit={handleDeactivate}>
            <div className="form-group">
              <label className="form-label">Password</label>
              <input
                type="password"
                className="form-control"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </div>

            {message && <p style={{ color: "green" }}>{message}</p>}

            <button className="auth-btn" style={{ background: "red" }}>
              Deactivate Account
            </button>
          </form>
        </div>
      </div>
    </div>
  );
};

export default DeactivateAccount;
