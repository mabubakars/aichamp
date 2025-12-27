<?php
function registerRoutes($router) {
    // Auth routes
    $router->addRoute('POST', '/signup', 'Auth@signup');
    $router->addRoute('POST', '/login', 'Auth@login');
    $router->addRoute('GET', '/verify', 'Auth@verify');
    $router->addRoute('POST', '/refresh', 'Auth@refresh');
    $router->addRoute('POST', '/logout', 'Auth@logout');
    $router->addRoute('POST', '/forgot-password', 'Auth@forgotPassword');
    $router->addRoute('POST', '/reset-password', 'Auth@resetPassword');
    $router->addRoute('POST', '/change-password', 'Auth@changePassword');
    $router->addRoute('POST', '/request-verification', 'Auth@requestEmailVerification');
    $router->addRoute('POST', '/verify-email', 'Auth@verifyEmail');

    // User profile routes (protected)
    $router->addRoute('GET', '/profile', 'User@getProfile');
    $router->addRoute('PUT', '/profile', 'User@updateProfile');

    // Admin routes (protected by admin middleware)
    $router->addRoute('GET', '/admin/users', 'User@getAllUsers');
    $router->addRoute('GET', '/admin/users/search', 'User@searchUsers');
    $router->addRoute('GET', '/admin/users/details', 'User@getUserById');
    $router->addRoute('POST', '/admin/users/activate', 'User@activateUser');

    // AI/OpenAI compatible routes
    $router->addRoute('GET', '/v1/models', 'AI@getModels');

    // AI Model management routes (admin)
    $router->addRoute('GET', '/admin/models', 'AIModel@getModels');
    $router->addRoute('GET', '/admin/models/{id}', 'AIModel@getModel');
    $router->addRoute('POST', '/admin/models', 'AIModel@createModel');
    $router->addRoute('PUT', '/admin/models/{id}', 'AIModel@updateModel');
    $router->addRoute('DELETE', '/admin/models/{id}', 'AIModel@deleteModel');
    $router->addRoute('GET', '/admin/models/{id}/analytics', 'AIModel@getModelAnalytics');

    // Session model configuration routes
    $router->addRoute('GET', '/sessions/{sessionId}/models-config', 'AIModel@getSessionModels');
    $router->addRoute('PUT', '/sessions/{sessionId}/models-config', 'AIModel@updateSessionModels');

    // Session management routes
    $router->addRoute('POST', '/sessions', 'Session@createSession');
    $router->addRoute('GET', '/sessions/active', 'Session@getActiveSession');
    $router->addRoute('GET', '/sessions', 'Session@listSessions');
    $router->addRoute('GET', '/sessions/{sessionId}', 'Session@getSession');
    $router->addRoute('PUT', '/sessions/active', 'Session@renameActiveSession');
    $router->addRoute('PUT', '/sessions/{sessionId}/activate', 'Session@activateSession');
    $router->addRoute('DELETE', '/sessions/{sessionId}', 'Session@deleteSession');

    // Session model management routes
    $router->addRoute('POST', '/sessions/{sessionId}/models', 'Session@addModel');
    $router->addRoute('PUT', '/sessions/{sessionId}/models/{modelId}', 'Session@updateModel');
    $router->addRoute('PATCH', '/sessions/{sessionId}/models/{modelId}/toggle', 'Session@toggleModel');
    $router->addRoute('DELETE', '/sessions/{sessionId}/models/{modelId}', 'Session@removeModel');
    $router->addRoute('GET', '/sessions/{sessionId}/models', 'Session@listSessionModels');

    // Session messages route
    $router->addRoute('GET', '/sessions/{sessionId}/messages', 'Session@getSessionMessages');

    // Individual model chat endpoints for concurrent multi-model support
    $router->addRoute('POST', '/sessions/{sessionId}/models/{modelId}/chat', 'Session@chatWithModel');
    $router->addRoute('PATCH', '/sessions/{sessionId}/models/{modelId}/visibility', 'Session@toggleModelVisibility');

    // Billing routes (protected)
    $router->addRoute('GET', '/billing/subscription', 'Billing@getCurrentSubscription');
    $router->addRoute('GET', '/billing/plans', 'Billing@getSubscriptionPlans');
    $router->addRoute('POST', '/billing/upgrade', 'Billing@upgradeSubscription');
    $router->addRoute('POST', '/billing/cancel', 'Billing@cancelSubscription');
    $router->addRoute('POST', '/billing/payment-intent', 'Billing@createPaymentIntent');
    $router->addRoute('POST', '/billing/process-payment', 'Billing@processPayment');
    $router->addRoute('GET', '/billing/invoices', 'Billing@getInvoices');
    $router->addRoute('GET', '/billing/invoices/{invoiceId}', 'Billing@getInvoice');
    $router->addRoute('GET', '/billing/payment-methods', 'Billing@getPaymentMethods');
    $router->addRoute('POST', '/billing/payment-methods', 'Billing@addPaymentMethod');
    $router->addRoute('DELETE', '/billing/payment-methods/{methodId}', 'Billing@removePaymentMethod');
    $router->addRoute('GET', '/billing/history', 'Billing@getBillingHistory');
    $router->addRoute('GET', '/billing/usage', 'Billing@getSubscriptionUsage');

    // Admin billing routes
    $router->addRoute('GET', '/admin/billing/subscriptions', 'Billing@getAllSubscriptions');
    $router->addRoute('GET', '/admin/billing/stats', 'Billing@getBillingStats');
    
    // Admin user management routes
    $router->addRoute('GET', '/admin/users', 'Admin@getUsers');
    $router->addRoute('GET', '/admin/users/{userId}', 'Admin@getUser');
    $router->addRoute('PUT', '/admin/users/{userId}', 'Admin@updateUser');
    $router->addRoute('POST', '/admin/users/{userId}/activate', 'Admin@activateUser');
    $router->addRoute('POST', '/admin/users/{userId}/deactivate', 'Admin@deactivateUser');
    $router->addRoute('POST', '/admin/users/{userId}/promote', 'Admin@promoteToAdmin');
    $router->addRoute('POST', '/admin/users/{userId}/demote', 'Admin@demoteFromAdmin');
    $router->addRoute('GET', '/admin/users/search', 'Admin@searchUsers');
    
    // Admin system monitoring routes
    $router->addRoute('GET', '/admin/system/health', 'Admin@getSystemHealth');
    $router->addRoute('GET', '/admin/system/database', 'Admin@getDatabaseStats');
    $router->addRoute('GET', '/admin/system/metrics', 'Admin@getPerformanceMetrics');
    $router->addRoute('GET', '/admin/system/metrics/comprehensive', 'Admin@getSystemMetrics');
    
    // Admin audit log routes
    $router->addRoute('GET', '/admin/audit/logs', 'Admin@getAuditLogs');
    $router->addRoute('GET', '/admin/users/{userId}/audit', 'Admin@getUserAuditLogs');
    
    // Admin background job routes
    $router->addRoute('GET', '/admin/jobs', 'Admin@getBackgroundJobs');
    $router->addRoute('POST', '/admin/jobs/trigger', 'Admin@triggerJob');
    
    // Admin statistics routes
    $router->addRoute('GET', '/admin/stats/users', 'Admin@getUserStats');

    // Webhook routes (public - no auth required)
    $router->addRoute('POST', '/webhooks/stripe', 'Billing@handleWebhook', ['stripe']);
    $router->addRoute('POST', '/webhooks/paypal', 'Billing@handleWebhook', ['paypal']);

    Logger::debug("Routes registered successfully");
}
?>