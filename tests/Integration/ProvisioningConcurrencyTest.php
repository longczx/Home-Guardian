<?php
namespace Tests\Integration;

use app\model\{Device, ProvisionCode};
use app\service\ProvisioningService;
use app\exception\BusinessException;
use PHPUnit\Framework\TestCase;

class ProvisioningConcurrencyTest extends TestCase
{
    public function test_postgres_serializes_pairing_code_consumption_and_migration_rolls_back(): void
    {
        if (!getenv('TEST_PGSQL_HOST') || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires isolated PostgreSQL and pcntl');
        $capsule=$GLOBALS['test_capsule'];$manager=$capsule->getDatabaseManager();$previous=$manager->getDefaultConnection();
        $schema='hg_pairing_'.bin2hex(random_bytes(5));$name='pairing_concurrency';
        $capsule->addConnection(['driver'=>'pgsql','host'=>getenv('TEST_PGSQL_HOST'),'database'=>'guardian_test',
            'username'=>'guardian_test','password'=>'guardian_test','charset'=>'utf8','search_path'=>$schema.',public'], $name);
        $db=$capsule->getConnection($name);$db->statement("CREATE SCHEMA {$schema}");$manager->setDefaultConnection($name);
        $dir=sys_get_temp_dir().'/'.$schema;mkdir($dir,0700);
        try {
            $db->getSchemaBuilder()->create('provision_codes', function ($t) {
                $t->id();$t->unsignedBigInteger('home_id')->default(1);$t->string('code')->unique();$t->unsignedBigInteger('user_id');
                $t->string('location')->nullable();$t->string('status');$t->string('device_uid')->nullable();$t->timestamp('expires_at');$t->timestamp('used_at')->nullable();$t->timestamps();
            });
            $migration=require __DIR__.'/../../database/php-migrations/2026_10_19_000001_harden_provisioning_and_commands.php';$migration->db=$db;$migration->up();
            $db->getSchemaBuilder()->create('devices', function ($t) {
                $t->id();$t->unsignedBigInteger('home_id');$t->string('device_uid')->unique();$t->string('name');$t->string('type');
                $t->string('location')->nullable();$t->string('firmware_version')->nullable();$t->string('mqtt_username')->unique();$t->string('mqtt_password_hash')->nullable();$t->timestamps();
            });
            $code=ProvisioningService::createCode(1);$db->disconnect();$children=[];
            for ($i=0;$i<2;$i++) {
                $pid=pcntl_fork();self::assertNotSame(-1,$pid);
                if ($pid===0) {
                    try {
                        $manager->purge($name);
                        ProvisioningService::register(['provision_code'=>$code->code,'gateway'=>['device_uid'=>'race-gateway-'.$i]]);
                        file_put_contents($dir.'/'.$i, 'registered');
                    } catch (BusinessException $e) { file_put_contents($dir.'/'.$i,(string)$e->getBusinessCode()); }
                    catch (\Throwable $e) { file_put_contents($dir.'/'.$i,get_class($e).':'.$e->getMessage()); }
                    exit(0);
                }
                $children[]=$pid;
            }
            foreach ($children as $pid) { pcntl_waitpid($pid,$status); self::assertSame(0,pcntl_wexitstatus($status)); }
            $results=[file_get_contents($dir.'/0'),file_get_contents($dir.'/1')];sort($results);
            self::assertSame(['9004','registered'],$results);
            self::assertSame(1,Device::withoutGlobalScopes()->count());
            self::assertSame('registered',ProvisionCode::withoutGlobalScopes()->first()->status);
            $db=$capsule->getConnection($name);$migration->db=$db;$migration->down();
            self::assertFalse($db->getSchemaBuilder()->hasTable('command_outbox'));
            self::assertFalse($db->getSchemaBuilder()->hasColumn('provision_codes','rebind_uid'));
            self::assertSame(1,Device::withoutGlobalScopes()->count());
        } finally {
            $manager->setDefaultConnection($previous);
            $db=$capsule->getConnection($name);$db->statement('SET search_path TO public');$db->statement("DROP SCHEMA {$schema} CASCADE");$manager->purge($name);
            foreach (glob($dir.'/*') as $file) unlink($file);rmdir($dir);
        }
    }
}
