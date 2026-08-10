<?php

/**
 * SecurityService
 *
 * Service for handling security measures including rate limiting and XSS protection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction.nl <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Service for handling security measures
 *
 * This service provides comprehensive security features including:
 * - Rate limiting for login attempts
 * - XSS protection through input sanitization
 * - Brute force protection with IP-based blocking
 * - Login attempt logging and monitoring
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) SecurityService covers a single cohesive
 *   security domain (login rate-limiting, inbound-API brute-force protection, input
 *   sanitization, and response headers). Each method is consumed by external callers
 *   (UserController and RateLimitMiddleware); splitting into sub-services would scatter
 *   the security surface without reducing per-method complexity.
 */
class SecurityService
{

    /**
     * Cache instance for storing rate limit data
     *
     * @var ICache
     */
    private readonly ICache $cache;

    /**
     * Logger for security events
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Rate limiting configuration constants
     */
    private const RATE_LIMIT_ATTEMPTS    = 5;
    private const RATE_LIMIT_WINDOW      = 900;
    private const LOCKOUT_DURATION       = 3600;
    private const PROGRESSIVE_DELAY_BASE = 2;
    private const MAX_PROGRESSIVE_DELAY  = 60;

    /**
     * Inbound-API brute-force configuration constants.
     *
     * These are intentionally MORE generous than the interactive-login
     * constants above. Inbound API auth is wired into a middleware that
     * sees every authenticated request, so a tight threshold risks locking
     * out legitimate automated traffic (mis-configured token in a cron job,
     * a single client behind a shared NAT, etc). The composite identity+IP
     * key (see checkAuthRateLimit) keeps a single bad actor from poisoning a
     * shared IP, so we can afford a higher per-IP fallback ceiling.
     */
    private const AUTH_RATE_LIMIT_ATTEMPTS    = 20;
    private const AUTH_RATE_LIMIT_IP_ATTEMPTS = 100;
    private const AUTH_RATE_LIMIT_WINDOW      = 900;
    private const AUTH_LOCKOUT_DURATION       = 900;

    /**
     * Cache key prefixes for different security features
     */
    private const CACHE_PREFIX_LOGIN_ATTEMPTS    = 'openregister_login_attempts_';
    private const CACHE_PREFIX_IP_ATTEMPTS       = 'openregister_ip_attempts_';
    private const CACHE_PREFIX_USER_LOCKOUT      = 'openregister_user_lockout_';
    private const CACHE_PREFIX_IP_LOCKOUT        = 'openregister_ip_lockout_';
    private const CACHE_PREFIX_PROGRESSIVE_DELAY = 'openregister_progressive_delay_';

    /**
     * Cache key prefixes for the inbound-API brute-force path.
     *
     * Kept separate from the interactive-login prefixes so the two paths
     * never share counters: locking out a brute-forced API token must not
     * lock the same identity out of the login form, and vice-versa.
     */
    private const CACHE_PREFIX_AUTH_ATTEMPTS    = 'openregister_auth_attempts_';
    private const CACHE_PREFIX_AUTH_IP_ATTEMPTS = 'openregister_auth_ip_attempts_';
    private const CACHE_PREFIX_AUTH_LOCKOUT     = 'openregister_auth_lockout_';

