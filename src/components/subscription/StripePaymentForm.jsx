import { useState, useMemo } from "react";
import { CardElement, useStripe, useElements } from "@stripe/react-stripe-js";
import { apiClient } from "../../services/apiClient";
import "../../styles/PaymentSection.css";

const DISCOUNT = 0.15;

const StripePaymentForm = ({ plan, onBack, onSuccess }) => {
  const [billing, setBilling] = useState("monthly");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const stripe = useStripe();
  const elements = useElements();

  const priceInfo = useMemo(() => {
    if (billing === "monthly") {
      return { label: "month", total: plan.price };
    }

    const yearly = plan.price * 12;
    return {
      label: "year",
      total: (yearly - yearly * DISCOUNT).toFixed(2),
    };
  }, [billing, plan.price]);

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!stripe || !elements) {
      return;
    }

    setLoading(true);
    setError(null);

    try {
      // Step 1: Create payment method using Stripe Elements
      const cardElement = elements.getElement(CardElement);
      const { error: stripeError, paymentMethod } = await stripe.createPaymentMethod({
        type: 'card',
        card: cardElement,
        billing_details: {
          name: e.target[0].value, // Full name from form
          email: e.target[1].value, // Email from form
        },
      });

      if (stripeError) {
        setError(stripeError.message);
        setLoading(false);
        return;
      }

      // Step 2: Send payment method to backend
      const paymentMethodResponse = await apiClient.post('billing/payment-methods', {
        payment_method_id: paymentMethod.id,
        is_default: true
      });

      if (!paymentMethodResponse.ok) {
        setError(paymentMethodResponse.data?.message || 'Failed to attach payment method');
        setLoading(false);
        return;
      }

      // Step 3: Create payment intent
      const paymentIntentResponse = await apiClient.post('billing/payment-intent', {
        plan_id: plan.id,
        gateway_key: 'stripe'
      });

      if (!paymentIntentResponse.ok) {
        setError(paymentIntentResponse.data?.message || 'Failed to create payment intent');
        setLoading(false);
        return;
      }

      const client_secret = paymentIntentResponse.data?.data?.payment_intent?.client_secret;
      const transaction_id = paymentIntentResponse.data?.data?.transaction_id;

      if (!client_secret) {
        setError('Invalid payment intent response format. Please check the API response.');
        setLoading(false);
        return;
      }

      // Step 4: Confirm payment intent
      const { error: confirmError, paymentIntent } = await stripe.confirmCardPayment(client_secret, {
        payment_method: paymentMethod.id,
      });

      if (confirmError) {
        setError(confirmError.message);
        setLoading(false);
        return;
      }

      // Step 5: Process payment on backend
      const processPaymentResponse = await apiClient.post('billing/process-payment', {
        transaction_id: transaction_id,
        payment_intent_id: paymentIntent.id,
        payment_method_id: paymentMethod.id,
      });

      if (!processPaymentResponse.ok) {
        setError(processPaymentResponse.data?.message || 'Payment processing failed');
        setLoading(false);
        return;
      }

      setLoading(false);
      onSuccess({ plan, priceInfo, paymentMethod, paymentIntent });

    } catch (err) {
      setError(err.message || 'Payment failed');
      setLoading(false);
    }
  };

  return (
    <div className="payment-card">
      <div className="payment-header">
        <h2 className="payment-title">Complete Your Subscription</h2>
        <div className="selected-plan-info">
          <span className="selected-plan-name">{plan.name}</span>
          <span className="selected-plan-price">
            ${priceInfo.total}/{priceInfo.label}
          </span>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <div className="payment-form">
          <div className="form-group">
            <label className="form-label">Full Name</label>
            <input className="form-control" placeholder="Dr. Jane Smith" required />
          </div>

          <div className="form-group">
            <label className="form-label">Email Address</label>
            <input type="email" className="form-control" placeholder="your.email@university.edu" required />
          </div>

          <div className="form-group full-width">
            <label className="form-label">Card Information</label>
            <div className="card-element-container">
              <CardElement
                options={
                  {
                    style: {
                      base: {
                        fontSize: '16px',
                        color: '#424770',
                        '::placeholder': {
                          color: '#aab7c4',
                        },
                      },
                      invalid: {
                        color: '#9e2146',
                      },
                    },
                  }
                }
              />
            </div>
            {error && <div className="card-errors">{error}</div>}
          </div>

          <div className="form-group">
            <label className="form-label">Coupon Code (Optional)</label>
            <input className="form-control" placeholder="SUMMER2024" />
          </div>

          <div className="form-group">
            <label className="form-label">Billing Cycle</label>
            <select
              className="form-control"
              value={billing}
              onChange={(e) => setBilling(e.target.value)}
            >
              <option value="monthly">Monthly Billing</option>
              <option value="yearly">Yearly Billing (Save 15%)</option>
            </select>
          </div>
        </div>

        <div className="form-group full-width">
          <div className="form-check">
            <input type="checkbox" required />
            <label className="form-check-label">
              I agree to the <a href="#">Terms of Service</a>
            </label>
          </div>
        </div>

        <div className="payment-actions">
          <button type="button" className="back-to-plans" onClick={onBack}>
            ← Back to Plans
          </button>
          <button className="submit-payment" disabled={!stripe || loading}>
            {loading ? "Processing..." : "Subscribe Now"}
          </button>
        </div>
      </form>
    </div>
  );
};

export default StripePaymentForm;