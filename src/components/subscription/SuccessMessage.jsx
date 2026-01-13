const SuccessMessage = ({ plan, priceInfo, onContinue }) => {
  return (
    <div className="success-message active">
      <div className="success-icon">✓</div>
      <h2 className="success-title">Subscription Successful! 🎉</h2>
      <p className="success-details">
        You are now subscribed to <strong>{plan.name}</strong> plan.
        Your subscription of <strong>${priceInfo.total}/{priceInfo.label}</strong> is now active.
      </p>
      <div className="success-actions">
        <button className="btn btn-primary" onClick={onContinue}>
          Continue to Dashboard
        </button>
      </div>
    </div>
  );
};

export default SuccessMessage;
