// src/guards/AuthGuard.js
import { Navigate } from "react-router-dom";
import { useContext } from "react";
import { AuthContext } from "../guards/context/AuthContext";

const AuthGuard = ({ children }) => {
  const { token, logout } = useContext(AuthContext);

  if (!token) return <Navigate to="/login" replace />;

  try {
    const payload = JSON.parse(atob(token.split(".")[1]));
    const now = Math.floor(Date.now() / 1000);
    if (payload.exp && payload.exp < now) {
      logout(); // clears token and triggers redirect
      return <Navigate to="/login" replace />;
    }
  } catch {
    logout();
    return <Navigate to="/login" replace />;
  }

  return children;
};

export default AuthGuard;