    /**
     * Constructor for SecurityService
     *
     * @param ICacheFactory   $cacheFactory Factory for creating cache instances
     * @param LoggerInterface $logger       Logger for security event logging
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        LoggerInterface $logger
    ) {
        $this->cache  = $cacheFactory->createDistributed('openregister_security');
        $this->logger = $logger;
    }//end __construct()

    /**
     * Assert that a user-supplied URL is safe to fetch server-side (anti-SSRF).
     *
     * Validates scheme (http/https only) and resolves EVERY A and AAAA record
     * for the host, rejecting the request if ANY resolved address is loopback,
     * private (RFC-1918 / ULA), or reserved/link-local. This is the single
     * shared guard that every server-side URL fetcher in the app MUST call
     * before issuing a request (UploadService, FilePropertyHandler,
     * ConfigurationController, GitHub/GitLab handlers, webhooks).
     *
     * Callers that follow redirects MUST re-validate every redirect target,
     * or disable redirect following entirely, because this check is a
     * point-in-time DNS resolution and does not pin the connection IP.
     *
     * @param string $url The user-supplied URL to validate.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the URL is malformed or resolves to a non-public address.
     *
     * @spec openspec/specs/apphost-store-plane/spec.md#requirement-every-outbound-registry-url-must-be-ssrf-guarded-and-must-not-follow-redirects
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public static function assertSafeFetchUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) === true || empty($parts['host']) === true) {
            throw new InvalidArgumentException('Invalid URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (in_array($scheme, ['http', 'https'], true) === false) {
            throw new InvalidArgumentException('Only http and https URLs are allowed.');
        }

        $host = $parts['host'];

        // Collect every IP the host resolves to (IPv4 + IPv6). If the host is
        // already a literal IP, validate it directly.
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips[] = $host;
        } else {
            $v4 = gethostbynamel($host);
            if (is_array($v4) === true) {
                $ips = array_merge($ips, $v4);
            }

            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa) === true) {
                foreach ($aaaa as $record) {
                    if (isset($record['ipv6']) === true) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if (empty($ips) === true) {
            // Fail closed: a host we cannot resolve must not be fetched.
            throw new InvalidArgumentException('URL host could not be resolved.');
        }

        foreach ($ips as $ip) {
            $isPublic = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            );
            if ($isPublic === false) {
                throw new InvalidArgumentException('URL resolves to a non-public address and cannot be fetched.');
            }
        }
    }//end assertSafeFetchUrl()

    /**
     * Check if login attempt is allowed based on rate limiting rules
     *
     * @param string $username  The username attempting to login
     * @param string $ipAddress The IP address of the request
     *
     * @return array Result with 'allowed' boolean and optional 'delay' or 'lockout_until'
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function checkLoginRateLimit(string $username, string $ipAddress): array
    {
        $username  = $this->sanitizeForCacheKey(input: $username);
        $ipAddress = $this->sanitizeForCacheKey(input: $ipAddress);

        $userLockoutKey   = self::CACHE_PREFIX_USER_LOCKOUT.$username;
        $userLockoutUntil = $this->cache->get($userLockoutKey);
        if ($userLockoutUntil !== null && $userLockoutUntil > time()) {
            $this->logSecurityEvent(
                event: 'login_attempt_during_lockout',
                context: [
                    'username'      => $username,
                    'ip_address'    => $ipAddress,
                    'lockout_until' => $userLockoutUntil,
                ]
            );

            return [
                'allowed'       => false,
                'lockout_until' => $userLockoutUntil,
                'reason'        => 'Account temporarily locked due to too many failed login attempts',
            ];
        }

        $ipLockoutKey   = self::CACHE_PREFIX_IP_LOCKOUT.$ipAddress;
        $ipLockoutUntil = $this->cache->get($ipLockoutKey);
        if ($ipLockoutUntil !== null && $ipLockoutUntil > time()) {
            $this->logSecurityEvent(
                event: 'login_attempt_from_blocked_ip',
                context: [
                    'username'      => $username,
                    'ip_address'    => $ipAddress,
                    'lockout_until' => $ipLockoutUntil,
                ]
            );

            return [
                'allowed'       => false,
                'lockout_until' => $ipLockoutUntil,
                'reason'        => 'IP address temporarily blocked due to suspicious activity',
            ];
        }

        $userAttemptsKey = self::CACHE_PREFIX_LOGIN_ATTEMPTS.$username;
        $userAttempts    = $this->cache->get($userAttemptsKey) ?? 0;

        $ipAttemptsKey = self::CACHE_PREFIX_IP_ATTEMPTS.$ipAddress;
        $ipAttempts    = $this->cache->get($ipAttemptsKey) ?? 0;

        if ($userAttempts >= self::RATE_LIMIT_ATTEMPTS || $ipAttempts >= self::RATE_LIMIT_ATTEMPTS) {
            $delayKey     = self::CACHE_PREFIX_PROGRESSIVE_DELAY.$username.'_'.$ipAddress;
            $currentDelay = $this->cache->get($delayKey) ?? self::PROGRESSIVE_DELAY_BASE;

            $nextDelay = min($currentDelay * 2, self::MAX_PROGRESSIVE_DELAY);
            $this->cache->set($delayKey, $nextDelay, self::RATE_LIMIT_WINDOW);

            $this->logSecurityEvent(
                event: 'rate_limit_exceeded',
                context: [
                    'username'      => $username,
                    'ip_address'    => $ipAddress,
                    'user_attempts' => $userAttempts,
                    'ip_attempts'   => $ipAttempts,
                    'delay'         => $currentDelay,
                ]
            );

            return [
                'allowed' => false,
                'delay'   => $currentDelay,
                'reason'  => 'Too many login attempts. Please wait before trying again.',
            ];
        }//end if

        return ['allowed' => true];
    }//end checkLoginRateLimit()

    /**
     * Record a failed login attempt
     *
     * @param string $username  The username that failed authentication
     * @param string $ipAddress The IP address of the failed attempt
     * @param string $reason    The reason for login failure
     *
     * @return void
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function recordFailedLoginAttempt(string $username, string $ipAddress, string $reason='invalid_credentials'): void
    {
        $username  = $this->sanitizeForCacheKey(input: $username);
        $ipAddress = $this->sanitizeForCacheKey(input: $ipAddress);

        $userAttemptsKey = self::CACHE_PREFIX_LOGIN_ATTEMPTS.$username;
        $userAttempts    = ($this->cache->get($userAttemptsKey) ?? 0) + 1;
        $this->cache->set($userAttemptsKey, $userAttempts, self::RATE_LIMIT_WINDOW);

        $ipAttemptsKey = self::CACHE_PREFIX_IP_ATTEMPTS.$ipAddress;
        $ipAttempts    = ($this->cache->get($ipAttemptsKey) ?? 0) + 1;
        $this->cache->set($ipAttemptsKey, $ipAttempts, self::RATE_LIMIT_WINDOW);

        if ($userAttempts >= self::RATE_LIMIT_ATTEMPTS) {
            $lockoutUntil   = time() + self::LOCKOUT_DURATION;
            $userLockoutKey = self::CACHE_PREFIX_USER_LOCKOUT.$username;
            $this->cache->set($userLockoutKey, $lockoutUntil, self::LOCKOUT_DURATION);

            $this->logSecurityEvent(
                event: 'user_locked_out',
                context: [
                    'username'      => $username,
                    'ip_address'    => $ipAddress,
                    'attempts'      => $userAttempts,
                    'lockout_until' => $lockoutUntil,
                ]
            );
        }

        if ($ipAttempts >= self::RATE_LIMIT_ATTEMPTS) {
            $lockoutUntil = time() + self::LOCKOUT_DURATION;
            $ipLockoutKey = self::CACHE_PREFIX_IP_LOCKOUT.$ipAddress;
            $this->cache->set($ipLockoutKey, $lockoutUntil, self::LOCKOUT_DURATION);

            $this->logSecurityEvent(
                event: 'ip_locked_out',
                context: [
                    'username'      => $username,
                    'ip_address'    => $ipAddress,
                    'attempts'      => $ipAttempts,
                    'lockout_until' => $lockoutUntil,
                ]
            );
        }

        $this->logSecurityEvent(
            event: 'failed_login_attempt',
            context: [
                'username'      => $username,
                'ip_address'    => $ipAddress,
                'reason'        => $reason,
                'user_attempts' => $userAttempts,
                'ip_attempts'   => $ipAttempts,
            ]
        );
    }//end recordFailedLoginAttempt()

    /**
     * Record a successful login attempt
     *
     * @param string $username  The username that successfully authenticated
     * @param string $ipAddress The IP address of the successful attempt
     *
     * @return void
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function recordSuccessfulLogin(string $username, string $ipAddress): void
    {
        $username  = $this->sanitizeForCacheKey(input: $username);
        $ipAddress = $this->sanitizeForCacheKey(input: $ipAddress);

        // Clear user-based rate limits.
        $userAttemptsKey = self::CACHE_PREFIX_LOGIN_ATTEMPTS.$username;
        $this->cache->remove($userAttemptsKey);

        $userLockoutKey = self::CACHE_PREFIX_USER_LOCKOUT.$username;
        $this->cache->remove($userLockoutKey);

        // Clear IP-based rate limits.
        $ipAttemptsKey = self::CACHE_PREFIX_IP_ATTEMPTS.$ipAddress;
        $this->cache->remove($ipAttemptsKey);

        $ipLockoutKey = self::CACHE_PREFIX_IP_LOCKOUT.$ipAddress;
        $this->cache->remove($ipLockoutKey);

        // Clear progressive delay.
        $delayKey = self::CACHE_PREFIX_PROGRESSIVE_DELAY.$username.'_'.$ipAddress;
        $this->cache->remove($delayKey);

        $this->logSecurityEvent(
            event: 'successful_login',
            context: [
                'username'   => $username,
                'ip_address' => $ipAddress,
            ]
        );
    }//end recordSuccessfulLogin()

    /**
     * Clear all rate limits for a specific IP address
     *
     * This method allows administrators to unblock an IP that has been
     * temporarily blocked due to suspicious activity.
     *
     * @param string $ipAddress The IP address to unblock
     *
     * @return void
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function clearIpRateLimits(string $ipAddress): void
    {
        $ipAddress = $this->sanitizeForCacheKey(input: $ipAddress);

        $ipAttemptsKey = self::CACHE_PREFIX_IP_ATTEMPTS.$ipAddress;
        $this->cache->remove($ipAttemptsKey);

        $ipLockoutKey = self::CACHE_PREFIX_IP_LOCKOUT.$ipAddress;
        $this->cache->remove($ipLockoutKey);

        $this->logSecurityEvent(
            event: 'ip_rate_limits_cleared',
            context: ['ip_address' => $ipAddress]
        );
    }//end clearIpRateLimits()

    /**
     * Clear all rate limits for a specific user
     *
     * This method allows administrators to unblock a user account that has been
     * temporarily locked due to too many failed login attempts.
     *
     * @param string $username The username to unblock
     *
     * @return void
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function clearUserRateLimits(string $username): void
    {
        $username = $this->sanitizeForCacheKey(input: $username);

        $userAttemptsKey = self::CACHE_PREFIX_LOGIN_ATTEMPTS.$username;
        $this->cache->remove($userAttemptsKey);

        $userLockoutKey = self::CACHE_PREFIX_USER_LOCKOUT.$username;
        $this->cache->remove($userLockoutKey);

        $this->logSecurityEvent(
            event: 'user_rate_limits_cleared',
            context: ['username' => $username]
        );
    }//end clearUserRateLimits()

    /**
     * Check whether an inbound-API authentication attempt is allowed.
     *
     * This powers the brute-force protection wired into the inbound auth
     * middleware (issue #1834). It is deliberately distinct from
     * checkLoginRateLimit():
     *
     * - Lockout is keyed on a COMPOSITE (identity + IP) so a single bad
     *   actor on a shared NAT/proxy IP cannot lock out, or reset the
     *   counter of, other legitimate clients sharing that IP (issue #1834
     *   item 2). A much higher per-IP ceiling is kept purely as a
     *   coarse-grained backstop against a high-volume sprayer.
     * - Thresholds are generous (see AUTH_RATE_LIMIT_* constants) to avoid
     *   locking out legitimate automated traffic.
     *
     * The caller (middleware) MUST fail OPEN: if this method throws, the
     * request should be allowed through. A bug in the limiter must never
     * deny service to every client.
     *
     * @param string $identity  The authentication identity (username/token id) or '' if unknown
     * @param string $ipAddress The trusted client IP address of the request
     *
     * @return array{allowed: bool, lockout_until?: int, reason?: string} Result
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function checkAuthRateLimit(string $identity, string $ipAddress): array
    {
        $compositeKey = $this->buildAuthCompositeKey(identity: $identity, ipAddress: $ipAddress);
        $ipAddress    = $this->sanitizeForCacheKey(input: $ipAddress);

        // Composite (identity+IP) lockout — the primary, narrowly-scoped gate.
        $lockoutKey   = self::CACHE_PREFIX_AUTH_LOCKOUT.$compositeKey;
        $lockoutUntil = $this->cache->get($lockoutKey);
        if ($lockoutUntil !== null && $lockoutUntil > time()) {
            return [
                'allowed'       => false,
                'lockout_until' => $lockoutUntil,
                'reason'        => 'Too many failed authentication attempts. Please wait before trying again.',
            ];
        }

        return ['allowed' => true];
    }//end checkAuthRateLimit()

    /**
     * Record a failed inbound-API authentication attempt.
     *
     * Increments the composite (identity+IP) counter and a coarse per-IP
     * counter, applying a lockout when either threshold is crossed. Callers
     * (the middleware) MUST swallow any exception this throws (fail-open).
     *
     * @param string $identity  The authentication identity (username/token id) or '' if unknown
     * @param string $ipAddress The trusted client IP address of the failed attempt
     * @param string $reason    The reason for authentication failure
     *
     * @return void
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function recordFailedAuthAttempt(string $identity, string $ipAddress, string $reason='inbound_auth_failed'): void
    {
        $compositeKey = $this->buildAuthCompositeKey(identity: $identity, ipAddress: $ipAddress);
        $sanitizedIp  = $this->sanitizeForCacheKey(input: $ipAddress);

        // Composite (identity+IP) counter — primary gate.
        $attemptsKey = self::CACHE_PREFIX_AUTH_ATTEMPTS.$compositeKey;
        $attempts    = ($this->cache->get($attemptsKey) ?? 0) + 1;
        $this->cache->set($attemptsKey, $attempts, self::AUTH_RATE_LIMIT_WINDOW);

        // Coarse per-IP counter — high-ceiling backstop only.
        $ipAttemptsKey = self::CACHE_PREFIX_AUTH_IP_ATTEMPTS.$sanitizedIp;
        $ipAttempts    = ($this->cache->get($ipAttemptsKey) ?? 0) + 1;
        $this->cache->set($ipAttemptsKey, $ipAttempts, self::AUTH_RATE_LIMIT_WINDOW);

        $lockoutTriggered = false;
        if ($attempts >= self::AUTH_RATE_LIMIT_ATTEMPTS || $ipAttempts >= self::AUTH_RATE_LIMIT_IP_ATTEMPTS) {
            $lockoutTriggered = true;
            $lockoutUntil     = time() + self::AUTH_LOCKOUT_DURATION;
            $lockoutKey       = self::CACHE_PREFIX_AUTH_LOCKOUT.$compositeKey;
            $this->cache->set($lockoutKey, $lockoutUntil, self::AUTH_LOCKOUT_DURATION);

            $this->logSecurityEvent(
                event: 'auth_locked_out',
                context: [
                    'identity'      => $identity,
                    'ip_address'    => $sanitizedIp,
                    'attempts'      => $attempts,
                    'ip_attempts'   => $ipAttempts,
                    'lockout_until' => $lockoutUntil,
                ]
            );
        }

        $this->logSecurityEvent(
            event: 'failed_auth_attempt',
            context: [
                'identity'          => $identity,
                'ip_address'        => $sanitizedIp,
                'reason'            => $reason,
                'attempts'          => $attempts,
                'ip_attempts'       => $ipAttempts,
                'lockout_triggered' => $lockoutTriggered,
            ]
        );
    }//end recordFailedAuthAttempt()

    /**
     * Clear the inbound-API auth rate-limit counters for an identity+IP.
     *
     * Called on a successful authenticated request so a single mistyped
     * credential earlier in the window doesn't accumulate toward a lockout.
     *
     * @param string $identity  The authentication identity (username/token id)
     * @param string $ipAddress The trusted client IP address
     *
     * @return void
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
     */
    public function recordSuccessfulAuth(string $identity, string $ipAddress): void
    {
        $compositeKey = $this->buildAuthCompositeKey(identity: $identity, ipAddress: $ipAddress);

        $this->cache->remove(self::CACHE_PREFIX_AUTH_ATTEMPTS.$compositeKey);
        $this->cache->remove(self::CACHE_PREFIX_AUTH_LOCKOUT.$compositeKey);
    }//end recordSuccessfulAuth()

