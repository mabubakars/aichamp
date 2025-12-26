import { useState } from "react";
import { Link } from "react-router-dom";
import "../styles/auth.css";

const ResetPassword = () => {
  const [newPass, setNewPass] = useState("");
  const [confirm, setConfirm] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const handleReset = (e) => {
    e.preventDefault();

    if (!newPass || !confirm) {
      return setError("All fields are required");
    }

    if (newPass !== confirm) {
      return setError("Passwords do not match");
    }

    setError("");
    setMessage("Your password has been successfully reset.");
  };

  return (
    <div id="resetPage" className="page active">
      <div className="auth-container">
        <div className="auth-card">
          <div className="auth-header">
            <h1 className="auth-title">Reset Password</h1>
            <p className="auth-subtitle">Enter your new password</p>
          </div>

          <form onSubmit={handleReset}>
            <div className="form-group">
              <label className="form-label">New Password</label>
              <input
                type="password"
                className="form-control"
                placeholder="New password"
                value={newPass}
                onChange={(e) => setNewPass(e.target.value)}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Confirm Password</label>
              <input
                type="password"
                className="form-control"
                placeholder="Confirm new password"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                required
              />
            </div>

            {error && <p style={{ color: "red" }}>{error}</p>}
            {message && <p style={{ color: "green" }}>{message}</p>}

            <button type="submit" className="auth-btn">Reset Password</button>
          </form>

          <div className="auth-footer">
            <p>
              Back to login?{" "}
              <Link to="/login" className="auth-link">Sign in</Link>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ResetPassword;
