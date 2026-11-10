<?php
namespace Tests\Unit;

use app\controller\MqttAuthController;
use app\model\Device;
use PHPUnit\Framework\TestCase;
use support\Request;

class MqttCallbackTest extends TestCase
{
    private function request(array $data): Request
    {
        $body = http_build_query($data);
        return new Request("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
    }

    public function test_emqx5_reads_top_level_auth_decisions_and_wrong_credentials_are_explicitly_denied(): void
    {
        $device = Device::create(['device_uid'=>'callback-'.bin2hex(random_bytes(5)), 'name'=>'test', 'mqtt_username'=>'callback-'.bin2hex(random_bytes(5)), 'mqtt_password_hash'=>password_hash('test-password', PASSWORD_BCRYPT)]);
        $controller = new MqttAuthController();
        try {
            $allowed = $controller->auth($this->request(['username'=>$device->mqtt_username, 'password'=>'test-password']));
            self::assertSame(200, $allowed->getStatusCode());
            self::assertSame(['result'=>'allow', 'is_superuser'=>false], json_decode($allowed->rawBody(), true));
            foreach ([[], ['username'=>$device->mqtt_username, 'password'=>'incorrect']] as $input) {
                $denied = $controller->auth($this->request($input));
                self::assertSame(200, $denied->getStatusCode());
                self::assertSame('deny', json_decode($denied->rawBody(), true)['result']);
            }
        } finally { $device->delete(); }
    }

    public function test_acl_decisions_are_explicit_and_do_not_grant_other_device_topics(): void
    {
        $device = Device::create(['device_uid'=>'acl-'.bin2hex(random_bytes(5)), 'name'=>'test', 'mqtt_username'=>'acl-'.bin2hex(random_bytes(5))]);
        $controller = new MqttAuthController();
        try {
            $allowed = $controller->acl($this->request(['username'=>$device->mqtt_username, 'topic'=>'home/upstream/'.$device->device_uid.'/state/post', 'action'=>'publish']));
            self::assertSame(['result'=>'allow'], json_decode($allowed->rawBody(), true));
            $denied = $controller->acl($this->request(['username'=>$device->mqtt_username, 'topic'=>'home/upstream/other-device/state/post', 'action'=>'publish']));
            self::assertSame(200, $denied->getStatusCode());
            self::assertSame(['result'=>'deny'], json_decode($denied->rawBody(), true));
        } finally { $device->delete(); }
    }
}