    /**
     * Resolve the trusted client IP address for rate-limiting purposes.
     *
     * Unlike getClientIpAddress() — which trusts forwarding headers from
     * ANY source and is therefore spoofable (issue #1834 item 3) — this
     * method defers entirely to the platform's getRemoteAddress(), which
     * only honours forwarding headers from configured `trusted_proxies`.
     * This is the correct primitive for security decisions.
     *
     * @param IRequest $request The request object
     *
     * @return string The trusted client IP address
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-cors-policy-must-be-enforced-per-consumer-and-prevent-csrf
     */
    public function getTrustedClientIpAddress(IRequest $request): string
    {
        return $request->getRemoteAddress();
    }//end getTrustedClientIpAddress()

    /**
     * Build the composite (identity + IP) cache key for inbound-API auth.
     *
     * @param string $identity  The authentication identity, may be empty
     * @param string $ipAddress The trusted client IP address
     *
     * @return string The sanitized composite cache key
     */
    private function buildAuthCompositeKey(string $identity, string $ipAddress): string
    {
        $identityValue = $identity;
        if ($identity === '') {
            $identityValue = 'anonymous';
        }

        $identity = $this->sanitizeForCacheKey(input: $identityValue);
        $ipKey    = $this->sanitizeForCacheKey(input: $ipAddress);

        return $identity.'_'.$ipKey;
    }//end buildAuthCompositeKey()

