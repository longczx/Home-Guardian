<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\service\JwtService;

class JwtServiceTest extends TestCase
{
    /* =============================================
     * issueAccessToken + verifyAccessToken
     * ============================================= */

    public function test_issue_and_verify_roundtrip(): void
    {
        $token = JwtService::issueAccessToken(
            1, 'admin', ['admin'], ['admin' => true], ['home']
        );

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $payload = JwtService::verifyAccessToken($token);

        $this->assertNotNull($payload);
        $this->assertEquals(1, $payload->sub);
        $this->assertEquals('admin', $payload->username);
    }

    public function test_payload_contains_all_fields(): void
    {
        $roles       = ['editor', 'viewer'];
        $permissions = ['content.edit' => true, 'admin' => false];
        $locations   = ['home', 'office'];

        $token   = JwtService::issueAccessToken(42, 'alice', $roles, $permissions, $locations);
        $payload = JwtService::verifyAccessToken($token);

        $this->assertNotNull($payload);
        $this->assertEquals(42, $payload->sub);
        $this->assertEquals('alice', $payload->username);
        $this->assertEquals($roles, $payload->roles);
        $this->assertEquals($locations, $payload->locations);
        $this->assertEquals('home-guardian', $payload->iss);

        // permissions 是 stdClass（JSON 对象解码）
        $this->assertIsObject($payload->permissions);
        $this->assertTrue($payload->permissions->{'content.edit'});
        $this->assertFalse($payload->permissions->admin);
    }

    public function test_payload_has_iat_and_exp(): void
    {
        $before = time();
        $token  = JwtService::issueAccessToken(1, 'u', [], [], []);
        $after  = time();

        $payload = JwtService::verifyAccessToken($token);

        $this->assertNotNull($payload);
        $this->assertGreaterThanOrEqual($before, $payload->iat);
        $this->assertLessThanOrEqual($after, $payload->iat);
        $this->assertEquals($payload->iat + 7200, $payload->exp);
    }

    /* =============================================
     * 无效 / 过期 Token
     * ============================================= */

    public function test_verify_returns_null_for_garbage_string(): void
    {
        $this->assertNull(JwtService::verifyAccessToken('not.a.jwt'));
    }

    public function test_verify_returns_null_for_empty_string(): void
    {
        $this->assertNull(JwtService::verifyAccessToken(''));
    }

    public function test_verify_returns_null_for_wrong_secret(): void
    {
        $token = JwtService::issueAccessToken(1, 'u', [], [], []);

        // 改变密钥后验证
        putenv('JWT_SECRET=a_completely_different_secret_key!');
        $result = JwtService::verifyAccessToken($token);
        $this->assertNull($result);

        // 恢复密钥
        putenv('JWT_SECRET=unit_test_jwt_secret_key_32chars!');
    }

    public function test_verify_returns_null_for_expired_token(): void
    {
        putenv('JWT_ACCESS_TTL=1');
        $token = JwtService::issueAccessToken(1, 'u', [], [], []);
        sleep(2);

        $this->assertNull(JwtService::verifyAccessToken($token));

        putenv('JWT_ACCESS_TTL=7200');
    }

    /* =============================================
     * Refresh Token 工具方法
     * ============================================= */

    public function test_generate_refresh_token_is_64_hex(): void
    {
        $token = JwtService::generateRefreshToken();
        $this->assertEquals(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_generate_refresh_token_is_unique(): void
    {
        $a = JwtService::generateRefreshToken();
        $b = JwtService::generateRefreshToken();
        $this->assertNotEquals($a, $b);
    }

    public function test_hash_refresh_token(): void
    {
        $token = 'abc123';
        $hash  = JwtService::hashRefreshToken($token);
        $this->assertEquals(hash('sha256', 'abc123'), $hash);
    }

    /* =============================================
     * JWT_SECRET 校验
     * ============================================= */

    public function test_throws_when_jwt_secret_not_set(): void
    {
        putenv('JWT_SECRET=');
        $this->expectException(\RuntimeException::class);
        JwtService::issueAccessToken(1, 'u', [], [], []);
    }

    public function test_throws_when_jwt_secret_is_default_placeholder(): void
    {
        putenv('JWT_SECRET=your_jwt_secret_key_here');
        $this->expectException(\RuntimeException::class);
        JwtService::issueAccessToken(1, 'u', [], [], []);
    }

    protected function tearDown(): void
    {
        // 确保每个测试结束时密钥恢复
        putenv('JWT_SECRET=unit_test_jwt_secret_key_32chars!');
        putenv('JWT_ACCESS_TTL=7200');
    }
}
