<?php

/**
 * OpenRegister Notification RateLimiter
 *
 * Token-bucket rate limiter for notification dispatches, keyed on
 * (rule, recipient). Backed by the distributed cache (APCu in dev,
 * Redis in cluster). Per-bucket state is a `[tokens, lastRefill]`
 * tuple; tokens refill at a configurable rate up to the bucket
 * size. When a token isn't available the dispatch is dropped and
 * logged at info level — operators who care can grep for it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Per-(rule, recipient) token-bucket rate limiter.
 *
 * Defaults: bucket size 10, refill 1 token per 60s. Both can be
 * overridden globally via app-config and per-rule via the
 * `rateLimit` block on the notification spec. The whole limiter
 * can be killed via `notification_rate_limit_enabled = false`.
 */
class RateLimiter
{

    /**
     * Cache TTL for bucket state (24h). Stale entries get evicted
     * naturally; we never need to keep history past that.
     */
    public const STATE_TTL = 86400;

    public const APP_ID = 'openregister';

    public const CONFIG_ENABLED = 'notification_rate_limit_enabled';

    public const CONFIG_DEFAULT_BUCKET_SIZE = 'notification_rate_limit_default_bucket_size';

    public const CONFIG_DEFAULT_REFILL_SECONDS = 'notification_rate_limit_default_refill_seconds';

    public const DEFAULT_BUCKET_SIZE = 10;

    public const DEFAULT_REFILL_SECONDS = 60;

    /**
     * Distributed cache used to keep bucket state across requests
     * and (with Redis) across nodes. Null when no cache backend is
     * available — in that case the limiter fails open and never
     * drops dispatches.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Time provider — callable returning a unix timestamp in
     * seconds. Constructor-injectable so tests can advance time
     * without sleep().
     *
     * @var callable():int
     */
    private $timeProvider;

    /**
     * Constructor.
     *
     * @param ICacheFactory   $cacheFactory Distributed cache factory.
     * @param IAppConfig      $appConfig    App-config reader for kill switch + defaults.
     * @param LoggerInterface $logger       Logger for dropped-dispatch info events.
     * @param callable|null   $timeProvider Optional time source (defaults to time()).
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        ?callable $timeProvider=null
    ) {
        try {
            $this->cache = $cacheFactory->createDistributed('openregister_notification_rate_limit');
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[NotificationRateLimiter] cache backend unavailable: %s', $e->getMessage())
            );
            $this->cache = null;
        }//end try

        $this->timeProvider = ($timeProvider ?? static fn(): int => time());

    }//end __construct()

    /**
     * Try to consume one token for the given (rule, recipient).
     *
     * Returns true when the dispatch may proceed. Returns false when
     * the bucket is empty — the caller MUST then skip the dispatch.
     * Logs an info-level entry on drop so operators can grep
     * "[NotificationRateLimiter] dropped".
     *
     * Fails open: when the limiter is disabled, the cache is
     * unavailable, or a state read fails — return true so a broken
     * limiter never blocks legitimate notifications.
     *
     * @param string                    $ruleId          Stable rule identifier (annotation key).
     * @param string                    $recipient       Recipient ID (uid, group id, webhook URL hash, etc.).
     * @param array<string, mixed>|null $perRuleOverride Optional `rateLimit` block from the rule spec.
     *
     * @return bool True when the dispatch may proceed.
     */
    public function tryConsume(string $ruleId, string $recipient, ?array $perRuleOverride=null): bool
    {
        if ($this->isEnabled() === false) {
            return true;
        }

        if ($this->cache === null) {
            return true;
        }

        if ($ruleId === '' || $recipient === '') {
            // Defensive: a rule without an id can't be rate-limited
            // in a stable way. Fail open rather than silently grouping
            // unrelated rules under the empty-string key.
            return true;
        }

        [$bucketSize, $refillSeconds] = $this->resolveLimits(perRuleOverride: $perRuleOverride);

        $key = $this->key(ruleId: $ruleId, recipient: $recipient);
        $now = ($this->timeProvider)();

        try {
            $state = $this->cache->get($key);
        } catch (\Throwable $e) {
            return true;
        }//end try

        if (is_string($state) === true) {
            $decoded = json_decode($state, true);
            if (is_array($decoded) === true && isset($decoded['tokens'], $decoded['lastRefill']) === true) {
                $tokens     = (float) $decoded['tokens'];
                $lastRefill = (int) $decoded['lastRefill'];
            } else {
                $tokens     = (float) $bucketSize;
                $lastRefill = $now;
            }
        } else {
            $tokens     = (float) $bucketSize;
            $lastRefill = $now;
        }

        // Refill: one token every $refillSeconds. Cap at bucket size.
        if ($refillSeconds > 0 && $now > $lastRefill) {
            $elapsed = ($now - $lastRefill);
            $earned  = ($elapsed / $refillSeconds);
            $tokens  = min((float) $bucketSize, ($tokens + $earned));
        }

        if ($tokens < 1.0) {
            $this->logger->info(
                sprintf(
                    '[NotificationRateLimiter] dropped rule="%s" recipient="%s" bucket=%d refillSeconds=%d',
                    $ruleId,
                    $recipient,
                    $bucketSize,
                    $refillSeconds
                )
            );
            // Persist the new lastRefill so partial earnings don't
            // get lost on the next call.
            $this->persist(key: $key, tokens: $tokens, lastRefill: $now);
            return false;
        }

        $tokens -= 1.0;
        $this->persist(key: $key, tokens: $tokens, lastRefill: $now);
        return true;

    }//end tryConsume()

