-- Complete AI Chat Platform Schema with Multi-Model Support
-- Enhanced for multiple AI models per prompt with individual response storage

-- Create Database
CREATE DATABASE IF NOT EXISTS ai_chat_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ai_chat_platform;

-- 1. Users Table (updated with Stripe integration)
CREATE TABLE users (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    avatar_url TEXT,
    email_verified BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    is_admin BOOLEAN DEFAULT FALSE,
    last_login_at TIMESTAMP NULL,
    stripe_customer_id VARCHAR(255) UNIQUE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_is_admin (is_admin),
    INDEX idx_users_email (email),
    INDEX idx_users_created_at (created_at),
    INDEX idx_users_stripe_customer_id (stripe_customer_id)
);

-- 2. Organizations Table (unchanged)
CREATE TABLE organizations (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    logo_url TEXT,
    industry VARCHAR(100),
    size VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    created_by CHAR(36),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_organizations_slug (slug),
    INDEX idx_organizations_created_by (created_by)
);

-- 3. Organization Members Table (unchanged)
CREATE TABLE organization_members (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    organization_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    invited_by CHAR(36),
    invitation_token VARCHAR(255),
    invitation_status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id),
    UNIQUE KEY unique_organization_user (organization_id, user_id),
    INDEX idx_org_members_user_id (user_id),
    INDEX idx_org_members_org_id (organization_id)
);

-- 4. Subscription Plans Table (unchanged)
CREATE TABLE subscription_plans (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    name VARCHAR(255) NOT NULL,
    description TEXT,
    plan_type VARCHAR(50) NOT NULL,
    price_monthly DECIMAL(10,2),
    price_yearly DECIMAL(10,2),
    currency VARCHAR(3) DEFAULT 'USD',
    features JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_plans_type (plan_type),
    INDEX idx_plans_active (is_active)
);

-- 5. Enhanced Subscriptions Table (with Stripe integration)
CREATE TABLE subscriptions (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NOT NULL,
    tier ENUM('free', 'pro', 'enterprise') DEFAULT 'free',
    status ENUM('active', 'inactive', 'cancelled') DEFAULT 'active',
    context_window_size INT DEFAULT 10,
    max_sessions INT DEFAULT 1,
    max_messages_per_session INT DEFAULT 100,
    max_models_per_prompt INT DEFAULT 1, -- NEW: Limit number of models per prompt
    features JSON,
    limits JSON,
    stripe_subscription_id VARCHAR(255) UNIQUE NULL,
    stripe_price_id VARCHAR(255) NULL,
    current_period_start TIMESTAMP NULL,
    current_period_end TIMESTAMP NULL,
    cancel_at_period_end BOOLEAN DEFAULT FALSE,
    trial_start TIMESTAMP NULL,
    trial_end TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_subscriptions_user_id (user_id),
    INDEX idx_subscriptions_tier (tier),
    INDEX idx_subscriptions_status (status),
    INDEX idx_subscriptions_stripe_subscription_id (stripe_subscription_id),
    INDEX idx_subscriptions_stripe_price_id (stripe_price_id),
    INDEX idx_subscriptions_current_period_end (current_period_end)
);

-- 6. Subscription Invoices Table (unchanged)
CREATE TABLE subscription_invoices (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    subscription_id CHAR(36) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    status VARCHAR(20) DEFAULT 'pending',
    due_date TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_due_date (due_date)
);

-- 7. AI Models Table (enhanced)
CREATE TABLE ai_models (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    model_name VARCHAR(255) NOT NULL,
    provider VARCHAR(100) NOT NULL,
    description TEXT,
    context_length INT,
    is_active BOOLEAN DEFAULT TRUE,
    config JSON,
    display_name VARCHAR(100), -- NEW: User-friendly display name
    display_order INT DEFAULT 0, -- NEW: For ordering in UI
    is_default BOOLEAN DEFAULT FALSE, -- NEW: Default model for new sessions
    capabilities JSON, -- NEW: Model capabilities (text, vision, audio, etc.)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_provider_model (provider, model_name),
    INDEX idx_models_provider (provider),
    INDEX idx_models_active (is_active),
    INDEX idx_models_display_order (display_order),
    INDEX idx_models_is_default (is_default),
    INDEX idx_models_created_at (created_at)
);

