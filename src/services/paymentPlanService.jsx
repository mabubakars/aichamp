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

export default getPaymentPlans;