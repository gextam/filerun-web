<?php
declare(strict_types=1);

/**
 * Database schema and migrations. Every statement is idempotent so the
 * installer and the updater can both call Schema::migrate().
 */
final class Schema
{
    public const VERSION = 1;

    public static function statements(): array
    {
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS {{settings}} (
                `key` VARCHAR(100) NOT NULL PRIMARY KEY,
                `value` LONGTEXT NULL,
                `updated_at` DATETIME NULL
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{users}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(100) NOT NULL,
                `email` VARCHAR(255) NULL,
                `password_hash` VARCHAR(255) NULL,
                `name` VARCHAR(200) NOT NULL DEFAULT '',
                `role` ENUM('superuser','admin','user','guest') NOT NULL DEFAULT 'user',
                `created_by` INT UNSIGNED NULL,
                `home_path` VARCHAR(500) NOT NULL DEFAULT '',
                `quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `traffic_quota_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `max_upload_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `allowed_extensions` VARCHAR(1000) NOT NULL DEFAULT '',
                `denied_extensions` VARCHAR(1000) NOT NULL DEFAULT '',
                `expires_at` DATETIME NULL,
                `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `deactivated_reason` VARCHAR(255) NULL,
                `ip_rules` TEXT NULL,
                `perms` TEXT NULL,
                `prefs` TEXT NULL,
                `two_factor_secret` VARCHAR(64) NULL,
                `two_factor_email` TINYINT(1) NOT NULL DEFAULT 1,
                `recovery_codes` TEXT NULL,
                `failed_2fa` INT NOT NULL DEFAULT 0,
                `oidc_subject` VARCHAR(255) NULL,
                `avatar` VARCHAR(255) NULL,
                `language` VARCHAR(10) NULL,
                `used_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `used_calc_at` DATETIME NULL,
                `last_login_at` DATETIME NULL,
                `last_activity_at` DATETIME NULL,
                `password_changed_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NULL,
                UNIQUE KEY `uq_username` (`username`),
                KEY `ix_email` (`email`),
                KEY `ix_created_by` (`created_by`),
                KEY `ix_role` (`role`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{groups}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(150) NOT NULL,
                `description` VARCHAR(500) NOT NULL DEFAULT '',
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL,
                UNIQUE KEY `uq_name` (`name`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{group_members}} (
                `group_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `added_at` DATETIME NOT NULL,
                PRIMARY KEY (`group_id`,`user_id`),
                KEY `ix_user` (`user_id`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{sessions}} (
                `id` CHAR(64) NOT NULL PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `ip` VARCHAR(45) NOT NULL DEFAULT '',
                `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
                `client` VARCHAR(100) NOT NULL DEFAULT '',
                `remember` TINYINT(1) NOT NULL DEFAULT 0,
                `pending_2fa` TINYINT(1) NOT NULL DEFAULT 0,
                `data` TEXT NULL,
                `created_at` DATETIME NOT NULL,
                `last_seen_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                KEY `ix_user` (`user_id`),
                KEY `ix_expires` (`expires_at`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{app_passwords}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(150) NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `prefix` VARCHAR(12) NOT NULL DEFAULT '',
                `scope` ENUM('all','webdav','api') NOT NULL DEFAULT 'all',
                `last_used_at` DATETIME NULL,
                `last_ip` VARCHAR(45) NULL,
                `created_at` DATETIME NOT NULL,
                UNIQUE KEY `uq_token` (`token_hash`),
                KEY `ix_user` (`user_id`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{passkeys}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `credential_id` VARBINARY(1024) NOT NULL,
                `public_key` TEXT NOT NULL,
                `sign_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(150) NOT NULL DEFAULT 'Passkey',
                `transports` VARCHAR(200) NULL,
                `aaguid` VARCHAR(64) NULL,
                `created_at` DATETIME NOT NULL,
                `last_used_at` DATETIME NULL,
                KEY `ix_user` (`user_id`),
                KEY `ix_cred` (`credential_id`(255))
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{login_attempts}} (
                `key` VARCHAR(200) NOT NULL PRIMARY KEY,
                `attempts` INT NOT NULL DEFAULT 0,
                `last_attempt_at` DATETIME NOT NULL,
                `blocked_until` DATETIME NULL
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{tokens}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `type` VARCHAR(40) NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `data` TEXT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                UNIQUE KEY `uq_token` (`token_hash`),
                KEY `ix_user_type` (`user_id`,`type`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{file_index}} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `owner_id` INT UNSIGNED NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `parent_hash` CHAR(40) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `is_dir` TINYINT(1) NOT NULL DEFAULT 0,
                `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `mtime` INT UNSIGNED NOT NULL DEFAULT 0,
                `mime` VARCHAR(150) NOT NULL DEFAULT '',
                `kind` VARCHAR(20) NOT NULL DEFAULT 'other',
                `indexed_at` DATETIME NOT NULL,
                UNIQUE KEY `uq_owner_path` (`owner_id`,`path_hash`),
                KEY `ix_parent` (`owner_id`,`parent_hash`),
                KEY `ix_name` (`owner_id`,`name`),
                KEY `ix_mtime` (`owner_id`,`mtime`),
                KEY `ix_kind` (`owner_id`,`kind`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{file_meta}} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `owner_id` INT UNSIGNED NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `is_dir` TINYINT(1) NOT NULL DEFAULT 0,
                `label` VARCHAR(20) NULL,
                `rating` TINYINT NULL,
                `description` TEXT NULL,
                `custom` TEXT NULL,
                `exif` TEXT NULL,
                `locked_by` INT UNSIGNED NULL,
                `locked_at` DATETIME NULL,
                `lock_token` VARCHAR(100) NULL,
                `created_by` INT UNSIGNED NULL,
                `downloads` INT UNSIGNED NOT NULL DEFAULT 0,
                `views` INT UNSIGNED NOT NULL DEFAULT 0,
                `updated_at` DATETIME NULL,
                UNIQUE KEY `uq_owner_path` (`owner_id`,`path_hash`),
                KEY `ix_label` (`owner_id`,`label`),
                KEY `ix_locked` (`locked_by`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{file_text}} (
                `owner_id` INT UNSIGNED NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `content` LONGTEXT NULL,
                `source` VARCHAR(20) NOT NULL DEFAULT 'text',
                `indexed_at` DATETIME NOT NULL,
                `mtime` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`owner_id`,`path_hash`),
                FULLTEXT KEY `ft_content` (`content`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{tags}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `color` VARCHAR(20) NULL,
                `created_at` DATETIME NOT NULL,
                UNIQUE KEY `uq_user_name` (`user_id`,`name`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{file_tags}} (
                `tag_id` INT UNSIGNED NOT NULL,
                `owner_id` INT UNSIGNED NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`tag_id`,`owner_id`,`path_hash`),
                KEY `ix_file` (`owner_id`,`path_hash`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{stars}} (
                `user_id` INT UNSIGNED NOT NULL,
                `owner_id` INT UNSIGNED NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `is_dir` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`user_id`,`owner_id`,`path_hash`),
                KEY `ix_file` (`owner_id`,`path_hash`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{comments}} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `owner_id` INT UNSIGNED NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `user_id` INT UNSIGNED NULL,
                `user_name` VARCHAR(200) NOT NULL DEFAULT '',
                `body` TEXT NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NULL,
                KEY `ix_file` (`owner_id`,`path_hash`,`created_at`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{versions}} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `owner_id` INT UNSIGNED NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `stored` VARCHAR(255) NOT NULL,
                `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `mtime` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_by` INT UNSIGNED NULL,
                `created_by_name` VARCHAR(200) NOT NULL DEFAULT '',
                `reason` VARCHAR(40) NOT NULL DEFAULT 'overwrite',
                `created_at` DATETIME NOT NULL,
                KEY `ix_file` (`owner_id`,`path_hash`,`created_at`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{trash}} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `owner_id` INT UNSIGNED NOT NULL,
                `original_path` VARCHAR(2000) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `stored` VARCHAR(255) NOT NULL,
                `is_dir` TINYINT(1) NOT NULL DEFAULT 0,
                `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `deleted_by` INT UNSIGNED NULL,
                `deleted_by_name` VARCHAR(200) NOT NULL DEFAULT '',
                `deleted_at` DATETIME NOT NULL,
                KEY `ix_owner` (`owner_id`,`deleted_at`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{shares}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `owner_id` INT UNSIGNED NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `is_dir` TINYINT(1) NOT NULL DEFAULT 1,
                `target_type` ENUM('user','group') NOT NULL,
                `target_id` INT UNSIGNED NOT NULL,
                `role` VARCHAR(30) NOT NULL DEFAULT 'viewer',
                `alias` VARCHAR(255) NULL,
                `notify` TINYINT(1) NOT NULL DEFAULT 0,
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL,
                `expires_at` DATETIME NULL,
                UNIQUE KEY `uq_share` (`owner_id`,`path_hash`,`target_type`,`target_id`),
                KEY `ix_target` (`target_type`,`target_id`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{weblinks}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `hash` VARCHAR(64) NOT NULL,
                `owner_id` INT UNSIGNED NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `is_dir` TINYINT(1) NOT NULL DEFAULT 0,
                `title` VARCHAR(255) NULL,
                `message` TEXT NULL,
                `role` VARCHAR(30) NOT NULL DEFAULT 'viewer',
                `password_hash` VARCHAR(255) NULL,
                `expires_at` DATETIME NULL,
                `max_downloads` INT UNSIGNED NOT NULL DEFAULT 0,
                `downloads` INT UNSIGNED NOT NULL DEFAULT 0,
                `views` INT UNSIGNED NOT NULL DEFAULT 0,
                `terms` TEXT NULL,
                `notify` TINYINT(1) NOT NULL DEFAULT 0,
                `allow_preview_only` TINYINT(1) NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL,
                `last_access_at` DATETIME NULL,
                UNIQUE KEY `uq_hash` (`hash`),
                KEY `ix_owner` (`owner_id`,`path_hash`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{follows}} (
                `user_id` INT UNSIGNED NOT NULL,
                `owner_id` INT UNSIGNED NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `events` VARCHAR(200) NOT NULL DEFAULT 'all',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`user_id`,`owner_id`,`path_hash`),
                KEY `ix_file` (`owner_id`,`path_hash`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{notifications}} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `type` VARCHAR(40) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `body` TEXT NULL,
                `data` TEXT NULL,
                `read_at` DATETIME NULL,
                `emailed` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                KEY `ix_user` (`user_id`,`read_at`,`created_at`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{activity}} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `user_name` VARCHAR(200) NOT NULL DEFAULT '',
                `action` VARCHAR(40) NOT NULL,
                `status` ENUM('ok','failed') NOT NULL DEFAULT 'ok',
                `reason` VARCHAR(255) NULL,
                `owner_id` INT UNSIGNED NULL,
                `path` VARCHAR(2000) NULL,
                `path_hash` CHAR(40) NULL,
                `path2` VARCHAR(2000) NULL,
                `is_dir` TINYINT(1) NOT NULL DEFAULT 0,
                `client` VARCHAR(20) NOT NULL DEFAULT 'web',
                `ip` VARCHAR(45) NOT NULL DEFAULT '',
                `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
                `details` TEXT NULL,
                `created_at` DATETIME NOT NULL,
                KEY `ix_user` (`user_id`,`created_at`),
                KEY `ix_file` (`owner_id`,`path_hash`,`created_at`),
                KEY `ix_action` (`action`,`created_at`),
                KEY `ix_created` (`created_at`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{metadata_fields}} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `key` VARCHAR(60) NOT NULL,
                `type` ENUM('text','textarea','number','date','select','boolean','url') NOT NULL DEFAULT 'text',
                `options` TEXT NULL,
                `file_types` VARCHAR(500) NOT NULL DEFAULT '',
                `required` TINYINT(1) NOT NULL DEFAULT 0,
                `show_column` TINYINT(1) NOT NULL DEFAULT 0,
                `searchable` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                UNIQUE KEY `uq_key` (`key`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{uploads}} (
                `id` CHAR(32) NOT NULL PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `link_id` INT UNSIGNED NULL,
                `owner_id` INT UNSIGNED NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `received` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `mtime` INT UNSIGNED NULL,
                `overwrite` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                KEY `ix_updated` (`updated_at`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{traffic}} (
                `user_id` INT UNSIGNED NOT NULL,
                `month` CHAR(7) NOT NULL,
                `bytes_down` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `bytes_up` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`user_id`,`month`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{dav_locks}} (
                `token` VARCHAR(100) NOT NULL PRIMARY KEY,
                `owner_id` INT UNSIGNED NOT NULL,
                `path_hash` CHAR(40) NOT NULL,
                `path` VARCHAR(2000) NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `depth` TINYINT NOT NULL DEFAULT 0,
                `scope` VARCHAR(20) NOT NULL DEFAULT 'exclusive',
                `owner_info` TEXT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                KEY `ix_file` (`owner_id`,`path_hash`)
            ) $o",

            "CREATE TABLE IF NOT EXISTS {{jobs}} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `type` VARCHAR(40) NOT NULL,
                `payload` TEXT NULL,
                `attempts` INT NOT NULL DEFAULT 0,
                `run_after` DATETIME NOT NULL,
                `locked_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                KEY `ix_run` (`run_after`,`locked_at`)
            ) $o",
        ];
    }

    /** Create missing tables and apply incremental migrations. */
    public static function migrate(): void
    {
        foreach (self::statements() as $sql) {
            DB::exec($sql);
        }
        $current = 0;
        try {
            $current = (int) DB::val('SELECT `value` FROM {{settings}} WHERE `key` = ?', ['schema_version']);
        } catch (Throwable) {
        }
        // Future incremental migrations go here, guarded by $current < N.
        if ($current < self::VERSION) {
            DB::upsert('settings', ['key' => 'schema_version', 'value' => (string) self::VERSION, 'updated_at' => fr_now()], ['value', 'updated_at']);
        }
    }

    public static function tables(): array
    {
        $names = [];
        foreach (self::statements() as $sql) {
            if (preg_match('/\{\{(\w+)\}\}/', $sql, $m)) $names[] = $m[1];
        }
        return $names;
    }
}