-- 8. Enhanced Chat Sessions Table
CREATE TABLE chat_sessions (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NOT NULL,
    organization_id CHAR(36) NULL,
    title VARCHAR(255) NOT NULL,
    default_model_id CHAR(36) NOT NULL, -- Primary/default model for the session
    is_active BOOLEAN DEFAULT TRUE,
    metadata JSON,
    last_message_at TIMESTAMP NULL,
    prompt_count INT DEFAULT 0, -- NEW: Count of user prompts
    response_count INT DEFAULT 0, -- NEW: Count of AI responses
    total_tokens INT DEFAULT 0,
    total_cost DECIMAL(10,4) DEFAULT 0.0000,
    context_summary TEXT NULL,
    last_context_update TIMESTAMP NULL,
    thinking_pattern_summary TEXT NULL,
    session_metadata JSON NULL,
    continuity_token VARCHAR(255) UNIQUE NULL,
    version INT DEFAULT 1 NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    FOREIGN KEY (default_model_id) REFERENCES ai_models(id),
    INDEX idx_chat_sessions_user_id (user_id),
    INDEX idx_chat_sessions_org_id (organization_id),
    INDEX idx_chat_sessions_last_message (last_message_at),
    INDEX idx_chat_sessions_continuity_token (continuity_token),
    INDEX idx_chat_sessions_last_message_at (last_message_at),
    INDEX idx_chat_sessions_is_active_last_message (is_active, last_message_at),
    INDEX idx_chat_sessions_version (version),
    INDEX idx_chat_sessions_user_active (user_id, is_active, last_message_at)
);

