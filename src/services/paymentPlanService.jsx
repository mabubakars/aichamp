import { apiClient } from './apiClient';

const getPaymentPlans = async () => {
  try {
    const response = await apiClient.get('billing/plans');
    if (!response.ok) {
      throw new Error(response.data.message || 'Failed to fetch payment plans');
    }
    return response.data;
  } catch (error) {
    console.error('Error fetching payment plans:', error);
    throw error;
  }
};

const getCurrentSubscription = async () => {
  try {
    const response = await apiClient.get('billing/subscription');
    if (!response.ok) {
      throw new Error(response.data.message || 'Failed to fetch current subscription');
    }
    return response.data;
  } catch (error) {
    console.error('Error fetching current subscription:', error);
    throw error;
  }
};

export { getPaymentPlans, getCurrentSubscription };
export default getPaymentPlans;