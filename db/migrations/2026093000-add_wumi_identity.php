<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;

/**
 * wumi 身份桥：用户表新增 wumi 公钥映射列。
 *
 * wumi 是唯一用户体系（爸爸 2026-09-30 决策）。SSPanel 不再维护独立账号密码，
 * 用户首次通过 wumi SSO 进入时按 public_key 自动开户并绑定。
 */
return new class() implements MigrationInterface {
    public function up(): int
    {
        DB::getPdo()->exec("
            ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `wumi_user_key` varchar(191) NULL DEFAULT NULL COMMENT 'wumi 用户公钥（身份桥主键）';
            ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `wumi_synced_at` int NULL DEFAULT NULL COMMENT 'wumi 身份最后同步时间';
        ");

        // 唯一索引：仅对已绑定用户保证唯一；NULL 可重复（未绑定用户不受影响）
        $indexes = DB::getPdo()->query(
            "SHOW INDEX FROM `user` WHERE Key_name = 'uniq_user_wumi_key'"
        )->fetchAll();

        if (count($indexes) === 0) {
            DB::getPdo()->exec('ALTER TABLE `user` ADD UNIQUE KEY `uniq_user_wumi_key` (`wumi_user_key`)');
        }

        return 2026093000;
    }

    public function down(): int
    {
        $indexes = DB::getPdo()->query(
            "SHOW INDEX FROM `user` WHERE Key_name = 'uniq_user_wumi_key'"
        )->fetchAll();

        if (count($indexes) > 0) {
            DB::getPdo()->exec('ALTER TABLE `user` DROP INDEX `uniq_user_wumi_key`');
        }

        DB::getPdo()->exec("
            ALTER TABLE `user` DROP COLUMN IF EXISTS `wumi_user_key`;
            ALTER TABLE `user` DROP COLUMN IF EXISTS `wumi_synced_at`;
        ");

        return 2025073100;
    }
};