-- 9. SESSION MODELS TABLE (CORE FOR MULTI-MODEL SUPPORT)
-- Tracks which models are enabled for a specific session
CREATE TABLE session_models (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    session_id CHAR(36) NOT NULL,
    model_id CHAR(36) NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    is_visible BOOLEAN DEFAULT TRUE, -- NEW: Controls UI visibility
    display_order INT DEFAULT 0, -- NEW: Order in which models appear
    configuration JSON, -- Model-specific settings per session
    last_used_at TIMESTAMP NULL, -- NEW: Track when this model was last used
    usage_count INT DEFAULT 0, -- NEW: How many times this model was used in session
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES ai_models(id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_model (session_id, model_id),
    INDEX idx_session_models_session_id (session_id),
    INDEX idx_session_models_model_id (model_id),
    INDEX idx_session_models_enabled (is_enabled),
    INDEX idx_session_models_visible (is_visible),
    INDEX idx_session_models_session_enabled (session_id, is_enabled, is_visible),
    INDEX idx_session_models_display_order (session_id, display_order)
);

-- 10. USER PROMPTS TABLE (CORE)
-- Stores user prompts independently of responses
CREATE TABLE user_prompts (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    session_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    content TEXT NOT NULL,
    topic VARCHAR(255),
    category VARCHAR(100),
    sentiment ENUM('positive', 'negative', 'neutral', 'mixed') DEFAULT 'neutral',
    complexity_score DECIMAL(3,2) CHECK (complexity_score >= 0 AND complexity_score <= 10),
    input_tokens INT,
    metadata JSON,
    is_edited BOOLEAN DEFAULT FALSE,
    edit_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_prompts_session_id (session_id),
    INDEX idx_user_prompts_user_id (user_id),
    INDEX idx_user_prompts_created_at (created_at),
    INDEX idx_user_prompts_topic_category (topic, category),
    INDEX idx_user_prompts_sentiment (sentiment),
    INDEX idx_user_prompts_session_created (session_id, created_at)
);

-- 11. AI RESPONSES TABLE (CORE)
-- Stores responses from AI models, linked to prompts
CREATE TABLE ai_responses (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    prompt_id CHAR(36) NOT NULL,
    model_id CHAR(36) NOT NULL,
    session_id CHAR(36) NOT NULL, -- Denormalized for performance
    content TEXT NOT NULL,
    confidence_score DECIMAL(3,2) CHECK (confidence_score >= 0 AND confidence_score <= 10),
    source_citations JSON,
    follow_up_suggestions JSON,
    generation_time_ms INT,
    output_tokens INT,
    is_visible BOOLEAN DEFAULT TRUE, -- Controls if response is shown in UI
    is_preferred BOOLEAN DEFAULT FALSE, -- Marks as user's preferred response
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (prompt_id) REFERENCES user_prompts(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES ai_models(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_prompt_model (prompt_id, model_id), -- One response per model per prompt
    INDEX idx_ai_responses_prompt_id (prompt_id),
    INDEX idx_ai_responses_model_id (model_id),
    INDEX idx_ai_responses_session_id (session_id),
    INDEX idx_ai_responses_created_at (created_at),
    INDEX idx_ai_responses_confidence (confidence_score),
    INDEX idx_ai_responses_visible (is_visible),
    INDEX idx_ai_responses_preferred (is_preferred),
    INDEX idx_ai_responses_prompt_model (prompt_id, model_id, is_visible)
);

-- 12. MESSAGE THREADS TABLE (Optional, for grouping prompts/responses)
-- Useful for complex conversation flows
CREATE TABLE message_threads (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    session_id CHAR(36) NOT NULL,
    parent_prompt_id CHAR(36) NULL,
    title VARCHAR(255),
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_prompt_id) REFERENCES user_prompts(id) ON DELETE SET NULL,
    INDEX idx_message_threads_session_id (session_id),
    INDEX idx_message_threads_parent_prompt (parent_prompt_id)
);

-- 13. Thinking Traces Table
CREATE TABLE thinking_traces (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NULL,
    session_id CHAR(36) NOT NULL,
    prompt_id CHAR(36) NULL,
    response_id CHAR(36) NULL,
    trace_type VARCHAR(50) NOT NULL,
    content TEXT NOT NULL,
    metadata JSON,
    sequence_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (prompt_id) REFERENCES user_prompts(id) ON DELETE CASCADE,
    FOREIGN KEY (response_id) REFERENCES ai_responses(id) ON DELETE CASCADE,
    INDEX idx_thinking_traces_session_id (session_id),
    INDEX idx_thinking_traces_user_id (user_id),
    INDEX idx_thinking_traces_type (trace_type),
    INDEX idx_thinking_traces_sequence (session_id, sequence_order),
    INDEX idx_thinking_traces_user_session (user_id, session_id, created_at)
);

-- 14. User Drafts Table
CREATE TABLE user_drafts (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NOT NULL,
    session_id CHAR(36) NULL,
    content TEXT NOT NULL,
    draft_type VARCHAR(50) DEFAULT 'message',
    is_active BOOLEAN DEFAULT TRUE,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    INDEX idx_user_drafts_user_id (user_id),
    INDEX idx_user_drafts_session_id (session_id),
    INDEX idx_user_drafts_active (user_id, is_active)
);

-- 15. Conversation Metadata Table
CREATE TABLE conversation_metadata (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    session_id CHAR(36) NOT NULL UNIQUE,
    tags JSON,
    summary TEXT,
    priority VARCHAR(20) DEFAULT 'normal',
    category VARCHAR(100),
    custom_fields JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    INDEX idx_conversation_metadata_session_id (session_id),
    INDEX idx_conversation_metadata_category (category),
    INDEX idx_conversation_metadata_priority (priority)
);

-- 16. Response Feedback Table
CREATE TABLE response_feedback (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NOT NULL,
    response_id CHAR(36) NOT NULL,
    rating INT CHECK (rating >= 1 AND rating <= 5),
    feedback_text TEXT,
    is_helpful BOOLEAN,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (response_id) REFERENCES ai_responses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_response_feedback (user_id, response_id),
    INDEX idx_response_feedback_response_id (response_id),
    INDEX idx_response_feedback_user_id (user_id),
    INDEX idx_response_feedback_rating (rating)
);

-- REMOVED: Response Comparisons Table (was table 17)

-- 18. Model Performance Metrics Table
CREATE TABLE model_performance_metrics (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    model_id CHAR(36) NOT NULL,
    response_id CHAR(36) NOT NULL,
    latency_ms INT,
    tokens_per_second DECIMAL(10,2),
    cost_usd DECIMAL(10,6),
    error_rate DECIMAL(5,2),
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES ai_models(id) ON DELETE CASCADE,
    FOREIGN KEY (response_id) REFERENCES ai_responses(id) ON DELETE CASCADE,
    INDEX idx_model_performance_model_id (model_id),
    INDEX idx_model_performance_response_id (response_id),
    INDEX idx_model_performance_created_at (created_at)
);

-- 19. Vector Memories Table
CREATE TABLE vector_memories (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    session_id CHAR(36) NOT NULL,
    prompt_id CHAR(36) NULL,
    response_id CHAR(36) NULL,
    content TEXT NOT NULL,
    role ENUM('user', 'assistant', 'system') NOT NULL,
    embedding JSON,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (prompt_id) REFERENCES user_prompts(id) ON DELETE SET NULL,
    FOREIGN KEY (response_id) REFERENCES ai_responses(id) ON DELETE SET NULL,
    INDEX idx_vector_memories_session_id (session_id),
    INDEX idx_vector_memories_role (role),
    INDEX idx_vector_memories_created_at (created_at),
    INDEX idx_vector_memories_session_created (session_id, created_at)
);

-- 20. Background Jobs Table
CREATE TABLE background_jobs (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    job_type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    priority ENUM('urgent', 'high', 'normal', 'low') DEFAULT 'normal',
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    scheduled_at TIMESTAMP NOT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    retry_count INT DEFAULT 0,
    result JSON NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_background_jobs_status (status),
    INDEX idx_background_jobs_priority (priority),
    INDEX idx_background_jobs_scheduled_at (scheduled_at),
    INDEX idx_background_jobs_type (job_type)
);

-- 21. Performance Metrics Table
CREATE TABLE performance_metrics (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    operation VARCHAR(100) NOT NULL,
    duration_ms DECIMAL(10,2) NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_slow BOOLEAN DEFAULT FALSE,
    metadata JSON,
    INDEX idx_performance_metrics_operation (operation),
    INDEX idx_performance_metrics_timestamp (timestamp),
    INDEX idx_performance_metrics_slow (is_slow),
    INDEX idx_performance_metrics_operation_timestamp (operation, timestamp)
);

-- 22. Audit Logs Table
CREATE TABLE audit_logs (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    event_type VARCHAR(100) NOT NULL,
    resource_id CHAR(36) NULL,
    user_id CHAR(36) NULL,
    session_id VARCHAR(255) NULL,
    details JSON,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_event_type (event_type),
    INDEX idx_audit_logs_resource_id (resource_id),
    INDEX idx_audit_logs_user_id (user_id),
    INDEX idx_audit_logs_timestamp (timestamp),
    INDEX idx_audit_logs_user_timestamp (user_id, timestamp)
);

-- 23. Payment Gateways Table
CREATE TABLE payment_gateways (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    name VARCHAR(100) NOT NULL,
    gateway_key VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    config JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_gateway_key (gateway_key),
    INDEX idx_gateways_active (is_active)
);

-- 24. Payment Methods Table
CREATE TABLE payment_methods (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NOT NULL,
    gateway_id CHAR(36) NOT NULL,
    gateway_payment_method_id VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL, -- card, bank_account, etc.
    last4 VARCHAR(4),
    brand VARCHAR(50), -- visa, mastercard, etc.
    expiry_month INT,
    expiry_year INT,
    is_default BOOLEAN DEFAULT FALSE,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id),
    UNIQUE KEY unique_gateway_method (gateway_id, gateway_payment_method_id),
    INDEX idx_payment_methods_user_id (user_id),
    INDEX idx_payment_methods_gateway_id (gateway_id),
    INDEX idx_payment_methods_default (user_id, is_default)
);

-- 25. Payment Transactions Table
CREATE TABLE payment_transactions (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NULL,
    organization_id CHAR(36) NULL,
    gateway_id CHAR(36) NOT NULL,
    gateway_transaction_id VARCHAR(255),
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    status VARCHAR(50) NOT NULL,
    payment_method VARCHAR(100),
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id),
    INDEX idx_payments_user_id (user_id),
    INDEX idx_payments_org_id (organization_id),
    INDEX idx_payments_status (status),
    INDEX idx_payments_gateway_tx_id (gateway_transaction_id)
);

