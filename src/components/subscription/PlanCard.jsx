import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCheck, faTimes } from "@fortawesome/free-solid-svg-icons";

const PlanCard = ({ plan, onSelect, selected }) => {
  return (
    <div className={`plan-card ${plan.popular ? "popular" : ""} ${selected ? "selected" : ""}`}>
      {plan.popular && <div className="popular-badge">Most Popular</div>}
      {selected && !plan.disabled && <div className="selected-badge">✓ Selected</div>}

      <h3 className="plan-name">{plan.name}</h3>
      <p className="plan-description">{plan.description}</p>

      <div className="plan-price">
        <span className="price-amount">${plan.price}</span>
        <span className="price-period">/ month</span>
      </div>

      <div className="plan-features">
        {plan.features.map((f, i) => (
          <div className="feature-item" key={i}>
            <FontAwesomeIcon
              icon={f.ok ? faCheck : faTimes}
              className="feature-icon"
              style={{ color: f.ok ? "#2f9e44" : "#adb5bd" }}
            />
            <span className="feature-text" style={{ color: f.ok ? "inherit" : "#adb5bd" }}>
              {f.text}
            </span>
          </div>
        ))}
      </div>

      <button
        className={`plan-select-btn ${selected ? "selected" : ""}`}
        disabled={plan.disabled}
        onClick={onSelect}
      >
        {plan.disabled ? "Current Plan" : selected ? "Selected" : "Select Plan"}
      </button>
    </div>
  );
};

export default PlanCard;
