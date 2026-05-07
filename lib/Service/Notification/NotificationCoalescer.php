<?php

/**
 * OpenRegister Notification Coalescer.
 *
 * Per-(rule, recipient) debounce service that coalesces a burst of
 * notifications into a single dispatch. The first event in a window
 * fires; subsequent events within the same window are silently
 * absorbed (a counter is bumped so the eventual dispatch can render
 * "5 changes in the last minute" if the channel cares).
 *
 * Closes the `notificatie-engine` spec's
 * "Notification grouping MUST reduce noise for related events"
 * requirement. Wired into `AnnotationNotificationDispatcher` as an
 * optional dependency — when absent or when no rule declares
 * `coalesce`, dispatch behaves as before.
 *
 * Backed by the same distributed cache used by `RateLimiter` so the
 * window state survives across requests + (with Redis) across
 * cluster nodes.
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
 * Per-(rule, recipient) debounce coalescer.
 *
 * Schema authors enable coalescing via
 * `coalesce: {windowSeconds: <int>, maxEvents?: <int>}` on the
 * notification spec. When configured, the first event opens the
 * window + fires the dispatch; subsequent events while the window
 * is open are silenced and only bump the counter. After the window
 * elapses (or `maxEvents` is reached), the next event opens a fresh
 * window.
 *
 * The whole coalescer can be killed via app-config
 * `notification_coalesce_enabled = false`.
 */
class NotificationCoalescer
{

    public const APP_ID = 'openregister';

    public const CONFIG_ENABLED = 'notification_coalesce_enabled';

    public const STATE_TTL = 86400;

    /**
     * Distributed cache used to keep window state across requests.
     *
     * Null when no cache backend is available — in that case the
     * coalescer fails open and never silences a dispatch.
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
     * @param IAppConfig      $appConfig    App-config reader for kill switch.
     * @param LoggerInterface $logger       Logger for silenced-dispatch info events.
     * @param callable|null   $timeProvider Optional time source (defaults to time()).
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        ?callable $timeProvider=null
    ) {
        try {
            $this->cache = $cacheFactory->createDistributed('openregister_notification_coalesce');
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[NotificationCoalescer] cache backend unavailable: %s', $e->getMessage())
            );
            $this->cache = null;
        }//end try

        $this->timeProvider = ($timeProvider ?? static fn(): int => time());

    }//end __construct()

    /**
     * Decide whether the dispatch may proceed.
     *
     * Returns true when this is the first event in the window (the
     * dispatch fires + the window opens). Returns false when the
     * dispatch should be silenced (the window is open + the counter
     * has been bumped).
     *
     * Fails open: when the coalescer is disabled, the cache is
     * unavailable, no rule-level config is provided, or a state read
     * fails — return true so a broken coalescer never silences
     * legitimate notifications.
     *
     * @param string                    $ruleId          Stable rule identifier (annotation key).
     * @param string                    $recipient       Recipient ID (uid, `__webhook__`, etc.).
     * @param array<string, mixed>|null $perRuleOverride Optional `coalesce` block from the rule spec.
     *
     * @return bool True when the dispatch may proceed.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function shouldDispatch(string $ruleId, string $recipient, ?array $perRuleOverride): bool
    {
        // No rule-level config = no coalescing for this rule.
        if (is_array($perRuleOverride) === false) {
            return true;
        }

        if ($this->isEnabled() === false) {
            return true;
        }

        if ($this->cache === null) {
            return true;
        }

        if ($ruleId === '' || $recipient === '') {
            // Defensive: empty keys would lump unrelated rules together.
            return true;
        }

        $windowSeconds = $this->resolveWindowSeconds(perRuleOverride: $perRuleOverride);
        if ($windowSeconds <= 0) {
            return true;
        }

        $maxEvents = $this->resolveMaxEvents(perRuleOverride: $perRuleOverride);

        $key = $this->key(ruleId: $ruleId, recipient: $recipient);
        $now = ($this->timeProvider)();

        try {
            $state = $this->cache->get($key);
        } catch (\Throwable $e) {
            return true;
        }//end try

        $count   = 0;
        $opened  = 0;
        $decoded = null;
        if (is_string($state) === true) {
            $decoded = json_decode($state, true);
        }

        if (is_array($decoded) === true && isset($decoded['count'], $decoded['opened']) === true) {
            $count  = (int) $decoded['count'];
            $opened = (int) $decoded['opened'];
        }

        // Window closed (or never opened) — fire + open a new window.
        if ($count === 0 || ($now - $opened) >= $windowSeconds) {
            $this->persist(key: $key, count: 1, opened: $now);
            return true;
        }

        // Bursting past maxEvents inside an open window: force a flush
        // dispatch + reset the window. Schema authors set this when
        // they want a "you've had N updates already" digest fire.
        if ($maxEvents !== null && ($count + 1) >= $maxEvents) {
            $this->persist(key: $key, count: 1, opened: $now);
            return true;
        }

        // Inside the window — silence the dispatch + bump the counter.
        $this->persist(key: $key, count: ($count + 1), opened: $opened);
        $this->logger->info(
            sprintf(
                '[NotificationCoalescer] silenced rule="%s" recipient="%s" count=%d windowSeconds=%d',
                $ruleId,
                $recipient,
                ($count + 1),
                $windowSeconds
            )
        );
        return false;

    }//end shouldDispatch()

    /**
     * Inspect the current coalesce state for a (rule, recipient).
     *
     * Mostly useful for tests + admin UIs that want to render
     * "12 events suppressed in this window".
     *
     * @param string $ruleId    Rule identifier.
     * @param string $recipient Recipient identifier.
     *
     * @return array{count:int,opened:int}|null Null when no state exists.
     */
    public function inspect(string $ruleId, string $recipient): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        if ($ruleId === '' || $recipient === '') {
            return null;
        }