-- 25. Refresh Tokens Table
CREATE TABLE refresh_tokens (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_refresh_tokens_user_id (user_id),
    INDEX idx_refresh_tokens_expires (expires_at)
);

-- 26. API Keys Table
CREATE TABLE api_keys (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    organization_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    key_hash VARCHAR(255) NOT NULL,
    scopes JSON,
    expires_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    INDEX idx_api_keys_org_id (organization_id),
    INDEX idx_api_keys_active (is_active)
);

-- 27. Email Verifications Table
CREATE TABLE email_verifications (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NOT NULL,
    email VARCHAR(255) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    token VARCHAR(255) NULL,
    token_type VARCHAR(20) DEFAULT 'verification',
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_email_verifications_user_id (user_id),
    INDEX idx_email_verifications_token_hash (token_hash),
    INDEX idx_email_verifications_expires_at (expires_at),
    INDEX idx_email_verifications_email (email),
    UNIQUE KEY unique_active_token_per_user (user_id, token_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key constraint for organizations.created_by after users table exists
ALTER TABLE organizations ADD CONSTRAINT fk_organizations_created_by 
    FOREIGN KEY (created_by) REFERENCES users(id);

-- Create Views for Multi-Model Conversations

-- View for conversation with all prompts and their multi-model responses
CREATE VIEW conversation_multi_model_view AS
SELECT
    cs.id as session_id,
    cs.title as session_title,
    cs.is_active as session_active,
    up.id as prompt_id,
    up.content as user_prompt,
    up.created_at as prompt_time,
    up.topic,
    up.sentiment,
    COUNT(DISTINCT ar.id) as response_count,
    GROUP_CONCAT(DISTINCT am.model_name ORDER BY sm.display_order) as model_names,
    GROUP_CONCAT(DISTINCT
        CONCAT(
            am.model_name, ':',
            CASE WHEN ar.is_visible THEN 'visible' ELSE 'hidden' END
        )
        ORDER BY sm.display_order
    ) as model_visibility
FROM chat_sessions cs
JOIN user_prompts up ON cs.id = up.session_id
LEFT JOIN ai_responses ar ON up.id = ar.prompt_id
LEFT JOIN ai_models am ON ar.model_id = am.id
LEFT JOIN session_models sm ON cs.id = sm.session_id AND am.id = sm.model_id
GROUP BY cs.id, cs.title, cs.is_active, up.id, up.content, up.created_at, up.topic, up.sentiment
ORDER BY cs.created_at DESC, up.created_at;

-- View for enabled models per session
CREATE VIEW session_enabled_models_view AS
SELECT
    cs.id as session_id,
    cs.title as session_title,
    sm.model_id,
    am.model_name as model_name,
    am.provider,
    sm.is_enabled,
    sm.is_visible,
    sm.display_order,
    sm.configuration,
    sm.usage_count,
    sm.last_used_at
FROM chat_sessions cs
JOIN session_models sm ON cs.id = sm.session_id
JOIN ai_models am ON sm.model_id = am.id
WHERE sm.is_enabled = TRUE
ORDER BY cs.id, sm.display_order;

-- View for prompt with all responses
CREATE VIEW prompt_responses_view AS
SELECT
    up.id as prompt_id,
    up.session_id,
    up.content as prompt_content,
    up.created_at as prompt_time,
    ar.id as response_id,
    ar.model_id,
    am.model_name as model_name,
    ar.content as response_content,
    ar.confidence_score,
    ar.generation_time_ms,
    ar.output_tokens,
    ar.is_visible,
    ar.is_preferred,
    ar.created_at as response_time
FROM user_prompts up
LEFT JOIN ai_responses ar ON up.id = ar.prompt_id
LEFT JOIN ai_models am ON ar.model_id = am.id
ORDER BY up.created_at, am.model_name;

-- Stored Procedures for Multi-Model Operations

DELIMITER //

-- Procedure to create a new prompt (for individual model responses)
CREATE PROCEDURE CreateUserPrompt(
    IN p_session_id CHAR(36),
    IN p_user_id CHAR(36),
    IN p_content TEXT,
    IN p_topic VARCHAR(255),
    OUT p_prompt_id CHAR(36)
)
BEGIN
    -- Create the prompt
    INSERT INTO user_prompts (id, session_id, user_id, content, topic, input_tokens, created_at, updated_at)
    VALUES (UUID(), p_session_id, p_user_id, p_content, p_topic, LENGTH(p_content) / 4, NOW(), NOW());

    SET p_prompt_id = LAST_INSERT_ID();

    -- Update session stats
    UPDATE chat_sessions
    SET prompt_count = prompt_count + 1,
        last_message_at = NOW(),
        updated_at = NOW()
    WHERE id = p_session_id;
END //

-- Procedure to create individual model response
CREATE PROCEDURE CreateModelResponse(
    IN p_prompt_id CHAR(36),
    IN p_model_id CHAR(36),
    IN p_session_id CHAR(36),
    IN p_content TEXT,
    IN p_confidence_score DECIMAL(3,2),
    IN p_generation_time_ms INT,
    IN p_output_tokens INT,
    OUT p_response_id CHAR(36)
)
BEGIN
    DECLARE v_is_visible BOOLEAN DEFAULT TRUE;

    -- Check if model is visible for this session
    SELECT is_visible INTO v_is_visible
    FROM session_models
    WHERE session_id = p_session_id AND model_id = p_model_id;

    -- Create the response
    INSERT INTO ai_responses (
        id, prompt_id, model_id, session_id, content,
        confidence_score, generation_time_ms, output_tokens,
        is_visible, created_at, updated_at
    ) VALUES (
        UUID(),
        p_prompt_id,
        p_model_id,
        p_session_id,
        p_content,
        p_confidence_score,
        p_generation_time_ms,
        p_output_tokens,
        COALESCE(v_is_visible, TRUE),
        NOW(),
        NOW()
    );

    SET p_response_id = LAST_INSERT_ID();

    -- Update session model usage
    UPDATE session_models
    SET usage_count = usage_count + 1,
        last_used_at = NOW(),
        updated_at = NOW()
    WHERE session_id = p_session_id AND model_id = p_model_id;

    -- Update session response count
    UPDATE chat_sessions
    SET response_count = response_count + 1,
        total_tokens = total_tokens + COALESCE(p_output_tokens, 0),
        updated_at = NOW()
    WHERE id = p_session_id;
END //

-- Procedure to toggle model visibility for a prompt
CREATE PROCEDURE ToggleModelResponseVisibility(
    IN p_prompt_id CHAR(36),
    IN p_model_id CHAR(36),
    IN p_is_visible BOOLEAN
)
BEGIN
    UPDATE ai_responses
    SET is_visible = p_is_visible,
        updated_at = NOW()
    WHERE prompt_id = p_prompt_id AND model_id = p_model_id;
    
    SELECT ROW_COUNT() as rows_updated;
END //

-- Procedure to mark a response as preferred
CREATE PROCEDURE MarkResponseAsPreferred(
    IN p_response_id CHAR(36)
)
BEGIN
    DECLARE v_prompt_id CHAR(36);
    
    -- Get prompt info
    SELECT prompt_id INTO v_prompt_id
    FROM ai_responses
    WHERE id = p_response_id;
    
    -- First, unset preferred flag for all responses of this prompt
    UPDATE ai_responses
    SET is_preferred = FALSE,
        updated_at = NOW()
    WHERE prompt_id = v_prompt_id;
    
    -- Then set the selected response as preferred
    UPDATE ai_responses
    SET is_preferred = TRUE,
        updated_at = NOW()
    WHERE id = p_response_id;
    
    SELECT ROW_COUNT() as rows_updated;
END //

-- Procedure to get conversation thread with multi-model responses
CREATE PROCEDURE GetConversationThread(
    IN p_session_id CHAR(36),
    IN p_limit INT,
    IN p_offset INT
)
BEGIN
    -- Get prompts with their visible responses
    SELECT
        up.id as prompt_id,
        up.content as prompt_content,
        up.created_at as prompt_time,
        up.topic,
        up.sentiment,
        JSON_ARRAYAGG(
            JSON_OBJECT(
                'response_id', ar.id,
                'model_id', ar.model_id,
                'model_name', am.model_name,
                'content', ar.content,
                'confidence_score', ar.confidence_score,
                'is_visible', ar.is_visible,
                'is_preferred', ar.is_preferred,
                'response_time', ar.created_at
            )
        ) as responses
    FROM user_prompts up
    LEFT JOIN ai_responses ar ON up.id = ar.prompt_id AND ar.is_visible = TRUE
    LEFT JOIN ai_models am ON ar.model_id = am.id
    WHERE up.session_id = p_session_id
    GROUP BY up.id, up.content, up.created_at, up.topic, up.sentiment
    ORDER BY up.created_at DESC
    LIMIT p_limit OFFSET p_offset;

    -- Get session models info
    SELECT
        sm.model_id,
        am.model_name as model_name,
        am.provider,
        sm.is_enabled,
        sm.is_visible,
        sm.display_order,
        sm.configuration
    FROM session_models sm
    JOIN ai_models am ON sm.model_id = am.id
    WHERE sm.session_id = p_session_id
    ORDER BY sm.display_order;
END //

-- Procedure to enable/disable a model for session
CREATE PROCEDURE ToggleSessionModel(
    IN p_session_id CHAR(36),
    IN p_model_id CHAR(36),
    IN p_is_enabled BOOLEAN,
    IN p_is_visible BOOLEAN
)
BEGIN
    DECLARE v_exists INT;
    
    -- Check if record exists
    SELECT COUNT(*) INTO v_exists
    FROM session_models
    WHERE session_id = p_session_id AND model_id = p_model_id;
    
    IF v_exists > 0 THEN
        -- Update existing record
        UPDATE session_models
        SET is_enabled = p_is_enabled,
            is_visible = COALESCE(p_is_visible, is_visible),
            updated_at = NOW()
        WHERE session_id = p_session_id AND model_id = p_model_id;
    ELSE
        -- Insert new record
        INSERT INTO session_models (
            id, session_id, model_id, is_enabled, is_visible,
            display_order, created_at, updated_at
        ) VALUES (
            UUID(),
            p_session_id,
            p_model_id,
            p_is_enabled,
            COALESCE(p_is_visible, TRUE),
            (SELECT COALESCE(MAX(display_order), 0) + 1 FROM session_models WHERE session_id = p_session_id),
            NOW(),
            NOW()
        );
    END IF;
    
    SELECT ROW_COUNT() as rows_affected;
END //

-- Procedure to reorder models in session
CREATE PROCEDURE ReorderSessionModels(
    IN p_session_id CHAR(36),
    IN p_model_order_json JSON -- Array of model IDs in desired order
)
BEGIN
    DECLARE v_model_id CHAR(36);
    DECLARE v_index INT DEFAULT 0;
    DECLARE v_count INT;
    
    SET v_count = JSON_LENGTH(p_model_order_json);
    
    START TRANSACTION;
    
    WHILE v_index < v_count DO
        SET v_model_id = JSON_UNQUOTE(JSON_EXTRACT(p_model_order_json, CONCAT('$[', v_index, ']')));
        
        UPDATE session_models
        SET display_order = v_index + 1,
            updated_at = NOW()
        WHERE session_id = p_session_id AND model_id = v_model_id;
        
        SET v_index = v_index + 1;
    END WHILE;
    
    COMMIT;
    
    SELECT v_count as models_reordered;
END //

DELIMITER ;

-- Create Events

-- Event to update session statistics
CREATE EVENT IF NOT EXISTS update_session_stats
ON SCHEDULE EVERY 1 HOUR
DO
    UPDATE chat_sessions cs
    SET
        prompt_count = (
            SELECT COUNT(*) FROM user_prompts up WHERE up.session_id = cs.id
        ),
        response_count = (
            SELECT COUNT(*) FROM ai_responses ar 
            JOIN user_prompts up ON ar.prompt_id = up.id 
            WHERE up.session_id = cs.id
        ),
        total_tokens = (
            SELECT COALESCE(SUM(up.input_tokens), 0) + COALESCE(SUM(ar.output_tokens), 0)
            FROM user_prompts up
            LEFT JOIN ai_responses ar ON up.id = ar.prompt_id
            WHERE up.session_id = cs.id
        ),
        updated_at = NOW()
    WHERE cs.id IN (
        SELECT DISTINCT session_id 
        FROM user_prompts 
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    );

-- Insert Default Data

-- Insert default AI models
INSERT INTO ai_models (id, model_name, provider, description, context_length, display_name, display_order, is_default, capabilities) VALUES
(UUID(), 'deepseek-r1:7b', 'ollama', 'DeepSeek R1 7B model', 32768, 'DeepSeek R1', 1, TRUE, '["text", "reasoning"]'),
(UUID(), 'llama3.1:8b', 'ollama', 'Llama 3.1 8B model', 131072, 'Llama 3.1', 2, FALSE, '["text"]'),
(UUID(), 'DeepSeek: R1 0528', 'openrouter', 'DeepSeek R1 model', 128000, 'DeepSeek: R1 0528', 3, FALSE, '["text", "vision"]');

-- Insert default payment gateways
INSERT INTO payment_gateways (id, name, gateway_key, config) VALUES
(UUID(), 'Stripe', 'stripe', '{"webhook_secret": "", "api_version": "2023-10-16"}'),
(UUID(), 'PayPal', 'paypal', '{"webhook_id": "", "api_mode": "live"}'),
(UUID(), 'Razorpay', 'razorpay', '{"webhook_secret": ""}');

SELECT 'Complete multi-model database schema created successfully!' as message;