    /**
     * Whether the limiter is enabled. Defaults to ON.
     *
     * @return bool True when the limiter should run.
     */
    private function isEnabled(): bool
    {
        try {
            $value = $this->appConfig->getValueString(self::APP_ID, self::CONFIG_ENABLED, 'true');
        } catch (\Throwable $e) {
            return true;
        }//end try

        return ($value !== 'false' && $value !== '0');

    }//end isEnabled()

    /**
     * Resolve effective (bucketSize, refillSeconds).
     *
     * Order: per-rule override > app-config default > class default.
     *
     * @param array<string, mixed>|null $perRuleOverride Per-rule rateLimit block.
     *
     * @return array{0:int,1:int} Tuple of (bucketSize, refillSeconds).
     */
    private function resolveLimits(?array $perRuleOverride): array
    {
        $bucketSize    = self::DEFAULT_BUCKET_SIZE;
        $refillSeconds = self::DEFAULT_REFILL_SECONDS;

        try {
            $configuredBucket = (int) $this->appConfig->getValueInt(
                self::APP_ID,
                self::CONFIG_DEFAULT_BUCKET_SIZE,
                self::DEFAULT_BUCKET_SIZE
            );
            if ($configuredBucket > 0) {
                $bucketSize = $configuredBucket;
            }

            $configuredRefill = (int) $this->appConfig->getValueInt(
                self::APP_ID,
                self::CONFIG_DEFAULT_REFILL_SECONDS,
                self::DEFAULT_REFILL_SECONDS
            );
            if ($configuredRefill > 0) {
                $refillSeconds = $configuredRefill;
            }
        } catch (\Throwable $e) {
            // Fall through with class defaults.
        }//end try

        if (is_array($perRuleOverride) === true) {
            $rb = ($perRuleOverride['bucketSize'] ?? null);
            if (is_int($rb) === true && $rb > 0) {
                $bucketSize = $rb;
            } else if (is_string($rb) === true && ctype_digit($rb) === true && (int) $rb > 0) {
                $bucketSize = (int) $rb;
            }

            $rr = ($perRuleOverride['refillSecondsPerToken'] ?? null);
            if (is_int($rr) === true && $rr > 0) {
                $refillSeconds = $rr;
            } else if (is_string($rr) === true && ctype_digit($rr) === true && (int) $rr > 0) {
                $refillSeconds = (int) $rr;
            }
        }

        return [
            $bucketSize,
            $refillSeconds,
        ];

    }//end resolveLimits()

    /**
     * Build the cache key. Hashes rule + recipient so colons or
     * special chars in either don't collide with the cache key
     * separator.
     *
     * @param string $ruleId    Rule identifier.
     * @param string $recipient Recipient identifier.
     *
     * @return string Cache key.
     */
    private function key(string $ruleId, string $recipient): string
    {
        return 'notification:rate:'.sha1($ruleId.'|'.$recipient);

    }//end key()

    /**
     * Persist bucket state. Best-effort; cache write failures are
     * swallowed so a transient cache hiccup never throws into the
     * dispatch path.
     *
     * @param string $key        Cache key.
     * @param float  $tokens     Current token count.
     * @param int    $lastRefill Last refill timestamp.
     *
     * @return void
     */
    private function persist(string $key, float $tokens, int $lastRefill): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set(
                $key,
                json_encode(
                    [
                        'tokens'     => $tokens,
                        'lastRefill' => $lastRefill,
                    ]
                ),
                self::STATE_TTL
            );
        } catch (\Throwable $e) {
            // Don't escalate.
        }//end try

    }//end persist()
}//end class