        try {
            $state = $this->cache->get($this->key(ruleId: $ruleId, recipient: $recipient));
        } catch (\Throwable $e) {
            return null;
        }//end try

        if (is_string($state) === false) {
            return null;
        }

        $decoded = json_decode($state, true);
        if (is_array($decoded) === false || isset($decoded['count'], $decoded['opened']) === false) {
            return null;
        }

        return [
            'count'  => (int) $decoded['count'],
            'opened' => (int) $decoded['opened'],
        ];

    }//end inspect()

    /**
     * Whether the coalescer is enabled. Defaults to ON.
     *
     * @return bool True when the coalescer should run.
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
     * Extract `windowSeconds` from the rule's coalesce block.
     *
     * @param array<string, mixed> $perRuleOverride The rule's `coalesce` config block.
     *
     * @return int Window length in seconds (0 = disabled).
     */
    private function resolveWindowSeconds(array $perRuleOverride): int
    {
        $value = ($perRuleOverride['windowSeconds'] ?? null);
        if (is_int($value) === true && $value > 0) {
            return $value;
        }

        if (is_string($value) === true && ctype_digit($value) === true && (int) $value > 0) {
            return (int) $value;
        }

        return 0;

    }//end resolveWindowSeconds()

    /**
     * Extract `maxEvents` from the rule's coalesce block.
     *
     * @param array<string, mixed> $perRuleOverride The rule's `coalesce` config block.
     *
     * @return int|null Max events (null = no cap).
     */
    private function resolveMaxEvents(array $perRuleOverride): ?int
    {
        $value = ($perRuleOverride['maxEvents'] ?? null);
        if (is_int($value) === true && $value > 0) {
            return $value;
        }

        if (is_string($value) === true && ctype_digit($value) === true && (int) $value > 0) {
            return (int) $value;
        }

        return null;

    }//end resolveMaxEvents()

    /**
     * Build the cache key for a (rule, recipient) bucket.
     *
     * @param string $ruleId    Rule identifier.
     * @param string $recipient Recipient identifier.
     *
     * @return string The cache key.
     */
    private function key(string $ruleId, string $recipient): string
    {
        return 'coalesce:'.sha1($ruleId.'|'.$recipient);

    }//end key()

    /**
     * Persist the window state.
     *
     * @param string $key    Cache key.
     * @param int    $count  Event count in the current window.
     * @param int    $opened Unix timestamp the window opened.
     *
     * @return void
     */
    private function persist(string $key, int $count, int $opened): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set(
                $key,
                json_encode(
                    [
                        'count'  => $count,
                        'opened' => $opened,
                    ]
                ),
                self::STATE_TTL
            );
        } catch (\Throwable $e) {
            // Persistence failure is best-effort — log + move on.
            $this->logger->warning(
                sprintf('[NotificationCoalescer] persist failed: %s', $e->getMessage())
            );
        }//end try

    }//end persist()
}//end class
