<?php

/**
 * NotificationCoalescer
 *
 * Per-(rule, recipient) debounce window that suppresses notification storms.
 * Backed by Nextcloud's distributed cache.
 *
 * Schema authors enable coalescing via:
 *   coalesce: {windowSeconds: <int>, maxEvents?: <int>}
 *
 * First event in a window fires + opens; subsequent events are silenced.
 * Optional maxEvents forces an early flush.
 * Kill switch: notification_coalesce_enabled = false.
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Debounce-window coalescer for notification dispatch.
 *
 * @psalm-suppress UnusedClass
 */
class NotificationCoalescer
{

    /**
     * Cache prefix.
     */
    private const CACHE_PREFIX = 'openregister_coalesce_';

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
     * Decide whether to dispatch or coalesce.
     *
     * Returns true when the dispatch SHOULD proceed (first event in window, or window expired,
     * or maxEvents exceeded).
     * Returns false when the event is silenced by the open window.
     *
     * Null ruleSpec means "no coalescing configured" — always returns true.
     *
     * @param string               $ruleId    Rule identifier.
     * @param string               $recipient Recipient identifier (uid or __webhook__ / __talk__).
     * @param array<string, mixed> $ruleSpec  Rule spec (must contain 'coalesce' key to activate).
     *
     * @return bool
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-12
     */
    public function shouldDispatch(string $ruleId, string $recipient, array $ruleSpec=[]): bool
    {
        if ($this->isEnabled() === false) {
            return true;
        }

        if ($ruleId === '' || $recipient === '') {
            return true;
        }

        $coalesceConfig = $ruleSpec['coalesce'] ?? null;
        if (is_array(value: $coalesceConfig) === false || isset($coalesceConfig['windowSeconds']) === false) {
            return true;
        }

        $windowSeconds = (int) $coalesceConfig['windowSeconds'];
        $maxEvents     = isset($coalesceConfig['maxEvents']) === true ? (int) $coalesceConfig['maxEvents'] : null;
        $cacheKey      = $this->cacheKey(ruleId: $ruleId, recipient: $recipient);

        try {
            $state = $this->readState(cacheKey: $cacheKey);

            if ($state === null) {
                // No open window — first event fires and opens window.
                $this->writeState(
                    cacheKey: $cacheKey,
                    state: ['openedAt' => time(), 'count' => 1],
                    ttl: $windowSeconds + 5
                );
                return true;
            }

            // Window expired?
            if ((time() - $state['openedAt']) >= $windowSeconds) {
                $this->writeState(
                    cacheKey: $cacheKey,
                    state: ['openedAt' => time(), 'count' => 1],
                    ttl: $windowSeconds + 5
                );
                return true;
            }

            // MaxEvents flush?
            $newCount = $state['count'] + 1;
            if ($maxEvents !== null && $newCount >= $maxEvents) {
                $this->cache?->remove(key: $cacheKey);
                $this->logger->info(
                    message: 'Notification coalesce maxEvents reached, early flush',
                    context: ['rule' => $ruleId, 'recipient' => $recipient, 'count' => $newCount]
                );
                return true;
            }

            // Silence.
            $state['count'] = $newCount;
            $this->writeState(cacheKey: $cacheKey, state: $state, ttl: $windowSeconds + 5);
            $this->logger->info(
                message: 'Notification coalesced (silenced)',
                context: ['rule' => $ruleId, 'recipient' => $recipient]
            );
            return false;
        } catch (\Throwable $e) {
            // Fail open.
            $this->logger->info(
                message: 'NotificationCoalescer cache failure, failing open',
                context: ['exception' => $e->getMessage()]
            );
            return true;
        }//end try
    }//end shouldDispatch()

    /**
     * Return the current coalesce state for a (rule, recipient) pair.
     *
     * @param string $ruleId    Rule identifier.
     * @param string $recipient Recipient identifier.
     *
     * @return array{openedAt: int, count: int}|null Null when no active window.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-12
     */
    public function inspect(string $ruleId, string $recipient): ?array
    {
        $cacheKey = $this->cacheKey(ruleId: $ruleId, recipient: $recipient);
        return $this->readState(cacheKey: $cacheKey);
    }//end inspect()

    /**
     * Check if coalescing is enabled.
     *
     * @return bool
     */
    private function isEnabled(): bool
    {
        return $this->appConfig->getValueString(
            app: 'openregister',
            key: 'notification_coalesce_enabled',
            default: 'true'
        ) !== 'false';
    }//end isEnabled()

    /**
     * Build a cache key.
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
     * Read coalesce state from cache.
     *
     * @param string $cacheKey Cache key.
     *
     * @return array{openedAt: int, count: int}|null
     */
    private function readState(string $cacheKey): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        $raw = $this->cache->get(key: $cacheKey);
        if (is_array(value: $raw) === false) {
            return null;
        }

        return $raw;
    }//end readState()

    /**
     * Write coalesce state to cache.
     *
     * @param string                           $cacheKey Cache key.
     * @param array{openedAt: int, count: int} $state    State to persist.
     * @param int                              $ttl      TTL in seconds.
     *
     * @return void
     */
    private function writeState(string $cacheKey, array $state, int $ttl): void
    {
        $this->cache?->set(key: $cacheKey, value: $state, ttl: $ttl);
    }//end writeState()
}//end class
