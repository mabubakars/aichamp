import { useState } from "react";
import { Link } from "react-router-dom";
import "../styles/auth.css";

const ForgotPassword = () => {
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const handleForgot = (e) => {
    e.preventDefault();

    if (!email) {
      return setError("Please enter a valid email address");
    }

    setError("");
    setMessage("Password reset link has been sent to your email.");
  };

  return (
    <div id="forgotPage" className="page active">
      <div className="auth-container">
        <div className="auth-card">
          <div className="auth-header">
            <h1 className="auth-title">Forgot Password</h1>
            <p className="auth-subtitle">
              Enter your email to receive a reset link
            </p>
          </div>

          <form onSubmit={handleForgot}>
            <div className="form-group">
              <label className="form-label">Email Address</label>
              <input
                type="email"
                className="form-control"
                placeholder="your.email@university.edu"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>

            {error && <p style={{ color: "red" }}>{error}</p>}
            {message && <p style={{ color: "green" }}>{message}</p>}

            <button type="submit" className="auth-btn">
              Send Reset Link
            </button>
          </form>

          <div className="auth-footer">
            <p>
              Remembered your password?{" "}
              <Link to="/login" className="auth-link">
                Sign in
              </Link>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ForgotPassword;