    /**
     * Sanitize input data to prevent XSS and injection attacks
     *
     * @param mixed $input     The input to sanitize
     * @param int   $maxLength Maximum allowed length for strings
     *
     * @return mixed Sanitized input
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-input-sanitization-must-prevent-xss-and-injection-attacks
     */
    public function sanitizeInput(mixed $input, int $maxLength=255): mixed
    {
        if (is_array($input) === true) {
            return array_map(fn(mixed $item): mixed => $this->sanitizeInput(input: $item, maxLength: $maxLength), $input);
        }

        if (is_string($input) === false) {
            return $input;
        }

        $input = trim($input);

        if (strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }

        $input = str_replace("\0", '', $input);

        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $dangerousPatterns = [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
            '/javascript:/i',
            '/vbscript:/i',
            '/onload\s*=/i',
            '/onerror\s*=/i',
            '/onclick\s*=/i',
            '/onmouseover\s*=/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            $input = preg_replace($pattern, '', $input);
        }

        return $input;
    }//end sanitizeInput()

    /**
     * Validate and sanitize login credentials
     *
     * @param array $credentials The login credentials to validate
     *
     * @return array Validated and sanitized credentials or error information
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-input-sanitization-must-prevent-xss-and-injection-attacks
     */
    public function validateLoginCredentials(array $credentials): array
    {
        if (empty($credentials['username']) === true || empty($credentials['password']) === true) {
            return [
                'valid' => false,
                'error' => 'Username and password are required',
            ];
        }

        $sanitizedUsername = $this->sanitizeInput(input: $credentials['username'], maxLength: 320);

        if (strlen($sanitizedUsername) < 2) {
            return [
                'valid' => false,
                'error' => 'Username must be at least 2 characters long',
            ];
        }

        if (preg_match('/[<>"\'\\/\\\\]/', $sanitizedUsername) === 1) {
            return [
                'valid' => false,
                'error' => 'Username contains invalid characters',
            ];
        }

        $password = $credentials['password'];
        if (strlen($password) > 1000) {
            return [
                'valid' => false,
                'error' => 'Password is too long',
            ];
        }

        return [
            'valid'       => true,
            'credentials' => [
                'username' => $sanitizedUsername,
                'password' => $password,
            ],
        ];
    }//end validateLoginCredentials()

