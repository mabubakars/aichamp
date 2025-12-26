import { useState } from "react";
import "../styles/auth.css";

const VerifyEmail = () => {
  const [token, setToken] = useState("");
  const [message, setMessage] = useState("");

  const handleVerify = (e) => {
    e.preventDefault();
    setMessage("Email verified successfully!");
  };

  return (
    <div className="page active">
      <div className="auth-container">
        <div className="auth-card">
          <h1 className="auth-title">Verify Email</h1>

          <form onSubmit={handleVerify}>
            <div className="form-group">
              <label className="form-label">Verification Token</label>
              <input
                className="form-control"
                value={token}
                onChange={(e) => setToken(e.target.value)}
              />
            </div>

            {message && <p style={{ color: "green" }}>{message}</p>}

            <button className="auth-btn">Verify Email</button>
          </form>
        </div>
      </div>
    </div>
  );
};

export default VerifyEmail;
