<?php

use Illuminate\Database\Schema\Blueprint;
use Eloquent\Migrations\Migrations\Migration;

/**
 * 用户体系升级 —— 单家庭制（多家庭地基版）
 *
 * 见 docs/design/home-user-system.md：
 *   1. homes / home_users / invite_codes 三张新表
 *   2. 种子默认家庭 id=1「我的家」，存量用户全部归入（管理员 → owner，其余 → member）
 *   3. devices / alert_rules / alert_logs / automations /
 *      notification_channels / provision_codes 加 home_id（DEFAULT 1，存量自动归位）
 *
 * 遥测 hypertable 不加列，通过 device 归属间接隔离。
 */
return new class extends Migration
{
    /** 需要加 home_id 的存量表 */
    private const HOME_SCOPED_TABLES = [
        'devices',
        'alert_rules',
        'alert_logs',
        'automations',
        'notification_channels',
        'provision_codes',
    ];

    public function up(): void
    {
        /* ---------- 1. 新表 ---------- */

        $this->schema()->create('homes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('家庭名称');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建者用户 ID');
            $table->timestampsTz();
        });

        $this->schema()->create('home_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('home_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 20)->default('member')->comment('owner / admin / member');
            $table->timestampTz('joined_at')->useCurrent();

            $table->unique(['home_id', 'user_id']);
            $table->foreign('home_id')->references('id')->on('homes')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        $this->schema()->create('invite_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('home_id');
            $table->string('code', 16)->unique()->comment('邀请码（一次性、短时有效）');
            $table->string('role', 20)->default('member')->comment('注册后获得的家庭角色');
            $table->unsignedBigInteger('created_by')->nullable()->comment('生成者');
            $table->unsignedBigInteger('used_by')->nullable()->comment('使用者（空 = 未使用）');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('expires_at')->comment('过期时间');
            $table->timestampsTz();

            $table->foreign('home_id')->references('id')->on('homes')->onDelete('cascade');
            $table->index('expires_at');
        });

        /* ---------- 2. 种子默认家庭 + 存量用户归位 ---------- */

        $db = $this->db();
        $now = date('Y-m-d H:i:s');

        // 挑一个 owner：优先带 admin 权限角色的最早用户，兜底最早的用户
        $ownerId = $db->table('users')
            ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.permissions', 'like', '%"admin"%')
            ->orderBy('users.id')
            ->value('users.id');
        $ownerId = $ownerId ?: $db->table('users')->orderBy('id')->value('id');

        $db->table('homes')->insert([
            'id'         => 1,
            'name'       => '我的家',
            'created_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // 显式插了 id=1，PG 序列需要同步到当前最大值
        $db->unprepared("SELECT setval(pg_get_serial_sequence('homes','id'), (SELECT MAX(id) FROM homes))");

        foreach ($db->table('users')->orderBy('id')->pluck('id') as $userId) {
            $db->table('home_users')->insert([
                'home_id'   => 1,
                'user_id'   => $userId,
                'role'      => ((int)$userId === (int)$ownerId) ? 'owner' : 'member',
                'joined_at' => $now,
            ]);
        }

        /* ---------- 3. 存量表加 home_id ---------- */

        foreach (self::HOME_SCOPED_TABLES as $tableName) {
            $this->schema()->table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('home_id')->default(1)->comment('归属家庭');
                $table->index('home_id');
                $table->foreign('home_id')->references('id')->on('homes');
            });
        }
    }

    public function down(): void
    {
        foreach (self::HOME_SCOPED_TABLES as $tableName) {
            $this->schema()->table($tableName, function (Blueprint $table) {
                $table->dropForeign(['home_id']);
                $table->dropColumn('home_id');
            });
        }
        $this->schema()->dropIfExists('invite_codes');
        $this->schema()->dropIfExists('home_users');
        $this->schema()->dropIfExists('homes');
    }
};
