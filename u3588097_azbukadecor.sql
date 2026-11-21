-- Схема базы данных. Записи из рабочих таблиц исключены.

CREATE TABLE `action_logs` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `action_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_settings` (
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` json NOT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int DEFAULT NULL,
  `role` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint DEFAULT NULL,
  `order_id` int DEFAULT NULL,
  `lead_id` int DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `http_method` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` enum('success','failure','denied') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bot_state` (
  `state_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `state_value` json DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `calendar_entries` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int DEFAULT NULL,
  `event_id` int DEFAULT NULL,
  `entry_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT '0',
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planned',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `calendar_entry_participants` (
  `id` bigint UNSIGNED NOT NULL,
  `calendar_entry_id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `role_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conflict_override` tinyint(1) NOT NULL DEFAULT '0',
  `override_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `assigned_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `candidate_applications` (
  `id` bigint UNSIGNED NOT NULL,
  `full_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `desired_role` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `experience` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `desired_salary` decimal(12,2) NOT NULL DEFAULT '0.00',
  `portfolio_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `preferred_schedule` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('new','contacted','interview','rejected','hired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `submission_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public_site',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `telegram_user_id` bigint DEFAULT NULL,
  `telegram_chat_id` bigint DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `candidate_application_files` (
  `id` bigint UNSIGNED NOT NULL,
  `candidate_application_id` bigint UNSIGNED NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint UNSIGNED DEFAULT NULL,
  `telegram_file_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chats` (
  `id` int NOT NULL,
  `event_id` int DEFAULT NULL,
  `lead_id` int DEFAULT NULL,
  `order_id` int DEFAULT NULL,
  `type` enum('event_group','personal','client','order_client','order_team') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'personal',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_participants` (
  `id` int NOT NULL,
  `chat_id` int NOT NULL,
  `user_id` int NOT NULL,
  `joined_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `client_profiles` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `preferred_contact` enum('site_chat','telegram','whatsapp','call','sms','email') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'site_chat',
  `telegram_username` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `concept_approvals` (
  `id` bigint UNSIGNED NOT NULL,
  `version_id` bigint UNSIGNED NOT NULL,
  `client_id` int NOT NULL,
  `decision` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `concept_comments` (
  `id` bigint UNSIGNED NOT NULL,
  `version_id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `concept_files` (
  `id` bigint UNSIGNED NOT NULL,
  `version_id` bigint UNSIGNED NOT NULL,
  `file_kind` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reference',
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int UNSIGNED NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `concept_versions` (
  `id` bigint UNSIGNED NOT NULL,
  `concept_id` bigint UNSIGNED NOT NULL,
  `version_no` int UNSIGNED NOT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `style_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color_palette` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `composition_notes` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_comments` (
  `id` bigint UNSIGNED NOT NULL,
  `document_id` bigint UNSIGNED NOT NULL,
  `version_id` bigint UNSIGNED DEFAULT NULL,
  `user_id` int NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_confirmations` (
  `id` bigint UNSIGNED NOT NULL,
  `document_id` bigint UNSIGNED NOT NULL,
  `version_id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `decision` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_templates` (
  `id` int NOT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_html` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `events` (
  `id` int NOT NULL,
  `organizer_id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `technical_task` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','active','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_media` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_staff` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `worker_id` int NOT NULL,
  `status` enum('pending','confirmed','on_location','completed','declined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `arrived_at` timestamp NULL DEFAULT NULL,
  `decline_reason` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_templates` (
  `id` int NOT NULL,
  `organizer_id` int NOT NULL,
  `template_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `event_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_hours` int DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `technical_task` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `template_payload` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leads` (
  `id` int NOT NULL,
  `client_id` int DEFAULT NULL,
  `manager_id` int DEFAULT NULL,
  `next_contact_at` datetime DEFAULT NULL,
  `reminder_at` datetime DEFAULT NULL,
  `order_id` int DEFAULT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `client_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_method` enum('site_chat','telegram','whatsapp','call','sms','email') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'site_chat',
  `telegram_username` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_date` date DEFAULT NULL,
  `event_time` time DEFAULT NULL,
  `location_city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_details` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `venue` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guests_count` int DEFAULT NULL,
  `budget_range` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estimated_budget` decimal(14,2) DEFAULT NULL,
  `style` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color_palette` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `urgency` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consent_at` datetime DEFAULT NULL,
  `referer_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submission_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `services_needed` json DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `client_wishes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `client_likes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `client_dislikes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `client_additional_comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `client_questions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `manager_answers` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `conversation_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `refusal_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `internal_comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'quiz',
  `utm_source` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_medium` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_campaign` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_content` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utm_term` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_call_checklist_items` (
  `id` bigint UNSIGNED NOT NULL,
  `lead_id` int NOT NULL,
  `item_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT '0',
  `completed_by` int DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_files` (
  `id` int NOT NULL,
  `lead_id` int NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `telegram_file_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_follow_ups` (
  `id` bigint UNSIGNED NOT NULL,
  `lead_id` int NOT NULL,
  `manager_id` int NOT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'callback',
  `status` enum('scheduled','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_status_history` (
  `id` bigint UNSIGNED NOT NULL,
  `lead_id` int NOT NULL,
  `old_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` int DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` bigint UNSIGNED NOT NULL,
  `identifier_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT '0',
  `failure_reason` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_challenges` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `purpose` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'login',
  `code_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `manager_templates` (
  `id` bigint UNSIGNED NOT NULL,
  `manager_id` int NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `messages` (
  `id` int NOT NULL,
  `chat_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) DEFAULT '0',
  `reply_to_message_id` int DEFAULT NULL,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'site',
  `edited_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `revision` int UNSIGNED NOT NULL DEFAULT '1',
  `telegram_deep_link` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `media_group_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `forwarded` tinyint(1) NOT NULL DEFAULT '0',
  `unsupported_media_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_attachments` (
  `id` bigint UNSIGNED NOT NULL,
  `message_id` int NOT NULL,
  `attachment_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint UNSIGNED DEFAULT NULL,
  `telegram_file_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_file_unique_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `width` int UNSIGNED DEFAULT NULL,
  `height` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_revisions` (
  `id` bigint UNSIGNED NOT NULL,
  `message_id` int NOT NULL,
  `revision` int UNSIGNED NOT NULL,
  `message_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `edited_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `migration_log` (
  `id` bigint UNSIGNED NOT NULL,
  `migration_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notification_queue` (
  `id` bigint UNSIGNED NOT NULL,
  `channel` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'telegram',
  `recipient_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_id` int DEFAULT NULL,
  `telegram_chat_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `priority` smallint NOT NULL DEFAULT '100',
  `attempts` smallint UNSIGNED NOT NULL DEFAULT '0',
  `max_attempts` smallint UNSIGNED NOT NULL DEFAULT '6',
  `next_attempt_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `idempotency_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `order_id` int DEFAULT NULL,
  `site_chat_id` int DEFAULT NULL,
  `site_message_id` int DEFAULT NULL,
  `permanent_failure` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orders` (
  `id` int NOT NULL,
  `lead_id` int DEFAULT NULL,
  `client_id` int NOT NULL,
  `manager_id` int DEFAULT NULL,
  `organizer_id` int DEFAULT NULL,
  `event_id` int DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('new_lead','discussion','estimate','contract','prepayment','preparation','event_day','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new_lead',
  `event_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `venue` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guests_count` int DEFAULT NULL,
  `style` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `budget_range` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `services_needed` json DEFAULT NULL,
  `client_comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `internal_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `show_team_to_client` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_checklist_items` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int NOT NULL,
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `done_by` int DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_concepts` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_version_id` bigint UNSIGNED DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_documents` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int NOT NULL,
  `template_id` int DEFAULT NULL,
  `document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `current_version_id` bigint UNSIGNED DEFAULT NULL,
  `public_token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_expires_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_document_versions` (
  `id` bigint UNSIGNED NOT NULL,
  `document_id` bigint UNSIGNED NOT NULL,
  `version_no` int UNSIGNED NOT NULL,
  `body_html` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_data` json DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_expenses` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int NOT NULL,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `planned_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `actual_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `expense_date` date DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `proof_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_finance` (
  `order_id` int NOT NULL,
  `total_cost` decimal(14,2) NOT NULL DEFAULT '0.00',
  `planned_revenue` decimal(14,2) NOT NULL DEFAULT '0.00',
  `actual_revenue` decimal(14,2) NOT NULL DEFAULT '0.00',
  `prepayment` decimal(14,2) NOT NULL DEFAULT '0.00',
  `planned_expenses` decimal(14,2) NOT NULL DEFAULT '0.00',
  `actual_expenses` decimal(14,2) NOT NULL DEFAULT '0.00',
  `planned_profit` decimal(14,2) NOT NULL DEFAULT '0.00',
  `actual_profit` decimal(14,2) NOT NULL DEFAULT '0.00',
  `margin_percent` decimal(7,2) NOT NULL DEFAULT '0.00',
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_materials` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `uploaded_by` int DEFAULT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `material_type` enum('pdf','photo','text','estimate','contract','concept','timeline','plan','moodboard','file') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'file',
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `visibility` enum('internal','client') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'internal',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_payments` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planned',
  `payment_method` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `proof_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_role_requirements` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int NOT NULL,
  `role_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` smallint UNSIGNED NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_scheduled_notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int NOT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'organizer',
  `recipient_id` int DEFAULT NULL,
  `send_at` datetime NOT NULL,
  `payload_json` json NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `queued_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_status_history` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `old_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` int DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_tasks` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `assigned_to` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('todo','in_progress','done','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'todo',
  `due_date` date DEFAULT NULL,
  `visibility` enum('internal','client') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'internal',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_team` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `worker_id` int NOT NULL,
  `event_staff_id` int DEFAULT NULL,
  `public_role` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `internal_role` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hourly_rate` decimal(10,2) DEFAULT '0.00',
  `planned_hours` decimal(6,2) DEFAULT '0.00',
  `transport_compensation` decimal(10,2) DEFAULT '0.00',
  `participation_status` enum('pending','confirmed','on_location','completed','declined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `payment_status` enum('not_set','pending','approved','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'not_set',
  `tasks_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `internal_comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `show_to_client` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_template_applications` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int NOT NULL,
  `template_id` int DEFAULT NULL,
  `snapshot_json` json NOT NULL,
  `applied_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_timeline_items` (
  `id` bigint UNSIGNED NOT NULL,
  `order_id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsible_id` int DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planned',
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  `completed_by` int DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `password_reset_requests` (
  `id` bigint UNSIGNED NOT NULL,
  `identifier_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ip_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int DEFAULT NULL,
  `was_queued` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
  `id` int NOT NULL,
  `event_id` int NOT NULL,
  `worker_id` int NOT NULL,
  `hours_worked` decimal(5,2) DEFAULT '0.00',
  `hourly_rate` decimal(10,2) DEFAULT '0.00',
  `work_payment` decimal(10,2) DEFAULT '0.00',
  `travel_type` enum('own_car','taxi','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'none',
  `travel_distance` decimal(10,2) DEFAULT NULL,
  `travel_cost` decimal(10,2) DEFAULT '0.00',
  `travel_receipt` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT '0.00',
  `status` enum('pending','approved','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `portfolio_images` (
  `id` int NOT NULL,
  `project_id` int NOT NULL,
  `image_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `alt_text` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `portfolio_projects` (
  `id` int NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `style` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guests_count` int DEFAULT NULL,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cover_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alt_text` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT '1',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `public_team_profiles` (
  `user_id` int NOT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT '0',
  `public_role` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `specialization` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `public_quote` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `experience_years` decimal(4,1) NOT NULL DEFAULT '0.0',
  `sort_order` int NOT NULL DEFAULT '0',
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quiz_answers` (
  `id` int NOT NULL,
  `lead_id` int NOT NULL,
  `question_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `answer_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quiz_drafts` (
  `id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `payload_json` json NOT NULL,
  `expires_at` datetime NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reviews` (
  `id` int NOT NULL,
  `order_id` int DEFAULT NULL,
  `client_id` int DEFAULT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `client_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` decimal(2,1) DEFAULT '5.0',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `service_rating` tinyint UNSIGNED DEFAULT NULL,
  `decor_rating` tinyint UNSIGNED DEFAULT NULL,
  `team_rating` tinyint UNSIGNED DEFAULT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `publication_consent` tinyint(1) NOT NULL DEFAULT '0',
  `photo_consent` tinyint(1) NOT NULL DEFAULT '0',
  `display_name_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'first_name',
  `requested_at` datetime DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `moderated_by` int DEFAULT NULL,
  `moderated_at` datetime DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `review_files` (
  `id` bigint UNSIGNED NOT NULL,
  `review_id` int NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `services` (
  `id` int NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `short_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `description` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `image_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `service_packages` (
  `id` int NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `features` json DEFAULT NULL,
  `price_note` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_auth_requests` (
  `id` bigint UNSIGNED NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `browser_session_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_ip_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_ip` varchar(45) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_bot_sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `telegram_user_id` bigint NOT NULL,
  `telegram_chat_id` bigint NOT NULL,
  `flow_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_chat_bindings` (
  `id` bigint UNSIGNED NOT NULL,
  `site_chat_id` int NOT NULL,
  `order_id` int NOT NULL,
  `group_pool_id` bigint UNSIGNED NOT NULL,
  `telegram_chat_id` bigint NOT NULL,
  `chat_kind` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reserved',
  `activated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_group_memberships` (
  `id` bigint UNSIGNED NOT NULL,
  `binding_id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `telegram_user_id` bigint NOT NULL,
  `membership_role` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invited',
  `invite_id` bigint UNSIGNED DEFAULT NULL,
  `joined_at` datetime DEFAULT NULL,
  `left_at` datetime DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_group_pool` (
  `id` bigint UNSIGNED NOT NULL,
  `telegram_chat_id` bigint NOT NULL,
  `kind` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `bound_order_id` int DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bot_is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `can_invite_users` tinyint(1) NOT NULL DEFAULT '0',
  `can_delete_messages` tinyint(1) NOT NULL DEFAULT '0',
  `can_manage_chat` tinyint(1) NOT NULL DEFAULT '0',
  `can_change_info` tinyint(1) NOT NULL DEFAULT '0',
  `can_restrict_members` tinyint(1) NOT NULL DEFAULT '0',
  `reserved_at` datetime DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_inbound_events` (
  `id` bigint UNSIGNED NOT NULL,
  `idempotency_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telegram_chat_id` bigint NOT NULL,
  `telegram_message_id` bigint DEFAULT NULL,
  `telegram_user_id` bigint NOT NULL,
  `payload_json` json NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int UNSIGNED NOT NULL DEFAULT '0',
  `max_attempts` int UNSIGNED NOT NULL DEFAULT '20',
  `next_attempt_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `site_message_id` bigint DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_invites` (
  `id` bigint UNSIGNED NOT NULL,
  `binding_id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `telegram_user_id` bigint NOT NULL,
  `invite_link` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `telegram_invite_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_link_tokens` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_message_map` (
  `id` bigint UNSIGNED NOT NULL,
  `site_message_id` int NOT NULL,
  `site_chat_id` int NOT NULL,
  `binding_id` bigint UNSIGNED NOT NULL,
  `telegram_chat_id` bigint NOT NULL,
  `telegram_message_id` bigint NOT NULL,
  `telegram_thread_id` bigint DEFAULT NULL,
  `direction` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` int UNSIGNED NOT NULL DEFAULT '1',
  `sync_status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mapped',
  `deleted_at` datetime DEFAULT NULL,
  `remote_deleted_at` datetime DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_notifications` (
  `id` int NOT NULL,
  `type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_chat_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `status` enum('pending','sent','failed','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `response` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_rate_limits` (
  `scope_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` int UNSIGNED NOT NULL DEFAULT '0',
  `window_started_at` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_service_nonces` (
  `nonce_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_sync_operations` (
  `id` bigint UNSIGNED NOT NULL,
  `idempotency_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` int DEFAULT NULL,
  `site_chat_id` int DEFAULT NULL,
  `site_message_id` int DEFAULT NULL,
  `binding_id` bigint UNSIGNED DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int UNSIGNED NOT NULL DEFAULT '0',
  `telegram_message_id` bigint DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_updates` (
  `update_id` bigint NOT NULL,
  `update_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `telegram_chat_id` bigint DEFAULT NULL,
  `processed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `timeline_checklist_items` (
  `id` bigint UNSIGNED NOT NULL,
  `timeline_item_id` bigint UNSIGNED NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT '0',
  `done_by` int DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `timeline_files` (
  `id` bigint UNSIGNED NOT NULL,
  `timeline_item_id` bigint UNSIGNED NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int UNSIGNED NOT NULL,
  `uploaded_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `timeline_history` (
  `id` bigint UNSIGNED NOT NULL,
  `timeline_item_id` bigint UNSIGNED NOT NULL,
  `changed_by` int DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `timeline_participants` (
  `timeline_item_id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int NOT NULL,
  `phone` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('client','manager','organizer','florist','courier','installer','helper','worker','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'client',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `locked_until` datetime DEFAULT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `telegram_chat_id` bigint DEFAULT NULL,
  `telegram_username` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_linked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `telegram_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unlinked'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `session_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_activity_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `worker_availability` (
  `id` int NOT NULL,
  `worker_id` int NOT NULL,
  `unavailable_date` date NOT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `worker_profiles` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `specialty` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `has_car` tinyint(1) DEFAULT '0',
  `car_brand` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `car_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `additional_info` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `public_role` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `experience_years` decimal(4,1) NOT NULL DEFAULT '0.0',
  `rating` decimal(3,2) NOT NULL DEFAULT '5.00',
  `reviews_count` int NOT NULL DEFAULT '0',
  `hourly_rate` decimal(10,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `worker_statistics` (
  `id` int NOT NULL,
  `worker_id` int NOT NULL,
  `total_events` int DEFAULT '0',
  `total_earnings` decimal(10,2) DEFAULT '0.00',
  `total_hours` decimal(10,2) DEFAULT '0.00',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `action_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_entity` (`entity_type`,`entity_id`);

ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`setting_key`),
  ADD KEY `fk_app_setting_user` (`updated_by`);

ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_audit_order` (`order_id`,`created_at`),
  ADD KEY `idx_audit_lead` (`lead_id`,`created_at`),
  ADD KEY `idx_audit_action_created` (`action`,`created_at`);

ALTER TABLE `bot_state`
  ADD PRIMARY KEY (`state_key`);

ALTER TABLE `calendar_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_calendar_period` (`starts_at`,`ends_at`),
  ADD KEY `idx_calendar_order` (`order_id`,`entry_type`),
  ADD KEY `idx_calendar_event` (`event_id`),
  ADD KEY `idx_calendar_status` (`status`),
  ADD KEY `fk_calendar_creator` (`created_by`);

ALTER TABLE `calendar_entry_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_calendar_participant` (`calendar_entry_id`,`user_id`),
  ADD KEY `idx_calendar_participant_user` (`user_id`,`calendar_entry_id`),
  ADD KEY `fk_calendar_participant_assigner` (`assigned_by`);

ALTER TABLE `candidate_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_candidate_submission` (`submission_key`),
  ADD KEY `idx_candidate_status_created` (`status`,`created_at`),
  ADD KEY `idx_candidate_role` (`desired_role`),
  ADD KEY `idx_candidate_telegram` (`telegram_user_id`,`created_at`);

ALTER TABLE `candidate_application_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_candidate_file_application` (`candidate_application_id`,`id`),
  ADD KEY `idx_candidate_file_telegram` (`candidate_application_id`,`telegram_file_id`);

ALTER TABLE `chats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event` (`event_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_chats_lead` (`lead_id`),
  ADD KEY `idx_chats_order` (`order_id`);

ALTER TABLE `chat_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_chat_user` (`chat_id`,`user_id`),
  ADD KEY `idx_chat` (`chat_id`),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `client_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_client_user` (`user_id`);

ALTER TABLE `concept_approvals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_concept_approval` (`version_id`,`client_id`),
  ADD KEY `fk_concept_approval_client` (`client_id`);

ALTER TABLE `concept_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_concept_comments_version` (`version_id`,`created_at`),
  ADD KEY `fk_concept_comments_user` (`user_id`);

ALTER TABLE `concept_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_concept_files_version` (`version_id`,`sort_order`);

ALTER TABLE `concept_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_concept_version` (`concept_id`,`version_no`),
  ADD KEY `idx_concept_version_status` (`status`),
  ADD KEY `fk_concept_versions_user` (`created_by`);

ALTER TABLE `document_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_document_comments` (`document_id`,`created_at`),
  ADD KEY `fk_document_comments_version` (`version_id`),
  ADD KEY `fk_document_comments_user` (`user_id`);

ALTER TABLE `document_confirmations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_document_confirmation` (`version_id`,`user_id`),
  ADD KEY `idx_document_confirmation_document` (`document_id`,`created_at`),
  ADD KEY `fk_document_confirmation_user` (`user_id`);

ALTER TABLE `document_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_document_templates_type` (`document_type`,`is_active`),
  ADD KEY `fk_document_template_user` (`created_by`);

ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_organizer` (`organizer_id`),
  ADD KEY `idx_date` (`event_date`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `event_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_event` (`event_id`);

ALTER TABLE `event_staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_event_worker` (`event_id`,`worker_id`),
  ADD KEY `idx_event` (`event_id`),
  ADD KEY `idx_worker` (`worker_id`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `event_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_organizer` (`organizer_id`);

ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_leads_submission` (`submission_key`),
  ADD KEY `idx_lead_status` (`status`),
  ADD KEY `idx_lead_client` (`client_id`),
  ADD KEY `idx_lead_manager` (`manager_id`),
  ADD KEY `idx_lead_event_date` (`event_date`),
  ADD KEY `idx_leads_next_contact` (`next_contact_at`),
  ADD KEY `idx_leads_source` (`source`),
  ADD KEY `idx_lead_manager_workflow` (`manager_id`,`status`,`created_at`);

ALTER TABLE `lead_call_checklist_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lead_call_checklist_item` (`lead_id`,`item_key`),
  ADD KEY `idx_lead_call_checklist_state` (`lead_id`,`is_done`),
  ADD KEY `idx_lead_call_checklist_user` (`completed_by`);

ALTER TABLE `lead_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lead_files_lead` (`lead_id`),
  ADD KEY `idx_lead_file_telegram` (`lead_id`,`telegram_file_id`);

ALTER TABLE `lead_follow_ups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lead_follow_up_due` (`manager_id`,`status`,`scheduled_at`),
  ADD KEY `idx_lead_follow_up_lead` (`lead_id`,`created_at`);

ALTER TABLE `lead_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lead_status_history` (`lead_id`,`created_at`),
  ADD KEY `fk_lead_status_history_user` (`changed_by`);

ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_identifier_time` (`identifier_hash`,`created_at`),
  ADD KEY `idx_login_ip_time` (`ip_address`,`created_at`),
  ADD KEY `idx_login_user_time` (`user_id`,`created_at`);

ALTER TABLE `login_challenges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_challenge_user` (`user_id`,`purpose`,`expires_at`);

ALTER TABLE `manager_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manager_templates_owner` (`manager_id`,`title`);

ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat` (`chat_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_messages_source` (`source`,`created_at`),
  ADD KEY `idx_messages_reply` (`reply_to_message_id`),
  ADD KEY `idx_messages_deleted` (`chat_id`,`deleted_at`);

ALTER TABLE `message_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_message_attachment_message` (`message_id`,`id`),
  ADD KEY `idx_message_attachment_file_unique` (`telegram_file_unique_id`);

ALTER TABLE `message_revisions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_message_revision` (`message_id`,`revision`),
  ADD KEY `idx_message_revision_created` (`created_at`),
  ADD KEY `fk_message_revision_user` (`edited_by`);

ALTER TABLE `migration_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_migration_log_key` (`migration_key`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_read` (`is_read`),
  ADD KEY `idx_created` (`created_at`);

ALTER TABLE `notification_queue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notification_idempotency` (`idempotency_key`),
  ADD KEY `idx_notification_claim` (`status`,`next_attempt_at`,`priority`,`id`),
  ADD KEY `idx_notification_locked` (`status`,`locked_at`),
  ADD KEY `idx_notification_recipient` (`recipient_type`,`recipient_id`,`created_at`),
  ADD KEY `idx_notification_order` (`order_id`,`status`,`created_at`),
  ADD KEY `idx_notification_message` (`site_message_id`,`event_type`);

ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_orders_lead` (`lead_id`),
  ADD KEY `idx_orders_client` (`client_id`),
  ADD KEY `idx_orders_manager` (`manager_id`),
  ADD KEY `idx_orders_organizer` (`organizer_id`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_event_date` (`event_date`),
  ADD KEY `fk_orders_lead` (`lead_id`),
  ADD KEY `fk_orders_event` (`event_id`);

ALTER TABLE `order_checklist_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_checklist_order` (`order_id`,`sort_order`,`id`),
  ADD KEY `idx_order_checklist_done_by` (`done_by`),
  ADD KEY `fk_order_checklist_creator` (`created_by`);

ALTER TABLE `order_concepts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_concepts_order` (`order_id`),
  ADD KEY `fk_order_concepts_user` (`created_by`);

ALTER TABLE `order_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_order_document_number` (`document_number`),
  ADD UNIQUE KEY `uq_order_document_token` (`public_token_hash`),
  ADD KEY `idx_order_documents_order` (`order_id`,`status`),
  ADD KEY `fk_order_documents_template` (`template_id`),
  ADD KEY `fk_order_documents_user` (`created_by`);

ALTER TABLE `order_document_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_document_version` (`document_id`,`version_no`),
  ADD KEY `fk_document_versions_user` (`created_by`);

ALTER TABLE `order_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_expenses_order` (`order_id`,`category`),
  ADD KEY `idx_order_expenses_date` (`expense_date`),
  ADD KEY `fk_order_expenses_user` (`created_by`);

ALTER TABLE `order_finance`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `fk_order_finance_user` (`updated_by`);

ALTER TABLE `order_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_materials_order` (`order_id`),
  ADD KEY `idx_order_materials_visibility` (`visibility`),
  ADD KEY `fk_order_materials_user` (`uploaded_by`);

ALTER TABLE `order_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_payments_order` (`order_id`,`status`),
  ADD KEY `idx_order_payments_due` (`due_date`,`status`),
  ADD KEY `fk_order_payments_user` (`created_by`);

ALTER TABLE `order_role_requirements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_role_requirement` (`order_id`,`role_key`);

ALTER TABLE `order_scheduled_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_scheduled_notification_due` (`status`,`send_at`),
  ADD KEY `idx_scheduled_notification_order` (`order_id`,`event_type`),
  ADD KEY `fk_scheduled_notification_user` (`recipient_id`);

ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status_history_order` (`order_id`),
  ADD KEY `idx_status_history_created` (`created_at`),
  ADD KEY `fk_status_history_user` (`changed_by`);

ALTER TABLE `order_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_tasks_order` (`order_id`),
  ADD KEY `idx_order_tasks_assigned` (`assigned_to`),
  ADD KEY `idx_order_tasks_status` (`status`),
  ADD KEY `fk_order_tasks_created_by` (`created_by`);

ALTER TABLE `order_team`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_order_worker` (`order_id`,`worker_id`),
  ADD KEY `idx_order_team_order` (`order_id`),
  ADD KEY `idx_order_team_worker` (`worker_id`),
  ADD KEY `fk_order_team_event_staff` (`event_staff_id`);

ALTER TABLE `order_template_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_template_application` (`order_id`,`template_id`),
  ADD KEY `idx_template_application_template` (`template_id`),
  ADD KEY `fk_template_application_user` (`applied_by`);

ALTER TABLE `order_timeline_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_timeline_order_sort` (`order_id`,`sort_order`,`starts_at`),
  ADD KEY `idx_timeline_responsible` (`responsible_id`,`status`),
  ADD KEY `fk_timeline_completed_by` (`completed_by`),
  ADD KEY `fk_timeline_created_by` (`created_by`);

ALTER TABLE `password_reset_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_password_reset_request_identifier` (`identifier_hash`,`created_at`),
  ADD KEY `idx_password_reset_request_ip` (`ip_hash`,`created_at`),
  ADD KEY `idx_password_reset_request_user` (`user_id`);

ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_password_reset_token_hash` (`token_hash`),
  ADD KEY `idx_password_reset_user_active` (`user_id`,`used_at`,`expires_at`);

ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event` (`event_id`),
  ADD KEY `idx_worker` (`worker_id`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `portfolio_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_portfolio_images_project` (`project_id`);

ALTER TABLE `portfolio_projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_portfolio_slug` (`slug`),
  ADD KEY `idx_portfolio_published` (`is_published`);

ALTER TABLE `public_team_profiles`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_public_team_published_sort` (`is_published`,`sort_order`,`user_id`),
  ADD KEY `idx_public_team_updated_by` (`updated_by`);

ALTER TABLE `quiz_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_quiz_lead` (`lead_id`);

ALTER TABLE `quiz_drafts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_quiz_draft_token` (`token_hash`),
  ADD KEY `idx_quiz_draft_session` (`session_key`,`expires_at`),
  ADD KEY `idx_quiz_draft_expiry` (`expires_at`,`submitted_at`),
  ADD KEY `fk_quiz_draft_user` (`user_id`);

ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_reviews_order` (`order_id`),
  ADD UNIQUE KEY `uq_reviews_token` (`token_hash`),
  ADD KEY `idx_reviews_published` (`is_published`),
  ADD KEY `idx_reviews_status` (`status`,`created_at`),
  ADD KEY `fk_reviews_moderator` (`moderated_by`),
  ADD KEY `idx_reviews_client` (`client_id`,`status`);

ALTER TABLE `review_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_review_files_review` (`review_id`);

ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_service_slug` (`slug`),
  ADD KEY `idx_services_active` (`is_active`);

ALTER TABLE `service_packages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_package_slug` (`slug`),
  ADD KEY `idx_packages_active` (`is_active`);

ALTER TABLE `telegram_auth_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_telegram_auth_token` (`token_hash`),
  ADD KEY `idx_telegram_auth_browser` (`browser_session_hash`,`purpose`,`expires_at`,`consumed_at`),
  ADD KEY `idx_telegram_auth_ip` (`request_ip_hash`,`created_at`),
  ADD KEY `idx_telegram_auth_user` (`user_id`,`purpose`,`created_at`);

ALTER TABLE `telegram_bot_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_telegram_bot_session_user_chat` (`telegram_user_id`,`telegram_chat_id`),
  ADD KEY `idx_telegram_bot_session_expiry` (`expires_at`);

ALTER TABLE `telegram_chat_bindings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_telegram_binding_site_chat` (`site_chat_id`),
  ADD UNIQUE KEY `uq_telegram_binding_chat` (`telegram_chat_id`),
  ADD UNIQUE KEY `uq_telegram_binding_order_kind` (`order_id`,`chat_kind`),
  ADD KEY `idx_telegram_binding_status` (`status`,`updated_at`),
  ADD KEY `fk_telegram_binding_pool` (`group_pool_id`);

ALTER TABLE `telegram_group_memberships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_telegram_membership_user` (`binding_id`,`user_id`),
  ADD UNIQUE KEY `uq_telegram_membership_identity` (`binding_id`,`telegram_user_id`),
  ADD KEY `idx_telegram_membership_status` (`status`,`updated_at`),
  ADD KEY `fk_telegram_membership_user` (`user_id`),
  ADD KEY `fk_telegram_membership_invite` (`invite_id`);

ALTER TABLE `telegram_group_pool`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_telegram_group_pool_chat` (`telegram_chat_id`),
  ADD UNIQUE KEY `uq_telegram_group_pool_order_kind` (`bound_order_id`,`kind`),
  ADD KEY `idx_telegram_group_pool_claim` (`kind`,`status`,`id`);

ALTER TABLE `telegram_inbound_events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_telegram_inbound_idempotency` (`idempotency_key`),
  ADD KEY `idx_telegram_inbound_claim` (`status`,`next_attempt_at`,`id`),
  ADD KEY `idx_telegram_inbound_remote` (`telegram_chat_id`,`telegram_message_id`),
  ADD KEY `idx_telegram_inbound_created` (`created_at`);

ALTER TABLE `telegram_invites`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_telegram_invite_binding_user_status` (`binding_id`,`user_id`,`status`),
  ADD KEY `idx_telegram_invite_expiry` (`status`,`expires_at`),
  ADD KEY `idx_telegram_invite_telegram_user` (`telegram_user_id`,`status`),
  ADD KEY `fk_telegram_invite_user` (`user_id`);

ALTER TABLE `telegram_link_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_telegram_link_token` (`token_hash`),
  ADD KEY `idx_telegram_link_user` (`user_id`,`expires_at`,`used_at`);

ALTER TABLE `telegram_message_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_telegram_message_remote` (`telegram_chat_id`,`telegram_message_id`),
  ADD UNIQUE KEY `uq_telegram_message_site_binding` (`site_message_id`,`binding_id`),
  ADD KEY `idx_telegram_message_sync` (`sync_status`,`updated_at`),
  ADD KEY `fk_telegram_message_chat` (`site_chat_id`),
  ADD KEY `fk_telegram_message_binding` (`binding_id`);

ALTER TABLE `telegram_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_telegram_status` (`status`),
  ADD KEY `idx_telegram_type` (`type`);

ALTER TABLE `telegram_rate_limits`
  ADD PRIMARY KEY (`scope_key`),
  ADD KEY `idx_telegram_rate_blocked` (`blocked_until`);

ALTER TABLE `telegram_service_nonces`
  ADD PRIMARY KEY (`nonce_hash`),
  ADD KEY `idx_telegram_nonce_expiry` (`expires_at`);

ALTER TABLE `telegram_sync_operations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_telegram_sync_idempotency` (`idempotency_key`),
  ADD KEY `idx_telegram_sync_status` (`status`,`updated_at`),
  ADD KEY `idx_telegram_sync_message` (`site_message_id`,`operation_type`),
  ADD KEY `fk_telegram_sync_order` (`order_id`),
  ADD KEY `fk_telegram_sync_chat` (`site_chat_id`),
  ADD KEY `fk_telegram_sync_binding` (`binding_id`);

ALTER TABLE `telegram_updates`
  ADD PRIMARY KEY (`update_id`),
  ADD KEY `idx_telegram_update_processed` (`processed_at`);

ALTER TABLE `timeline_checklist_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_timeline_checklist` (`timeline_item_id`,`sort_order`),
  ADD KEY `fk_timeline_checklist_user` (`done_by`);

ALTER TABLE `timeline_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_timeline_files_item` (`timeline_item_id`),
  ADD KEY `fk_timeline_files_user` (`uploaded_by`);

ALTER TABLE `timeline_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_timeline_history_item` (`timeline_item_id`,`created_at`),
  ADD KEY `fk_timeline_history_user` (`changed_by`);

ALTER TABLE `timeline_participants`
  ADD PRIMARY KEY (`timeline_item_id`,`user_id`),
  ADD KEY `idx_timeline_participant_user` (`user_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_telegram` (`telegram_user_id`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_active` (`is_active`);

ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_sessions_hash` (`session_hash`),
  ADD KEY `idx_user_sessions_user` (`user_id`,`revoked_at`),
  ADD KEY `idx_user_sessions_activity` (`last_activity_at`);

ALTER TABLE `worker_availability`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_worker_date` (`worker_id`,`unavailable_date`),
  ADD KEY `idx_worker` (`worker_id`),
  ADD KEY `idx_date` (`unavailable_date`);

ALTER TABLE `worker_profiles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_specialty` (`specialty`);

ALTER TABLE `worker_statistics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_worker_stats` (`worker_id`);

ALTER TABLE `action_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `audit_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=263;

ALTER TABLE `calendar_entries`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `calendar_entry_participants`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `candidate_applications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `candidate_application_files`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `chats`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

ALTER TABLE `chat_participants`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

ALTER TABLE `client_profiles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `concept_approvals`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `concept_comments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `concept_files`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `concept_versions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `document_comments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `document_confirmations`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `document_templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

ALTER TABLE `events`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `event_media`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `event_staff`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `event_templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `leads`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `lead_call_checklist_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `lead_files`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

ALTER TABLE `lead_follow_ups`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `lead_status_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

ALTER TABLE `login_attempts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

ALTER TABLE `login_challenges`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

ALTER TABLE `manager_templates`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

ALTER TABLE `message_attachments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `message_revisions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `migration_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

ALTER TABLE `notification_queue`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=467;

ALTER TABLE `orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `order_checklist_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_concepts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_documents`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_document_versions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_expenses`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_materials`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_role_requirements`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_scheduled_notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_status_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `order_tasks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_team`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `order_template_applications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_timeline_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `password_reset_requests`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `password_reset_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `payments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `portfolio_images`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `portfolio_projects`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `quiz_answers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

ALTER TABLE `quiz_drafts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `reviews`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `review_files`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `services`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `service_packages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `telegram_auth_requests`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `telegram_bot_sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

ALTER TABLE `telegram_chat_bindings`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `telegram_group_memberships`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `telegram_group_pool`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

ALTER TABLE `telegram_inbound_events`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `telegram_invites`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `telegram_link_tokens`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `telegram_message_map`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `telegram_sync_operations`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

ALTER TABLE `timeline_checklist_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `timeline_files`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `timeline_history`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

ALTER TABLE `user_sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

ALTER TABLE `worker_availability`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `worker_profiles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `worker_statistics`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `action_logs`
  ADD CONSTRAINT `action_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `app_settings`
  ADD CONSTRAINT `fk_app_setting_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `calendar_entries`
  ADD CONSTRAINT `fk_calendar_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_calendar_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_calendar_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `calendar_entry_participants`
  ADD CONSTRAINT `fk_calendar_participant_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_calendar_participant_entry` FOREIGN KEY (`calendar_entry_id`) REFERENCES `calendar_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_calendar_participant_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `candidate_application_files`
  ADD CONSTRAINT `fk_candidate_file_application` FOREIGN KEY (`candidate_application_id`) REFERENCES `candidate_applications` (`id`) ON DELETE CASCADE;

ALTER TABLE `chats`
  ADD CONSTRAINT `chats_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

ALTER TABLE `chat_participants`
  ADD CONSTRAINT `chat_participants_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `lead_call_checklist_items`
  ADD CONSTRAINT `fk_lead_call_checklist_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lead_call_checklist_user` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `lead_follow_ups`
  ADD CONSTRAINT `fk_lead_follow_up_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_lead_follow_up_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `manager_templates`
  ADD CONSTRAINT `fk_manager_templates_user` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `message_attachments`
  ADD CONSTRAINT `fk_message_attachment_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

ALTER TABLE `message_revisions`
  ADD CONSTRAINT `fk_message_revision_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_message_revision_user` FOREIGN KEY (`edited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `password_reset_requests`
  ADD CONSTRAINT `fk_password_reset_request_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `public_team_profiles`
  ADD CONSTRAINT `fk_public_team_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_public_team_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `telegram_auth_requests`
  ADD CONSTRAINT `fk_telegram_auth_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `telegram_chat_bindings`
  ADD CONSTRAINT `fk_telegram_binding_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_telegram_binding_pool` FOREIGN KEY (`group_pool_id`) REFERENCES `telegram_group_pool` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_telegram_binding_site_chat` FOREIGN KEY (`site_chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE;

ALTER TABLE `telegram_group_memberships`
  ADD CONSTRAINT `fk_telegram_membership_binding` FOREIGN KEY (`binding_id`) REFERENCES `telegram_chat_bindings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_telegram_membership_invite` FOREIGN KEY (`invite_id`) REFERENCES `telegram_invites` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_telegram_membership_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `telegram_group_pool`
  ADD CONSTRAINT `fk_telegram_group_pool_order` FOREIGN KEY (`bound_order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL;

ALTER TABLE `telegram_invites`
  ADD CONSTRAINT `fk_telegram_invite_binding` FOREIGN KEY (`binding_id`) REFERENCES `telegram_chat_bindings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_telegram_invite_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `telegram_message_map`
  ADD CONSTRAINT `fk_telegram_message_binding` FOREIGN KEY (`binding_id`) REFERENCES `telegram_chat_bindings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_telegram_message_chat` FOREIGN KEY (`site_chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_telegram_message_site` FOREIGN KEY (`site_message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

ALTER TABLE `telegram_sync_operations`
  ADD CONSTRAINT `fk_telegram_sync_binding` FOREIGN KEY (`binding_id`) REFERENCES `telegram_chat_bindings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_telegram_sync_chat` FOREIGN KEY (`site_chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_telegram_sync_message` FOREIGN KEY (`site_message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_telegram_sync_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;