    /**
     * Add security headers to response
     *
     * @param JSONResponse $response The response to add headers to
     *
     * @return JSONResponse The response with added security headers
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-cors-policy-must-be-enforced-per-consumer-and-prevent-csrf
     */
    public function addSecurityHeaders(JSONResponse $response): JSONResponse
    {
        $response->addHeader('X-Frame-Options', 'DENY');
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->addHeader('X-XSS-Protection', '1; mode=block');
        $response->addHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->addHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none';");
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Expires', '0');

        return $response;
    }//end addSecurityHeaders()

    /**
     * Get client IP address from request
     *
     * @param IRequest $request The request object
     *
     * @return string The client IP address
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/specs/auth-system/spec.md#requirement-cors-policy-must-be-enforced-per-consumer-and-prevent-csrf
     */
    public function getClientIpAddress(IRequest $request): string
    {
        // SEC-SVC-11: rely on Nextcloud's IRequest::getRemoteAddress(), which
        // already resolves the real client IP via the configured
        // `trusted_proxies` / `forwarded_for_headers` system settings. Parsing
        // X-Forwarded-For / X-Real-IP / CF-Connecting-IP unconditionally let
        // any client spoof its source IP and bypass per-IP rate limiting and
        // lockouts. NC only honours those headers when the request actually
        // originates from a configured trusted proxy.
        return $request->getRemoteAddress();
    }//end getClientIpAddress()

