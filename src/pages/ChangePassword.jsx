import { useState } from "react";
import "../styles/auth.css";
import { useNavigate } from "react-router-dom";
import { authService } from "../services/authService";

const ChangePassword = () => {
  const navigate = useNavigate();

  const [form, setForm] = useState({
    currentPassword: "",
    newPassword: "",
    confirmPassword: "",
  });

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setSuccess("");

    if (form.newPassword !== form.confirmPassword) {
      return setError("New password and confirm password do not match.");
    }

    setLoading(true);

    const payload = {
      current_password: form.currentPassword,
      new_password: form.newPassword,
      confirm_password: form.confirmPassword,
    };

    const res = await authService.changePassword(payload);
    setLoading(false);

    if (!res.ok) {
      return setError(res.data.message || "Failed to update password.");
    }

    setSuccess("Password updated successfully.");
    setForm({ currentPassword: "", newPassword: "", confirmPassword: "" });
  };

  return (
    <div className="page active">
      <div className="auth-container">
        <div className="auth-card">
          <div className="auth-header">
            <h1 className="auth-title">Update Password</h1>
            <p className="auth-subtitle">Change your account password securely</p>
          </div>

          <form onSubmit={handleSubmit} className="auth-form">
            <div className="form-group">
              <label className="form-label">Current Password</label>
              <input
                type="password"
                name="currentPassword"
                className="form-control"
                value={form.currentPassword}
                onChange={handleChange}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">New Password</label>
              <input
                type="password"
                name="newPassword"
                className="form-control"
                value={form.newPassword}
                onChange={handleChange}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Confirm New Password</label>
              <input
                type="password"
                name="confirmPassword"
                className="form-control"
                value={form.confirmPassword}
                onChange={handleChange}
                required
              />
            </div>

            {error && <p className="auth-error">{error}</p>}
            {success && <p className="auth-success">{success}</p>}

            <button type="submit" className="auth-btn" disabled={loading}>
              {loading ? "Updating..." : "Update Password"}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
};

export default ChangePassword;
