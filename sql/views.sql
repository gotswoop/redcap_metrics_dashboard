-- REDCap Metrics Dashboard
-- Required views for redcap_metrics_dashboard/generate.php

DROP VIEW IF EXISTS `view_redcap_metrics_projects_base`;
DROP VIEW IF EXISTS `view_redcap_metrics_projects`;
DROP VIEW IF EXISTS `view_redcap_metrics_users`;

CREATE VIEW `view_redcap_metrics_projects_base` AS
SELECT
    p.`project_id`        AS `project_id`,
    p.`project_name`      AS `project_name`,
    p.`app_title`         AS `app_title`,
    p.`status`            AS `status`,
    p.`purpose`           AS `purpose`,
    p.`created_by`        AS `created_by`,
    p.`creation_time`     AS `creation_time`,
    p.`production_time`   AS `production_time`,
    p.`inactive_time`     AS `inactive_time`,
    p.`completed_time`    AS `completed_time`,
    p.`last_logged_event` AS `last_logged_event`,
    GREATEST(
        p.`creation_time`,
        p.`production_time`,
        p.`inactive_time`,
        p.`completed_time`,
        p.`last_logged_event`
    ) AS `last_updated`
FROM `redcap_projects` p;

CREATE VIEW `view_redcap_metrics_projects` AS
SELECT
    -- Core identifiers
    b.`project_id`,
    b.`project_name`,
    b.`app_title` AS `project_title`,

    -- Status
    b.`status`,
    CASE b.`status`
        WHEN 0 THEN 'development'
        WHEN 1 THEN 'production'
        WHEN 2 THEN 'inactive'
        WHEN 3 THEN 'completed'
        ELSE 'unknown'
    END AS `status_label`,

    -- Purpose
    b.`purpose`,
    CASE b.`purpose`
        WHEN 0 THEN 'Practice / Just for fun'
        WHEN 1 THEN 'Operational support'
        WHEN 2 THEN 'Research'
        WHEN 3 THEN 'Quality improvement'
        ELSE 'Other'
    END AS `purpose_label`,
    p.`purpose_other`,

    -- Ownership
    b.`created_by` AS `created_by_user_id`,
    ui.`username` AS `created_by_username`,
    p.`project_pi_username`,
    p.`project_pi_email`,

    -- Project configuration
    (p.`is_child_of` IS NOT NULL) AS `is_longitudinal`,
    p.`surveys_enabled`,
    p.`repeatforms` AS `repeating_instruments_enabled`,
    p.`randomization` AS `randomization_enabled`,
    p.`data_locked`,
    p.`mycap_enabled`,
    p.`datamart_enabled`,

    -- Counts
    COALESCE(uc.`user_count`, 0) AS `user_count`,
    COALESCE(auc.`api_user_count`, 0) AS `api_user_count`,
    COALESCE(rc.`record_count`, 0) AS `record_count`,
    COALESCE(ic.`instrument_count`, 0) AS `instrument_count`,
    COALESCE(ec.`event_count`, 0) AS `event_count`,
    COALESCE(ac.`arm_count`, 0) AS `arm_count`,

    -- Timestamps
    b.`creation_time` AS `project_created_at`,
    b.`production_time` AS `moved_to_production_at`,
    b.`inactive_time` AS `inactive_at`,
    b.`completed_time` AS `completed_at`,
    b.`last_logged_event` AS `last_logged_event_at`,
    COALESCE(
        GREATEST(
            COALESCE(b.`last_updated`, '1000-01-01 00:00:00'),
            COALESCE(rc.`time_of_count`, '1000-01-01 00:00:00')
        ),
        b.`creation_time`
    ) AS `last_updated`

FROM `view_redcap_metrics_projects_base` b

JOIN `redcap_projects` p
    ON p.`project_id` = b.`project_id`

LEFT JOIN `redcap_user_information` ui
    ON ui.`ui_id` = b.`created_by`

LEFT JOIN (
    SELECT
        `project_id`,
        COUNT(DISTINCT `username`) AS `user_count`
    FROM `redcap_user_rights`
    GROUP BY `project_id`
) uc
    ON uc.`project_id` = b.`project_id`

