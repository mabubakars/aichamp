import React, { useState } from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faPlus, faEllipsisV } from "@fortawesome/free-solid-svg-icons";
import "../styles/Sidebar.css";
import { sessionService } from "../services/chat/session/SessionService";
import { useNavigate } from "react-router-dom";
import ConfirmDialog from "../components/Common/ConfirmDialog";

const Sidebar = ({ sessions, setSessions, onSessionChange }) => {
  const [openMenu, setOpenMenu] = useState(null);
  const [currentSessionId, setCurrentSessionId] = useState(
    localStorage.getItem("currentSessionId")
  );

  const [editingSessionId, setEditingSessionId] = useState(null);
  const [editTitle, setEditTitle] = useState("");

  const [deleteTarget, setDeleteTarget] = useState(null);

  const navigate = useNavigate();

  const handleNew = () => {
    localStorage.removeItem("currentSessionId");
    setCurrentSessionId(null);
    setOpenMenu(null);
    onSessionChange(null, [], []);
    navigate("/dashboard");
  };

  const handleActivate = async (session) => {
    if (editingSessionId) return;

    setOpenMenu(null);
    try {
      const res = await sessionService.getSessionById(session.id);
      const modelRes = await sessionService.getSessionModels(session.id);
      const msgRes = await sessionService.getSessionMessages(session.id);

      if (!res.ok || !modelRes.ok) return;

      setCurrentSessionId(session.id);
      localStorage.setItem("currentSessionId", session.id);

      onSessionChange(
        res.data.data.session,
        msgRes?.data?.data?.messages || [],
        modelRes.data.data.models
      );

      navigate("/dashboard");
    } catch (err) {
      console.error(err);
    }
  };

  const startEditing = (session) => {
    setEditingSessionId(session.id);
    setEditTitle(session.title);
    setOpenMenu(null);
  };

  const saveEdit = async (session) => {
    if (!editTitle.trim()) {
      cancelEdit();
      return;
    }

    try {
      await sessionService.activateSession(session.id);
      await sessionService.renameActiveSession(editTitle.trim());

      setSessions((prev) =>
        prev.map((s) =>
          s.id === session.id ? { ...s, title: editTitle.trim() } : s
        )
      );
    } catch (err) {
      console.error(err);
    } finally {
      cancelEdit();
    }
  };

  const cancelEdit = () => {
    setEditingSessionId(null);
    setEditTitle("");
  };

  const handleDeleteConfirm = async () => {
    if (!deleteTarget) return;

    try {
      await sessionService.deleteSession(deleteTarget.id);

      setSessions((prev) =>
        prev.filter((s) => s.id !== deleteTarget.id)
      );

      if (currentSessionId === deleteTarget.id) {
        localStorage.removeItem("currentSessionId");
        setCurrentSessionId(null);
        onSessionChange(null, [], []);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setDeleteTarget(null);
      setOpenMenu(null);
    }
  };

  const formatDateTime = (dateStr) => {
    if (!dateStr) return "";
    const d = new Date(dateStr);
    return d.toLocaleString("en-GB", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  };

  return (
    <>
      <aside className="sessions-panel">
        <div className="sessions-header">
          <h2>Research Sessions</h2>
          <button className="new-session-btn" onClick={handleNew}>
            <FontAwesomeIcon icon={faPlus} /> New
          </button>
        </div>

        <div className="sessions-list">
          {sessions.map((session) => (
            <div
              key={session.id}
              className={`session-item ${
                currentSessionId === session.id ? "active" : ""
              }`}
              onClick={() => handleActivate(session)}
            >
              <div className="session-main">
                {editingSessionId === session.id ? (
                  <input
                    className="session-edit-input"
                    value={editTitle}
                    autoFocus
                    onChange={(e) => setEditTitle(e.target.value)}
                    onBlur={() => saveEdit(session)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") saveEdit(session);
                      if (e.key === "Escape") cancelEdit();
                    }}
                  />
                ) : (
                  <div className="session-info">
                    <div className="session-name">{session.title}</div>
                    <div className="session-date">
                      {formatDateTime(
                        session.last_message_at || session.created_at
                      )}
                    </div>
                  </div>
                )}

                <button
                  className="session-menu-btn"
                  onClick={(e) => {
                    e.stopPropagation();
                    setOpenMenu(openMenu === session.id ? null : session.id);
                  }}
                >
                  <FontAwesomeIcon icon={faEllipsisV} />
                </button>
              </div>

              {openMenu === session.id && (
                <div
                  className="session-menu"
                  onClick={(e) => e.stopPropagation()}
                >
                  <button onClick={() => startEditing(session)}>Edit</button>
                  <button
                    className="delete-btn"
                    onClick={() => setDeleteTarget(session)}
                  >
                    Delete
                  </button>
                </div>
              )}
            </div>
          ))}
        </div>
      </aside>

      <ConfirmDialog
        open={!!deleteTarget}
        title="Delete Session"
        message={`Are you sure you want to delete "${deleteTarget?.title}"? This action cannot be undone.`}
        confirmText="Delete"
        cancelText="Cancel"
        danger
        onConfirm={handleDeleteConfirm}
        onCancel={() => setDeleteTarget(null)}
      />
    </>
  );
};

export default Sidebar;