    /**
     * Sanitize string for safe cache key usage
     *
     * @param string $input The input string to sanitize
     *
     * @return string Sanitized cache key
     */
    private function sanitizeForCacheKey(string $input): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9._@-]/', '_', $input);

        return substr($sanitized, 0, 64);
    }//end sanitizeForCacheKey()

    /**
     * Log security events
     *
     * @param string $event   The event type
     * @param array  $context Additional context data
     *
     * @return void
     */
    private function logSecurityEvent(string $event, array $context=[]): void
    {
        $context['event']     = $event;
        $context['timestamp'] = (new DateTime())->format('Y-m-d H:i:s');

        switch ($event) {
            case 'user_locked_out':
            case 'login_attempt_during_lockout':
            case 'auth_locked_out':
                $this->logger->warning(
                    message: "[SecurityService] Security event: {$event}",
                    context: array_merge(['file' => __FILE__, 'line' => __LINE__], $context)
                );
                break;
            case 'rate_limit_exceeded':
            case 'failed_login_attempt':
            case 'successful_login':
            default:
                $this->logger->info(
                    message: "[SecurityService] Security event: {$event}",
                    context: array_merge(['file' => __FILE__, 'line' => __LINE__], $context)
                );
                break;
        }
    }//end logSecurityEvent()
}//end class
