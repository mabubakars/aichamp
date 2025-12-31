import { createContext, useState, useEffect } from "react";

export const AuthContext = createContext();

export const AuthProvider = ({ children }) => {
  const [token, setToken] = useState(localStorage.getItem("token") || sessionStorage.getItem("token"));

  const login = (newToken, remember = false) => {
    if (remember) localStorage.setItem("token", newToken);
    else sessionStorage.setItem("token", newToken);
    setToken(newToken);
  };

  const logout = () => {
    localStorage.setItem("loggedOut", "true"); 
    localStorage.removeItem("token");
    sessionStorage.removeItem("token");
    localStorage.removeItem("currentSessionId");
    sessionStorage.removeItem("currentSessionId");
    setToken(null);
  };

  useEffect(() => {
    const t = localStorage.getItem("token") || sessionStorage.getItem("token");
    setToken(t);
  }, []);

  useEffect(() => {
    const handleUnauthorized = () => logout();
    window.addEventListener('unauthorized', handleUnauthorized);
    return () => window.removeEventListener('unauthorized', handleUnauthorized);
  }, [logout]);

  return (
    <AuthContext.Provider value={{ token, setToken, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
};
