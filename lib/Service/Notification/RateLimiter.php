<?php

/**
 * RateLimiter
 *
 * Token-bucket rate limiter per (rule, recipient) pair, backed by Nextcloud's
 * distributed cache (ICacheFactory). Prevents notification abuse and system
 * overload.
 *
 * Default: bucket size 10, refill 1 token / 60 s.
 * Per-rule overrides via rateLimit: {bucketSize, refillSecondsPerToken} on the rule.
 * Operator overrides via app-config:
 *   - notification_rate_limit_default_bucket_size
 *   - notification_rate_limit_default_refill_seconds
 * Kill switch: notification_rate_limit_enabled = false
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-14
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Token-bucket rate limiter for notification dispatch.
 *
 * @psalm-suppress UnusedClass
 */
class RateLimiter
{

    /**
     * Distributed cache namespace.
     */
    private const CACHE_PREFIX = 'openregister_rl_';

    /**
     * Default bucket size (tokens).
     */
    private const DEFAULT_BUCKET_SIZE = 10;

    /**
     * Default refill interval in seconds per token.
     */
    private const DEFAULT_REFILL_SECONDS = 60;

    /**
     * Cache instance.
     *
     * @var ICache|null
     */
    private ?ICache $cache;

    /**
     * Constructor.
     *
     * @param ICacheFactory   $cacheFactory Cache factory.
     * @param IAppConfig      $appConfig    App configuration.
     * @param LoggerInterface $logger       Logger.
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {
        $this->cache = $cacheFactory->isAvailable() === true ? $cacheFactory->createDistributed(prefix: self::CACHE_PREFIX) : null;
    }//end __construct()

    /**
     * Check whether a dispatch is allowed and consume one token if so.
     *
     * @param string               $ruleId    Rule identifier.
     * @param string               $recipient Recipient identifier.
     * @param array<string, mixed> $ruleSpec  Optional rateLimit override from rule spec.
     *
     * @return bool True when dispatch is allowed; false when rate-limited.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-14
     */
    public function allow(string $ruleId, string $recipient, array $ruleSpec=[]): bool
    {
        if ($this->isEnabled() === false) {
            return true;
        }

        if ($ruleId === '' || $recipient === '') {
            return true;
        }

        $bucketSize    = $this->resolveBucketSize(ruleSpec: $ruleSpec);
        $refillSeconds = $this->resolveRefillSeconds(ruleSpec: $ruleSpec);
        $cacheKey      = $this->cacheKey(ruleId: $ruleId, recipient: $recipient);

        try {
            $state = $this->readState(cacheKey: $cacheKey, bucketSize: $bucketSize);
            $state = $this->refill(state: $state, bucketSize: $bucketSize, refillSeconds: $refillSeconds);

            if ($state['tokens'] < 1) {
                $this->logger->info(
                    message: 'Notification rate-limited',
                    context: ['rule' => $ruleId, 'recipient' => $recipient]
                );
                return false;
            }

            $state['tokens'] -= 1;
            $this->writeState(cacheKey: $cacheKey, state: $state, ttl: $bucketSize * $refillSeconds + 60);
            return true;
        } catch (\Throwable $e) {
            // Fail open: cache unavailable means no rate limiting.
            $this->logger->info(
                message: 'RateLimiter cache failure, failing open',
                context: ['exception' => $e->getMessage()]
            );
            return true;
        }//end try
    }//end allow()

    /**
     * Check if rate limiting is enabled.
     *
     * @return bool
     */
    private function isEnabled(): bool
    {
        return $this->appConfig->getValueString(
            app: 'openregister',
            key: 'notification_rate_limit_enabled',
            default: 'true'
        ) !== 'false';
    }//end isEnabled()

    /**
     * Resolve the bucket size from rule spec or app-config.
     *
     * @param array<string, mixed> $ruleSpec Rule specification.
     *
     * @return int
     */
    private function resolveBucketSize(array $ruleSpec): int
    {
        if (isset($ruleSpec['rateLimit']['bucketSize']) === true) {
            return (int) $ruleSpec['rateLimit']['bucketSize'];
        }

        return (int) $this->appConfig->getValueString(
            app: 'openregister',
            key: 'notification_rate_limit_default_bucket_size',
            default: (string) self::DEFAULT_BUCKET_SIZE
        );
    }//end resolveBucketSize()

    /**
     * Resolve the refill seconds from rule spec or app-config.
     *
     * @param array<string, mixed> $ruleSpec Rule specification.
     *
     * @return int
     */
    private function resolveRefillSeconds(array $ruleSpec): int
    {
        if (isset($ruleSpec['rateLimit']['refillSecondsPerToken']) === true) {
            return (int) $ruleSpec['rateLimit']['refillSecondsPerToken'];
        }

        return (int) $this->appConfig->getValueString(
            app: 'openregister',
            key: 'notification_rate_limit_default_refill_seconds',
            default: (string) self::DEFAULT_REFILL_SECONDS
        );
    }//end resolveRefillSeconds()

    /**
     * Build a cache key for a (rule, recipient) pair.
     *
     * @param string $ruleId    Rule identifier.
     * @param string $recipient Recipient identifier.
     *
     * @return string
     */
    private function cacheKey(string $ruleId, string $recipient): string
    {
        return md5(string: $ruleId.'::'.$recipient);
    }//end cacheKey()

    /**
     * Read current bucket state from cache, or initialise a full bucket.
     *
     * @param string $cacheKey   Cache key.
     * @param int    $bucketSize Full bucket token count.
     *
     * @return array{tokens: int, lastRefill: int}
     */
    private function readState(string $cacheKey, int $bucketSize): array
    {
        if ($this->cache === null) {
            return ['tokens' => $bucketSize, 'lastRefill' => time()];
        }

        $raw = $this->cache->get(key: $cacheKey);
        if (is_array(value: $raw) === false) {
            return ['tokens' => $bucketSize, 'lastRefill' => time()];
        }

        return $raw;
    }//end readState()

    /**
     * Refill tokens based on elapsed time.
     *
     * @param array{tokens: int, lastRefill: int} $state         Current state.
     * @param int                                 $bucketSize    Max tokens.
     * @param int                                 $refillSeconds Seconds per token.
     *
     * @return array{tokens: int, lastRefill: int}
     */
    private function refill(array $state, int $bucketSize, int $refillSeconds): array
    {
        $now     = time();
        $elapsed = max(value: 0, value2: $now - $state['lastRefill']);
        $toAdd   = (int) floor(num: $elapsed / max(value: 1, value2: $refillSeconds));

        if ($toAdd > 0) {
            $state['tokens']     = min(value: $bucketSize, value2: $state['tokens'] + $toAdd);
            $state['lastRefill'] = $now;
        }

        return $state;
    }//end refill()

    /**
     * Persist bucket state to cache.
     *
     * @param string                              $cacheKey Cache key.
     * @param array{tokens: int, lastRefill: int} $state    Bucket state.
     * @param int                                 $ttl      TTL in seconds.
     *
     * @return void
     */
    private function writeState(string $cacheKey, array $state, int $ttl): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->cache->set(key: $cacheKey, value: $state, ttl: $ttl);
    }//end writeState()
}//end class
