import { Routes, Route, useLocation, Navigate } from "react-router-dom";
import { useState, useEffect, useContext } from "react";
import Login from "./pages/Login";
import Signup from "./pages/Signup";
import Dashboard from "./pages/Dashboard";
import AuthGuard from "./guards/AuthGuard";
import Header from "./components/Header";
import Sidebar from "./components/Sidebar";
import ForgotPassword from "./pages/ForgotPassword";
import Profile from "./pages/Profile";
import ChangePassword from "./pages/ChangePassword";
import ResetPassword from "./pages/ResetPassword";
import { AuthProvider, AuthContext } from "./guards/context/AuthContext";
import { ToastContainer } from "react-toastify";
import { sessionService } from "./services/chat/session/SessionService";

const AppContent = () => {
  const location = useLocation();
  const { token } = useContext(AuthContext);

  const noLayoutRoutes = ["/login", "/signup", "/forgot-password", "/reset-password"];
  const hideLayout = noLayoutRoutes.includes(location.pathname);

  const [sessionData, setSessionData] = useState(null);
  const [sessionMessages, setSessionMessages] = useState([]);
  const [sessionModels, setSessionModels] = useState([]);
  const [sessions, setSessions] = useState([]);

  useEffect(() => {
    if (!token) return;

    const loadSessions = async () => {
      const res = await sessionService.getSessions();
      if (res.ok) {
        setSessions(res.data.data.sessions);
      }
    };

    loadSessions();
  }, [token]);

  useEffect(() => {
    if (!token) {
      setSessionData(null);
      setSessionMessages([]);
      setSessionModels([]);
      setSessions([]);
    }
  }, [token]);

  const handleSessionCreated = (newSession) => {
    setSessions((prev) => [newSession, ...prev]);
  };

  return (
    <div style={{ display: "flex", height: "90vh" }}>
      {!hideLayout && token && (
        <Sidebar
          sessions={sessions}
          setSessions={setSessions}
          onSessionChange={(session, messages, models) => {
            setSessionData(session);
            setSessionMessages(messages);
            setSessionModels(models);

            if (!session) {
              localStorage.removeItem("currentSessionId");
            }
          }}
        />
      )}

      <div style={{ flex: 1, display: "flex", flexDirection: "column" }}>
        {!hideLayout && token && <Header />}
        <ToastContainer position="top-right" autoClose={3000} />

        <Routes>
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
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
  );
};

const App = () => (
  <AuthProvider>
    <AppContent />
  </AuthProvider>
);

export default App;
