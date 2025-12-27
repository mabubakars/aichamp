import { Routes, Route, useLocation, Navigate } from "react-router-dom";
import Login from "./pages/Login";
import Signup from "./pages/Signup";
import Dashboard from "./pages/Dashboard";
import AuthGuard from "./guards/AuthGuard";
import Header from "./components/Header";
import Sidebar from "./components/Sidebar";
import ForgotPassword from "./pages/ForgotPassword";
import { AuthProvider } from "./guards/context/AuthContext";
import { ToastContainer } from "react-toastify";
import Profile from "./pages/Profile";
import ChangePassword from "./pages/ChangePassword";
import ResetPassword from "./pages/ResetPassword";
import { useState,useEffect } from "react";
import { sessionService } from "../src/services/chat/session/SessionService";
const App = () => {
  const location = useLocation();
  const noLayoutRoutes = ["/login", "/signup", "/forgot-password", "/reset-password"];
  const hideLayout = noLayoutRoutes.includes(location.pathname);

  const [sessionData, setSessionData] = useState(null);
  const [sessionMessages, setSessionMessages] = useState([]);
  const [sessionModels, setSessionModels] = useState([]);
  const [sessions, setSessions] = useState([]);

  const handleSessionCreated = (newSession) => {
  setSessions((prev) => [newSession, ...prev]);
  };

  useEffect(() => {
  const loadSessions = async () => {
    const res = await sessionService.getSessions();
    if (res.ok) {
      setSessions(res.data.data.sessions);
    }
  };

  loadSessions();
}, []);

  return (
    <AuthProvider>
      <div style={{ display: "flex", height: "90vh" }}>
        {!hideLayout && (
          <Sidebar
          sessions={sessions}
          setSessions={setSessions}
          onSessionChange={(session, messages, models) => {
            setSessionData(session);
            setSessionMessages(messages);
            setSessionModels(models);

            if (!session) {
              // 🔥 FORCE RESET
              localStorage.removeItem("currentSessionId");
            }
          }}
        />

        )}

        <div style={{ flex: 1, display: "flex", flexDirection: "column" }}>
          {!hideLayout && <Header />}
          <ToastContainer position="top-right" autoClose={3000} />

          <Routes>
            <Route path="/" element={<AuthGuard><Navigate to="/dashboard" replace /></AuthGuard>} />
            <Route path="/login" element={<Login />} />
            <Route path="/signup" element={<Signup />} />
            <Route path="/forgot-password" element={<ForgotPassword />} />
            <Route path="/reset-password" element={<ResetPassword />} />

            <Route
              path="/dashboard"
              element={
                <AuthGuard>
                  <Dashboard
                  sessionData={sessionData}
                  sessionMessages={sessionMessages}
                  sessionModels={sessionModels}
                  onSessionChange={(session, messages, models) => {
                    setSessionData(session);
                    setSessionMessages(messages);
                    setSessionModels(models);

                    if (!session) {
                      localStorage.removeItem("currentSessionId");
                    }
                  }}
                  onSessionCreated={handleSessionCreated}
                />

                </AuthGuard>
              }
            />

            <Route
              path="/profile"
              element={
                <AuthGuard>
                  <Profile />
                </AuthGuard>
              }
            />

            <Route
              path="/change-password"
              element={
                <AuthGuard>
                  <ChangePassword />
                </AuthGuard>
              }
            />
          </Routes>
        </div>
      </div>
    </AuthProvider>
  );
};

export default App;
