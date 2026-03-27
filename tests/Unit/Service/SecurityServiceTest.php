<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\SecurityService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class SecurityServiceTest extends TestCase
{
    private SecurityService $service;
    private ICache&MockObject $cache;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(ICache::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->service = new SecurityService($cacheFactory, $this->logger);
    }

    // ── checkLoginRateLimit ──

    public function testCheckLoginRateLimitAllowsWhenNoAttempts(): void
    {
        $this->cache->method('get')->willReturn(null);

        $result = $this->service->checkLoginRateLimit('user1', '1.2.3.4');

        $this->assertTrue($result['allowed']);
    }

    public function testCheckLoginRateLimitBlocksLockedOutUser(): void
    {
        // User lockout returns future timestamp.
        $this->cache->method('get')->willReturnCallback(function (string $key) {
            if (str_contains($key, 'user_lockout')) {
                return time() + 3600;
            }
            return null;
        });

        $result = $this->service->checkLoginRateLimit('user1', '1.2.3.4');

        $this->assertFalse($result['allowed']);
        $this->assertArrayHasKey('lockout_until', $result);
    }

    public function testCheckLoginRateLimitBlocksLockedOutIp(): void
    {
        $this->cache->method('get')->willReturnCallback(function (string $key) {
            if (str_contains($key, 'ip_lockout')) {
                return time() + 3600;
            }
            return null;
        });

        $result = $this->service->checkLoginRateLimit('user1', '1.2.3.4');

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('IP address', $result['reason']);
    }

    public function testCheckLoginRateLimitBlocksWhenTooManyAttempts(): void
    {
        $this->cache->method('get')->willReturnCallback(function (string $key) {
            if (str_contains($key, 'login_attempts')) {
                return 5;
            }
            if (str_contains($key, 'ip_attempts')) {
                return 0;
            }
            return null;
        });

        $result = $this->service->checkLoginRateLimit('user1', '1.2.3.4');

        $this->assertFalse($result['allowed']);
        $this->assertArrayHasKey('delay', $result);
    }

    public function testCheckLoginRateLimitAllowsExpiredLockout(): void
    {
        $this->cache->method('get')->willReturnCallback(function (string $key) {
            if (str_contains($key, 'user_lockout')) {
                return time() - 1; // Expired.
            }
            return null;
        });

        $result = $this->service->checkLoginRateLimit('user1', '1.2.3.4');

        $this->assertTrue($result['allowed']);
    }

    // ── recordFailedLoginAttempt ──

    public function testRecordFailedLoginAttemptIncrementsCounter(): void
    {
        $this->cache->method('get')->willReturn(0);
        $this->cache->expects($this->atLeastOnce())->method('set');

        $this->service->recordFailedLoginAttempt('user1', '1.2.3.4');
    }

    public function testRecordFailedLoginAttemptLocksOutAfterThreshold(): void
    {
        // Return 4 (will become 5, triggering lockout).
        $this->cache->method('get')->willReturn(4);

        $setCalls = [];
        $this->cache->method('set')->willReturnCallback(function ($key, $value, $ttl) use (&$setCalls) {
            $setCalls[] = $key;
        });

        $this->service->recordFailedLoginAttempt('user1', '1.2.3.4');

        // Should set both user lockout and ip lockout.
        $lockoutKeys = array_filter($setCalls, fn($k) => str_contains($k, 'lockout'));
        $this->assertNotEmpty($lockoutKeys);
    }

    // ── recordSuccessfulLogin ──

    public function testRecordSuccessfulLoginClearsRateLimits(): void
    {
        $removedKeys = [];
        $this->cache->method('remove')->willReturnCallback(function ($key) use (&$removedKeys) {
            $removedKeys[] = $key;
            return true;
        });

        $this->service->recordSuccessfulLogin('user1', '1.2.3.4');

        // Should remove at least user attempts, user lockout, ip attempts, ip lockout, progressive delay.
        $this->assertGreaterThanOrEqual(5, count($removedKeys));
    }

    // ── clearIpRateLimits ──

    public function testClearIpRateLimitsRemovesIpKeys(): void
    {
        $removedKeys = [];
        $this->cache->method('remove')->willReturnCallback(function ($key) use (&$removedKeys) {
            $removedKeys[] = $key;
            return true;
        });

        $this->service->clearIpRateLimits('1.2.3.4');

        $this->assertCount(2, $removedKeys);
    }

    // ── clearUserRateLimits ──

    public function testClearUserRateLimitsRemovesUserKeys(): void
    {
        $removedKeys = [];
        $this->cache->method('remove')->willReturnCallback(function ($key) use (&$removedKeys) {
            $removedKeys[] = $key;
            return true;
        });

        $this->service->clearUserRateLimits('user1');

        $this->assertCount(2, $removedKeys);
    }

    // ── sanitizeInput ──

    public function testSanitizeInputTrimsStrings(): void
    {
        $this->assertSame('hello', $this->service->sanitizeInput('  hello  '));
    }

    public function testSanitizeInputTruncatesLongStrings(): void
    {
        $longString = str_repeat('a', 300);
        $result = $this->service->sanitizeInput($longString, 10);
        $this->assertSame(10, strlen($result));
    }

    public function testSanitizeInputRemovesNullBytes(): void
    {
        $result = $this->service->sanitizeInput("hel\0lo");
        $this->assertStringNotContainsString("\0", $result);
    }

    public function testSanitizeInputEscapesHtml(): void
    {
        $result = $this->service->sanitizeInput('<b>bold</b>');
        $this->assertStringNotContainsString('<b>', $result);
    }

    public function testSanitizeInputReturnsNonStringsUnchanged(): void
    {
        $this->assertSame(42, $this->service->sanitizeInput(42));
        $this->assertNull($this->service->sanitizeInput(null));
        $this->assertTrue($this->service->sanitizeInput(true));
    }

    public function testSanitizeInputProcessesArraysRecursively(): void
    {
        $result = $this->service->sanitizeInput(['  hello  ', '<script>alert(1)</script>']);
        $this->assertIsArray($result);
        $this->assertSame('hello', $result[0]);
    }

    // ── validateLoginCredentials ──

    public function testValidateLoginCredentialsRejectsEmptyUsername(): void
    {
        $result = $this->service->validateLoginCredentials(['username' => '', 'password' => 'pass']);
        $this->assertFalse($result['valid']);
    }

    public function testValidateLoginCredentialsRejectsEmptyPassword(): void
    {
        $result = $this->service->validateLoginCredentials(['username' => 'user', 'password' => '']);
        $this->assertFalse($result['valid']);
    }

    public function testValidateLoginCredentialsRejectsShortUsername(): void
    {
        $result = $this->service->validateLoginCredentials(['username' => 'a', 'password' => 'password123']);
        $this->assertFalse($result['valid']);
    }

    public function testValidateLoginCredentialsRejectsInvalidChars(): void
    {
        // The sanitizer escapes < and > before the regex check runs,
        // so use / which survives sanitization and matches the regex.
        $result = $this->service->validateLoginCredentials(['username' => 'user/name', 'password' => 'password123']);
        $this->assertFalse($result['valid']);
    }

    public function testValidateLoginCredentialsRejectsTooLongPassword(): void
    {
        $result = $this->service->validateLoginCredentials([
            'username' => 'validuser',
            'password' => str_repeat('a', 1001),
        ]);
        $this->assertFalse($result['valid']);
    }

    public function testValidateLoginCredentialsAcceptsValidInput(): void
    {
        $result = $this->service->validateLoginCredentials([
            'username' => 'validuser',
            'password' => 'password123',
        ]);
        $this->assertTrue($result['valid']);
        $this->assertSame('validuser', $result['credentials']['username']);
    }

    // ── addSecurityHeaders ──

    public function testAddSecurityHeadersReturnsResponse(): void
    {
        // JSONResponse requires OC class in unit tests, so we use a mock.
        $response = $this->createMock(JSONResponse::class);
        $headerCount = 0;
        $response->method('addHeader')->willReturnCallback(
            function () use ($response, &$headerCount) {
                $headerCount++;
                return $response;
            }
        );

        $result = $this->service->addSecurityHeaders($response);
        $this->assertSame($response, $result);
        // Should add at least 5 security headers.
        $this->assertGreaterThanOrEqual(5, $headerCount);
    }

    // ── getClientIpAddress ──

    public function testGetClientIpAddressReturnsRemoteAddress(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getRemoteAddress')->willReturn('192.168.1.1');
        $request->method('getHeader')->willReturn('');

        $result = $this->service->getClientIpAddress($request);
        $this->assertSame('192.168.1.1', $result);
    }

    public function testGetClientIpAddressUsesForwardedHeader(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getRemoteAddress')->willReturn('192.168.1.1');
        $request->method('getHeader')->willReturnCallback(function (string $header) {
            if ($header === 'HTTP_CF_CONNECTING_IP') {
                return '8.8.8.8';
            }
            return '';
        });

        $result = $this->service->getClientIpAddress($request);
        $this->assertSame('8.8.8.8', $result);
    }

    public function testGetClientIpAddressIgnoresPrivateForwardedIps(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getRemoteAddress')->willReturn('10.0.0.1');
        $request->method('getHeader')->willReturnCallback(function (string $header) {
            if ($header === 'HTTP_X_FORWARDED_FOR') {
                return '192.168.1.100';
            }
            return '';
        });

        $result = $this->service->getClientIpAddress($request);
        // Private IP in forwarded header is rejected, falls back to remote address.
        $this->assertSame('10.0.0.1', $result);
    }
}
