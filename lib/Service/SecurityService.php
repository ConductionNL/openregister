<?php

/**
 * SecurityService
 *
 * Service for handling security measures including rate limiting and XSS protection.
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
     * Cache key prefixes for different security features
     */
    private const CACHE_PREFIX_LOGIN_ATTEMPTS    = 'openregister_login_attempts_';
    private const CACHE_PREFIX_IP_ATTEMPTS       = 'openregister_ip_attempts_';
    private const CACHE_PREFIX_USER_LOCKOUT      = 'openregister_user_lockout_';
    private const CACHE_PREFIX_IP_LOCKOUT        = 'openregister_ip_lockout_';
    private const CACHE_PREFIX_PROGRESSIVE_DELAY = 'openregister_progressive_delay_';

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
     * Check if login attempt is allowed based on rate limiting rules
     *
     * @param string $username  The username attempting to login
     * @param string $ipAddress The IP address of the request
     *
     * @return array Result with 'allowed' boolean and optional 'delay' or 'lockout_until'
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function checkLoginRateLimit(string $username, string $ipAddress): array
    {
        $username  = $this->sanitizeForCacheKey($username);
        $ipAddress = $this->sanitizeForCacheKey($ipAddress);

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
     */
    public function recordFailedLoginAttempt(string $username, string $ipAddress, string $reason='invalid_credentials'): void
    {
        $username  = $this->sanitizeForCacheKey($username);
        $ipAddress = $this->sanitizeForCacheKey($ipAddress);

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
     */
    public function recordSuccessfulLogin(string $username, string $ipAddress): void
    {
        $username  = $this->sanitizeForCacheKey($username);
        $ipAddress = $this->sanitizeForCacheKey($ipAddress);

        $userAttemptsKey = self::CACHE_PREFIX_LOGIN_ATTEMPTS.$username;
        $this->cache->remove($userAttemptsKey);

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
     * Sanitize input data to prevent XSS and injection attacks
     *
     * @param mixed $input     The input to sanitize
     * @param int   $maxLength Maximum allowed length for strings
     *
     * @return mixed Sanitized input
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
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
     */
    public function getClientIpAddress(IRequest $request): string
    {
        $ipAddress = $request->getRemoteAddress();

        $forwardedHeaders = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
        ];

        foreach ($forwardedHeaders as $header) {
            $headerValue = $request->getHeader($header);
            if (empty($headerValue) === false) {
                $ipList   = explode(',', $headerValue);
                $clientIp = trim($ipList[0]);

                $flags    = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
                $isPublic = filter_var($clientIp, FILTER_VALIDATE_IP, $flags);
                if ($isPublic !== false) {
                    $ipAddress = $clientIp;
                    break;
                }
            }
        }

        return $ipAddress;
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
                $this->logger->warning("Security event: {$event}", $context);
                break;
            case 'rate_limit_exceeded':
            case 'failed_login_attempt':
            case 'successful_login':
            default:
                $this->logger->info("Security event: {$event}", $context);
                break;
        }
    }//end logSecurityEvent()
}//end class
