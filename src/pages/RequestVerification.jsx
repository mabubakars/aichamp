import { useState } from "react";
import "../styles/auth.css";

const RequestVerification = () => {
  const [message, setMessage] = useState("");

  const handleRequest = (e) => {
    e.preventDefault();
    setMessage("Verification email sent!");
  };

  return (
    <div className="page active">
      <div className="auth-container">
        <div className="auth-card">
          <h1 className="auth-title">Request Email Verification</h1>

          {message && <p style={{ color: "green" }}>{message}</p>}

          <button onClick={handleRequest} className="auth-btn">
            Send Verification Email
          </button>
        </div>
      </div>
    </div>
  );
};

export default RequestVerification;
