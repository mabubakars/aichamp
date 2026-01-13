import { useState, useEffect } from "react";
import "../styles/UpgradePlan.css";
import PlanCard from "../components/subscription/PlanCard";
import SuccessMessage from "../components/subscription/SuccessMessage";
import { getPaymentPlans, getCurrentSubscription } from "../services/paymentPlanService";
import StripeProvider from "../components/subscription/StripeProvider";
import StripePaymentForm from "../components/subscription/StripePaymentForm";

const UpgradePlan = () => {
  const [selectedPlan, setSelectedPlan] = useState(null);
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [showPayment, setShowPayment] = useState(false);
  const [paymentSuccess, setPaymentSuccess] = useState(null);
  const [currentSubscription, setCurrentSubscription] = useState(null);

  // Define tier hierarchy (lower number = lower tier)
  const tierHierarchy = {
    'free': 0,
    'basic': 1,
    'pro': 2,
    'premium': 3,
    'enterprise': 4
  };

  useEffect(() => {
    const fetchData = async () => {
      try {
        // Fetch current subscription
        const subscriptionResponse = await getCurrentSubscription();
        const currentSub = subscriptionResponse.data;
        setCurrentSubscription(currentSub);

        // Fetch plans
        const plansResponse = await getPaymentPlans();
        const plansData = plansResponse.data.plans.map(plan => ({
          id: plan.id,
          name: plan.name,
          price: plan.price_monthly ? parseFloat(plan.price_monthly) : 0,
          description: plan.description,
          plan_type: plan.plan_type,
          popular: plan.plan_type === 'premium',
          features: [
            { text: `Max sessions: ${plan.features.max_sessions === -1 ? 'Unlimited' : plan.features.max_sessions}`, ok: true },
            { text: `Summarization: ${plan.features.summarization ? 'Yes' : 'No'}`, ok: plan.features.summarization },
            { text: `Vector memory: ${plan.features.vector_memory ? 'Yes' : 'No'}`, ok: plan.features.vector_memory },
            { text: `Thinking traces: ${plan.features.thinking_traces ? 'Yes' : 'No'}`, ok: plan.features.thinking_traces },
            { text: `Priority support: ${plan.features.priority_support ? 'Yes' : 'No'}`, ok: plan.features.priority_support },
            { text: `Advanced analytics: ${plan.features.advanced_analytics ? 'Yes' : 'No'}`, ok: plan.features.advanced_analytics },
            { text: `Custom integrations: ${plan.features.custom_integrations ? 'Yes' : 'No'}`, ok: plan.features.custom_integrations },
            { text: `Max models per prompt: ${plan.features.max_models_per_prompt === -1 ? 'Unlimited' : plan.features.max_models_per_prompt}`, ok: true },
            { text: `Multi-model comparison: ${plan.features.multi_model_comparison ? 'Yes' : 'No'}`, ok: plan.features.multi_model_comparison },
            { text: `Max messages per session: ${plan.features.max_messages_per_session === -1 ? 'Unlimited' : plan.features.max_messages_per_session}`, ok: true },
          ],
        }));

        // Filter plans based on current subscription
        const currentTierLevel = tierHierarchy[currentSub.tier] || 0;
        const filteredPlans = plansData.filter(plan => {
          const planTierLevel = tierHierarchy[plan.plan_type] || 0;
          return planTierLevel > currentTierLevel;
        });

        // Mark current plan as disabled if it's still in the list
        const plansWithDisabled = filteredPlans.map(plan => ({
          ...plan,
          disabled: plan.plan_type === currentSub.tier
        }));

        const sortedPlans = plansWithDisabled.sort((a, b) => {
          if (a.disabled && !b.disabled) return -1;
          if (!a.disabled && b.disabled) return 1;

          const aType = a.popular ? 'premium' : 'basic';
          const bType = b.popular ? 'premium' : 'basic';

          if (aType === 'premium' && bType === 'basic') return -1;
          if (aType === 'basic' && bType === 'premium') return 1;

          return b.price - a.price;
        });

        setPlans(sortedPlans);
      } catch (err) {
        setError(err.message || 'Failed to fetch data');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  return (
    <div className="subscription-page"> {/* ✅ SIDEBAR SAFE */}
      <div className="subscription-container">

        <div className="subscription-header">
          <h1 className="subscription-title">
            {currentSubscription && currentSubscription.tier !== 'free' ? 'Upgrade Your Plan' : 'Choose Your Plan'}
          </h1>
          <p className="subscription-subtitle">
            {currentSubscription && currentSubscription.tier !== 'free'
              ? `You're currently on the ${currentSubscription.tier} plan. Choose a higher-tier plan to unlock more features.`
              : 'Select the subscription plan that best fits your academic research needs.'
            }
          </p>
          {currentSubscription && currentSubscription.tier !== 'free' && (
            <div className="current-plan-info">
              <span className="current-plan-badge">Current Plan: {currentSubscription.tier}</span>
            </div>
          )}
        </div>

        {!showPayment && (
          <div>
            {selectedPlan && (
              <div className="selected-plan-notice">
                <span>Selected: {selectedPlan.name}</span>
                <button
                  className="clear-selection-btn"
                  onClick={() => setSelectedPlan(null)}
                >
                  Change Selection
                </button>
              </div>
            )}
            <div className="plans-grid">
              {loading ? (
                <div className="loading-message">Loading plans...</div>
              ) : error ? (
                <div className="error-message">Error: {error}</div>
              ) : (
                plans.map((plan) => (
                  <PlanCard
                    key={plan.id}
                    plan={plan}
                    selected={selectedPlan?.id === plan.id}
                    onSelect={() => {
                      if (!plan.disabled) {
                        setSelectedPlan(plan);
                        console.log('Plan selected:', plan.name);
                        setTimeout(() => setShowPayment(true), 500);
                      }
                    }}
                  />
                ))
              )}
            </div>
          </div>
        )}

        {showPayment && !paymentSuccess && (
          <StripeProvider>
            <StripePaymentForm
              plan={selectedPlan}
              onBack={() => setShowPayment(false)}
              onSuccess={(data) => setPaymentSuccess(data)}
            />
          </StripeProvider>
        )}

        {paymentSuccess && (
          <SuccessMessage
            plan={paymentSuccess.plan}
            priceInfo={paymentSuccess.priceInfo}
            onContinue={() => {
              setPaymentSuccess(null);
              setShowPayment(false);
              setSelectedPlan(null);
            }}
          />
        )}
      </div>
    </div>
  );
};

export default UpgradePlan;