LEFT JOIN (
    SELECT
        `project_id`,
        COUNT(DISTINCT `username`) AS `api_user_count`
    FROM `redcap_user_rights`
    WHERE `api_export` = 1
       OR `api_import` = 1
       OR `api_modules` = 1
    GROUP BY `project_id`
) auc
    ON auc.`project_id` = b.`project_id`

LEFT JOIN `redcap_record_counts` rc
    ON rc.`project_id` = b.`project_id`

LEFT JOIN (
    SELECT
        `project_id`,
        COUNT(DISTINCT `form_name`) AS `instrument_count`
    FROM `redcap_metadata`
    GROUP BY `project_id`
) ic
    ON ic.`project_id` = b.`project_id`

LEFT JOIN (
    SELECT
        `project_id`,
        COUNT(*) AS `arm_count`
    FROM `redcap_events_arms`
    GROUP BY `project_id`
) ac
    ON ac.`project_id` = b.`project_id`

LEFT JOIN (
    SELECT
        a.`project_id`,
        COUNT(DISTINCT e.`event_id`) AS `event_count`
    FROM `redcap_events_arms` a
    JOIN `redcap_events_metadata` e
        ON e.`arm_id` = a.`arm_id`
    GROUP BY a.`project_id`
) ec
    ON ec.`project_id` = b.`project_id`;

CREATE VIEW `view_redcap_metrics_users` AS
SELECT
    u.`ui_id` AS `user_id`,
    u.`username` AS `username`,
    CONCAT_WS(' ', u.`user_firstname`, u.`user_lastname`) AS `full_name`,
    u.`user_email` AS `primary_email`,
    (u.`email_verify_code` IS NULL) AS `primary_email_verified`,
    u.`user_email2` AS `secondary_email`,
    u.`user_email3` AS `tertiary_email`,
    u.`user_inst_id` AS `institution_id`,
    u.`allow_create_db` AS `can_create_projects`,
    u.`super_user` AS `is_redcap_admin`,
    u.`account_manager` AS `is_account_manager`,
    u.`access_admin_dashboards` AS `access_admin_dashboards`,
    u.`display_on_email_users` AS `display_on_email_users`,
    (u.`user_suspended_time` IS NOT NULL) AS `is_suspended`,
    u.`user_suspended_time` AS `suspension_at`,
    u.`user_expiration` AS `expiration_at`,
    u.`user_creation` AS `account_created_at`,
    u.`user_firstvisit` AS `first_login_at`,
    u.`user_lastlogin` AS `last_login_at`,
    u.`user_firstactivity` AS `first_activity_at`,
    u.`user_lastactivity` AS `last_activity_at`,

    COUNT(DISTINCT ur.`project_id`) AS `project_count`,
    SUM(p.`status` = 1) AS `production_project_count`,

    (u.`api_token` IS NOT NULL) AS `has_system_api_token`,
    MAX(ur.`api_export` = 1) AS `has_project_api_access`,
    SUM(ur.`api_export` = 1) AS `api_project_count`,

    GREATEST(
        u.`user_creation`,
        u.`user_lastlogin`,
        u.`user_lastactivity`,
        u.`user_suspended_time`
    ) AS `last_updated`

FROM `redcap_user_information` u
LEFT JOIN `redcap_user_rights` ur
    ON ur.`username` = u.`username`
LEFT JOIN `redcap_projects` p
    ON p.`project_id` = ur.`project_id`

GROUP BY
    u.`ui_id`,
    u.`username`,
    u.`user_firstname`,
    u.`user_lastname`,
    u.`user_email`,
    u.`email_verify_code`,
    u.`user_email2`,
    u.`user_email3`,
    u.`user_inst_id`,
    u.`allow_create_db`,
    u.`super_user`,
    u.`account_manager`,
    u.`access_admin_dashboards`,
    u.`display_on_email_users`,
    u.`user_suspended_time`,
    u.`user_expiration`,
    u.`user_creation`,
    u.`user_firstvisit`,
    u.`user_lastlogin`,
    u.`user_firstactivity`,
    u.`user_lastactivity`,
    u.`api_token`;
