<?php

/**
 * OpenRegister AnnotationNotificationDispatcher
 *
 * Reads `x-openregister-notifications` from the schema and fires
 * Nextcloud INotificationManager notifications when a triggering event
 * matches. v1 supports trigger types created/updated/transition,
 * recipient kinds users/field, channel `nc-notification`.
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

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\DuplicateDispatchException;
use OCA\OpenRegister\Db\NotificationDispatchLogMapper;
use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Reads notification annotations and dispatches matching ones.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class AnnotationNotificationDispatcher
{
    /**
     * Constructor.
     *
     * @param SchemaMapper                                             $schemaMapper        Mapper used to resolve the object's schema.
     * @param INotificationManager                                     $notificationManager Nextcloud notification API.
     * @param LoggerInterface                                          $logger              Logger for dispatch diagnostics.
     * @param IGroupManager                                            $groupManager        Group resolver for `groups` recipient kinds.
     * @param IUserManager                                             $userManager         User resolver for `users` recipient kinds.
     * @param IMailer                                                  $mailer              Mailer for the `email` channel.
     * @param IActivityManager                                         $activityManager     Activity manager for the `activity` channel.
     * @param IClientService                                           $httpClient          HTTP client for the `webhook` and `talk` channels.
     * @param IServerContainer                                         $serverContainer     Server container for expression resolvers (F06).
     * @param RateLimiter|null                                         $rateLimiter         Optional rate limiter (per-rule, per-recipient).
     * @param IConfig|null                                             $config              Optional config service for runtime tunables.
     * @param NotificationHistoryMapper|null                           $historyMapper       Optional history mapper for delivery audit rows.
     * @param NotificationCoalescer|null                               $coalescer           Optional coalescer for burst suppression.
     * @param \OCA\OpenRegister\Db\NotificationSubscriptionMapper|null $subscriptionMapper  Optional subscription mapper for opt-in filtering.
     * @param NotificationDispatchLogMapper|null                       $dispatchLogMapper   Optional dispatch-log mapper for idempotency-key dedup.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI-injected dependencies.
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IMailer $mailer,
        private readonly IActivityManager $activityManager,
        private readonly IClientService $httpClient,
        private readonly IServerContainer $serverContainer,
        private readonly ?RateLimiter $rateLimiter=null,
        private readonly ?IConfig $config=null,
        private readonly ?NotificationHistoryMapper $historyMapper=null,
        private readonly ?NotificationCoalescer $coalescer=null,
        private readonly ?\OCA\OpenRegister\Db\NotificationSubscriptionMapper $subscriptionMapper=null,
        private readonly ?NotificationDispatchLogMapper $dispatchLogMapper=null
    ) {
    }//end __construct()

    /**
     * Fire any notifications declared on the schema whose trigger matches.
     *
     * @param ObjectEntity         $object  The object the event happened on.
     * @param string               $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
     * @param array<string, mixed> $context Trigger-specific extras (e.g. `action`, `from`, `to`,
     *                                      `_newData`, `_oldData` for calculatedChange).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function dispatch(ObjectEntity $object, string $trigger, array $context=[]): void
    {
        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return;
        }

        $notifications = $this->getAnnotation(schema: $schema);
        if ($notifications === null) {
            return;
        }

        $data = $object->getObject() ?? [];

        foreach ($notifications as $name => $spec) {
            if (is_array($spec) === false) {
                continue;
            }

            $matches = $this->matches(
                triggerSpec: $spec['trigger'] ?? [],
                trigger: $trigger,
                context: $context
            );
            if ($matches === false) {
                continue;
            }

            // Organisation pinning: when the rule declares an
            // `organisation` field, the dispatch is skipped unless the
            // object lives in that organisation. Closes the spec's
            // "Notifications MUST be scoped to organisations for
            // multi-tenant deployments" requirement — RBAC already
            // implicitly scopes the recipient resolver, but explicit
            // org-pinning lets schema authors declare "this rule only
            // fires for objects belonging to org X" without writing a
            // custom expression resolver. Accepts a string (single org
            // UUID/slug) or an array of strings (any-of matching).
            if ($this->organisationGateAllows(spec: $spec, object: $object) === false) {
                continue;
            }

            // Idempotency-key dedup: when the rule declares an
            // `idempotencyKey` template, resolve it against the object
            // and CLAIM the slot in the dispatch log BEFORE sending.
            //
            // The previous design checked the log first, sent, then
            // recorded after success — that left a TOCTOU window where
            // two concurrent dispatchers could both pass the check, both
            // send, then both try to record. With the unique
            // (notification_slug, idempotency_key) index installed in
            // Version1Date20260511120000, claim-first turns the index
            // into the authoritative serialisation point: only the
            // dispatcher whose INSERT wins proceeds.
            //
            // Trade-off acknowledged: a failed send after a successful
            // claim leaves a dedup row that prevents retry until the
            // window expires. That is preferable to double-sending
            // under concurrency (which is what the prior order did).
            $idempotencyKeyTemplate = ($spec['idempotencyKey'] ?? null);
            $resolvedIdempotencyKey = null;
            if (is_string($idempotencyKeyTemplate) === true && $idempotencyKeyTemplate !== '') {
                $resolvedIdempotencyKey = $this->resolveIdempotencyKey(
                    template: $idempotencyKeyTemplate,
                    object: $object,
                    data: $data
                );
                if ($this->claimIdempotencyKey(slug: (string) $name, key: $resolvedIdempotencyKey) === false) {
                    $this->logger->info(
                        sprintf(
                            '[AnnotationNotificationDispatcher] deduplicated rule="%s" key="%s"',
                            $name,
                            $resolvedIdempotencyKey
                        )
                    );
                    continue;
                }
            }

            $recipients = $this->resolveRecipients(
                recipientsSpec: ($spec['recipients'] ?? []),
                data: $data,
                object: $object,
                context: $context
            );
            // Subscription gate: when the rule opts into subscription
            // filtering via `requiresSubscription: true`, intersect
            // the resolved recipients with the set of users who have
            // subscribed to this object's (register, schema). Anonymous
            // / non-uid recipients are passed through unchanged because
            // subscriptions are user-scoped only.
            if (($spec['requiresSubscription'] ?? false) === true) {
                $recipients = $this->filterBySubscription(
                    recipients: $recipients,
                    object: $object
                );
            }

            if (count($recipients) === 0) {
                continue;
            }

            $subjectTemplate = $spec['subject'] ?? (string) $name;
            // Pre-render the broadcast (webhook/talk) subject using the
            // default locale fallback chain — these channels don't have
            // a per-recipient locale, so they use the spec's `defaultLocale`
            // (or the first available locale, or the legacy string form).
            $broadcastSubject = $this->resolveLocalizedSubject(
                template: $subjectTemplate,
                locale: null,
                data: $data,
                context: $context,
                fallbackName: (string) $name
            );
            $channels         = (array) ($spec['channels'] ?? ['nc-notification']);

            $rateLimit = is_array($spec['rateLimit'] ?? null) === true ? $spec['rateLimit'] : null;
            $coalesce  = is_array($spec['coalesce'] ?? null) === true ? $spec['coalesce'] : null;
            $ruleId    = (string) $name;

            // Webhook is fired once per dispatch, not once per recipient,
            // and includes the recipient list in the payload.
            if (in_array('webhook', $channels, true) === true) {
                $this->dispatchBroadcastChannel(
                    channel: 'webhook',
                    ruleId: $ruleId,
                    recipientKey: '__webhook__',
                    rateLimit: $rateLimit,
                    coalesce: $coalesce,
                    object: $object,
                    spec: $spec,
                    notificationName: (string) $name,
                    broadcastSubject: $broadcastSubject,
                    recipients: $recipients,
                    context: $context
                );
            }

            // Talk channel is fired once per dispatch (chat message goes
            // to the configured Talk room, recipients aren't @-mentioned).
            if (in_array('talk', $channels, true) === true) {
                $this->dispatchBroadcastChannel(
                    channel: 'talk',
                    ruleId: $ruleId,
                    recipientKey: '__talk__',
                    rateLimit: $rateLimit,
                    coalesce: $coalesce,
                    object: $object,
                    spec: $spec,
                    notificationName: (string) $name,
                    broadcastSubject: $broadcastSubject,
                    recipients: $recipients,
                    context: $context
                );
            }

            foreach ($recipients as $uid) {
                // Per-recipient rate limit gates every channel for this uid.
                $allowed = $this->rateLimitAllows(ruleId: $ruleId, recipient: $uid, rateLimit: $rateLimit);
                if ($allowed === false) {
                    $this->recordHistoryAcrossChannels(
                        ruleId: $ruleId,
                        recipient: $uid,
                        channels: $channels,
                        broadcastChannels: ['webhook', 'talk'],
                        status: 'rate-limited',
                        object: $object,
                        subject: null,
                        locale: null
                    );
                    continue;
                }

                // Resolve the recipient's locale (NL/EN supported by
                // default; spec's `defaultLocale` falls back when the
                // user has no preference set or set a locale not
                // declared in the subject map).
                $recipientLocale  = $this->resolveUserLocale(uid: $uid);
                $recipientSubject = $this->resolveLocalizedSubject(
                    template: $subjectTemplate,
                    locale: $recipientLocale,
                    data: $data,
                    context: $context,
                    fallbackName: (string) $name
                );

                // Per-recipient coalesce: silences duplicate dispatches
                // inside the configured window. Applied to every
                // per-recipient channel (nc-notification, email,
                // activity) at once because the user-facing noise is
                // what we're collapsing.
                if ($this->coalesceAllows(ruleId: $ruleId, recipient: $uid, coalesce: $coalesce) === false) {
                    $this->recordHistoryAcrossChannels(
                        ruleId: $ruleId,
                        recipient: $uid,
                        channels: $channels,
                        broadcastChannels: ['webhook', 'talk'],
                        status: 'coalesced',
                        object: $object,
                        subject: $recipientSubject,
                        locale: $recipientLocale
                    );
                    continue;
                }

                if (in_array('nc-notification', $channels, true) === true) {
                    $this->emitNotification(
                        uid: $uid,
                        objectId: (string) ($object->getUuid() ?? ''),
                        name: (string) $name,
                        subject: $recipientSubject,
                        parameters: $context
                    );
                    $this->recordHistory(
                        ruleId: $ruleId,
                        channel: 'nc-notification',
                        recipient: $uid,
                        status: 'dispatched',
                        object: $object,
                        subject: $recipientSubject,
                        locale: $recipientLocale
                    );
                }

                if (in_array('email', $channels, true) === true) {
                    $this->emitEmail(
                        uid: $uid,
                        subject: $recipientSubject,
                        body: $recipientSubject
                    );
                    $this->recordHistory(
                        ruleId: $ruleId,
                        channel: 'email',
                        recipient: $uid,
                        status: 'dispatched',
                        object: $object,
                        subject: $recipientSubject,
                        locale: $recipientLocale
                    );
                }

                if (in_array('activity', $channels, true) === true) {
                    $this->emitActivity(
                        uid: $uid,
                        objectId: (string) ($object->getUuid() ?? ''),
                        name: (string) $name,
                        subject: $recipientSubject
                    );
                    $this->recordHistory(
                        ruleId: $ruleId,
                        channel: 'activity',
                        recipient: $uid,
                        status: 'dispatched',
                        object: $object,
                        subject: $recipientSubject,
                        locale: $recipientLocale
                    );
                }
            }//end foreach
        }//end foreach

    }//end dispatch()

    /**
     * Helper to dispatch a broadcast-style channel (webhook / talk).
     *
     * Centralises the rate-limit + coalesce + history-recording
     * pattern that webhook and talk share — they both go to a single
     * shared endpoint, so they're rate-limited once per dispatch
     * (not once per recipient) and recorded as a single
     * `__webhook__` / `__talk__` history row.
     *
     * @param string                    $channel          'webhook' | 'talk'.
     * @param string                    $ruleId           The rule id.
     * @param string                    $recipientKey     '__webhook__' | '__talk__'.
     * @param array<string, mixed>|null $rateLimit        Per-rule rate-limit override.
     * @param array<string, mixed>|null $coalesce         Per-rule coalesce override.
     * @param ObjectEntity              $object           The triggering object.
     * @param array<string, mixed>      $spec             The full notification spec.
     * @param string                    $notificationName The annotation key.
     * @param string                    $broadcastSubject Pre-rendered broadcast subject.
     * @param array<int, string>        $recipients       Resolved recipient uids.
     * @param array<string, mixed>      $context          Trigger context (action, from, to).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    private function dispatchBroadcastChannel(
        string $channel,
        string $ruleId,
        string $recipientKey,
        ?array $rateLimit,
        ?array $coalesce,
        ObjectEntity $object,
        array $spec,
        string $notificationName,
        string $broadcastSubject,
        array $recipients,
        array $context
    ): void {
        if ($this->rateLimitAllows(ruleId: $ruleId, recipient: $recipientKey, rateLimit: $rateLimit) === false) {
            $this->recordHistory(
                ruleId: $ruleId,
                channel: $channel,
                recipient: $recipientKey,
                status: 'rate-limited',
                object: $object,
                subject: $broadcastSubject,
                locale: null
            );
            return;
        }

        if ($this->coalesceAllows(ruleId: $ruleId, recipient: $recipientKey, coalesce: $coalesce) === false) {
            $this->recordHistory(
                ruleId: $ruleId,
                channel: $channel,
                recipient: $recipientKey,
                status: 'coalesced',
                object: $object,
                subject: $broadcastSubject,
                locale: null
            );
            return;
        }

        if ($channel === 'webhook') {
            $this->emitWebhook(
                spec: $spec,
                object: $object,
                notificationName: $notificationName,
                subject: $broadcastSubject,
                recipients: $recipients,
                context: $context
            );
        } else if ($channel === 'talk') {
            $this->emitTalk(spec: $spec, message: $broadcastSubject);
        }

        $this->recordHistory(
            ruleId: $ruleId,
            channel: $channel,
            recipient: $recipientKey,
            status: 'dispatched',
            object: $object,
            subject: $broadcastSubject,
            locale: null
        );

    }//end dispatchBroadcastChannel()

    /**
     * Apply the (optional) rate limiter. Returns true when the
     * dispatch may proceed. A null limiter (test contexts where the
     * dependency wasn't injected) always allows — keeps existing
     * tests passing without forcing every test to construct a
     * RateLimiter.
     *
     * @param string                    $ruleId    Rule identifier (notification annotation key).
     * @param string                    $recipient Recipient identifier.
     * @param array<string, mixed>|null $rateLimit Per-rule override block.
     *
     * @return bool True when the dispatch may proceed.
     */
    private function rateLimitAllows(string $ruleId, string $recipient, ?array $rateLimit): bool
    {
        if ($this->rateLimiter === null) {
            return true;
        }

        return $this->rateLimiter->tryConsume(ruleId: $ruleId, recipient: $recipient, perRuleOverride: $rateLimit);

    }//end rateLimitAllows()

    /**
     * Apply the (optional) coalescer. Returns true when the dispatch
     * may proceed.
     *
     * A null coalescer (test contexts where the dependency wasn't
     * injected) always allows, as does a missing per-rule
     * `coalesce` config block (the rule simply opted out of grouping).
     *
     * @param string                    $ruleId    Rule identifier (notification annotation key).
     * @param string                    $recipient Recipient identifier.
     * @param array<string, mixed>|null $coalesce  Per-rule coalesce block.
     *
     * @return bool True when the dispatch may proceed.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md
     */
    private function coalesceAllows(string $ruleId, string $recipient, ?array $coalesce): bool
    {
        if ($this->coalescer === null) {
            return true;
        }

        return $this->coalescer->shouldDispatch(ruleId: $ruleId, recipient: $recipient, perRuleOverride: $coalesce);

    }//end coalesceAllows()

    /**
     * Claim the idempotency slot for (slug, key) atomically.
     *
     * Inserts the dedup row up-front so the unique
     * (notification_slug, idempotency_key) index is the authoritative
     * serialisation point under concurrency. Returns true when the
     * claim succeeded (caller may dispatch) and false when the row
     * already exists within the dedup window (caller must skip).
     *
     * A null mapper (test contexts, older fixtures) always allows so
     * no test has to construct the mapper just to pass the guard.
     *
     * Side-effects:
     *   - Runs a best-effort prune before claiming so the table does
     *     not grow unboundedly without a separate cron job.
     *   - On non-duplicate DB error returns true (the dispatch should
     *     not be blocked by infrastructure failure) and logs at warning
     *     level so the operator can investigate.
     *
     * @param string $slug The notification annotation key.
     * @param string $key  The resolved idempotency key.
     *
     * @return bool True when the dispatch may proceed (claim succeeded
     *              or mapper unavailable); false when a competing
     *              dispatcher already claimed this (slug, key).
     */
    private function claimIdempotencyKey(string $slug, string $key): bool
    {
        if ($this->dispatchLogMapper === null) {
            return true;
        }

        // Prune expired rows lazily — best-effort, failures are swallowed
        // inside the mapper.
        $this->dispatchLogMapper->pruneExpired();

        try {
            $this->dispatchLogMapper->record(
                notificationSlug: $slug,
                idempotencyKey: $key
            );
            return true;
        } catch (DuplicateDispatchException) {
            // Concurrent dispatcher beat us to the (slug, key) slot, or
            // a previous send within the window already recorded it.
            // Either way: do not dispatch.
            return false;
        } catch (\Throwable $e) {
            // Genuine DB failure (table missing in test fixtures, etc.).
            // Fail-open: dispatch proceeds so a transient infra issue
            // doesn't silently drop user-visible notifications.
            $this->logger->warning(
                sprintf(
                    '[AnnotationNotificationDispatcher] idempotency claim failed (slug=%s key=%s): %s',
                    $slug,
                    $key,
                    $e->getMessage()
                )
            );
            return true;
        }//end try

    }//end claimIdempotencyKey()

    /**
     * Resolve a `${@self.<field>}` idempotency-key template against the object.
     *
     * The template syntax mirrors the spec example:
     *   `${@self.id}-T30-${@self.dueDate}`
     *
     * Each `${@self.<field>}` token is replaced with the value of
     * `<field>` from the object's stored data (or the object's built-in
     * accessor for `id` and `uuid`). Unknown tokens are replaced with
     * an empty string so the template never returns null.
     *
     * Values are cast to string and limited to 128 characters each to
     * avoid the 512-char column limit being hit by adversarial data.
     *
     * @param string               $template Raw idempotency-key template.
     * @param ObjectEntity         $object   Owning object.
     * @param array<string, mixed> $data     Pre-fetched object data array.
     *
     * @return string The resolved key.
     */
    private function resolveIdempotencyKey(string $template, ObjectEntity $object, array $data): string
    {
        return preg_replace_callback(
            '/\$\{@self\.([a-zA-Z0-9_.-]+)\}/',
            static function (array $matches) use ($object, $data): string {
                $field = $matches[1];

                // Built-in accessors for the most common fields.
                if ($field === 'id' || $field === 'uuid') {
                    return substr((string) ($object->getUuid() ?? ''), 0, 128);
                }

                // Fall through to the stored object data.
                $value = ($data[$field] ?? null);
                if ($value === null) {
                    return '';
                }

                if (is_scalar($value) === false) {
                    return '';
                }

                return substr((string) $value, 0, 128);
            },
            $template
        ) ?? $template;

    }//end resolveIdempotencyKey()

    /**
     * Persist a row in `openregister_notification_history`.
     *
     * Best-effort: a null mapper (older test fixtures) or a database
     * failure must never block the actual dispatch. When the mapper
     * is missing or throws we log at debug level + return — the
     * notification user-experience takes precedence over audit
     * completeness.
     *
     * @param string       $ruleId    The annotation key.
     * @param string       $channel   'nc-notification' | 'email' | 'activity' | 'webhook' | 'talk'.
     * @param string       $recipient Recipient identifier.
     * @param string       $status    'dispatched' | 'rate-limited' | 'coalesced' | 'failed'.
     * @param ObjectEntity $object    The object the event happened on.
     * @param string|null  $subject   The interpolated subject (null when no subject was rendered).
     * @param string|null  $locale    Recipient locale (null for broadcast channels).
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md
     */
    private function recordHistory(
        string $ruleId,
        string $channel,
        string $recipient,
        string $status,
        ObjectEntity $object,
        ?string $subject,
        ?string $locale
    ): void {
        if ($this->historyMapper === null) {
            return;
        }

        try {
            $this->historyMapper->record(
                ruleId: $ruleId,
                channel: $channel,
                recipient: $recipient,
                status: $status,
                schemaId: ($object->getSchema() !== null && $object->getSchema() !== '') ? (string) $object->getSchema() : null,
                registerId: ($object->getRegister() !== null && $object->getRegister() !== '') ? (string) $object->getRegister() : null,
                objectUuid: ($object->getUuid() !== null && $object->getUuid() !== '') ? (string) $object->getUuid() : null,
                subject: $subject,
                errorMessage: null,
                locale: $locale
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                sprintf(
                    '[AnnotationNotificationDispatcher] history record failed (rule=%s channel=%s): %s',
                    $ruleId,
                    $channel,
                    $e->getMessage()
                )
            );
        }//end try

    }//end recordHistory()

    /**
     * Record a per-recipient short-circuit (rate-limit / coalesce)
     * across every per-recipient channel declared on the rule.
     *
     * When the per-recipient gate fails, no individual emit is called
     * — but the audit trail should still show that each declared
     * channel was suppressed. We skip channels listed in
     * `$broadcastChannels` because those have their own broadcast
     * row recorded by `dispatchBroadcastChannel()`.
     *
     * @param string             $ruleId            The rule id.
     * @param string             $recipient         Recipient identifier.
     * @param array<int, string> $channels          Channels declared on the rule.
     * @param array<int, string> $broadcastChannels Channels that are recorded once per dispatch (not per recipient).
     * @param string             $status            'rate-limited' | 'coalesced'.
     * @param ObjectEntity       $object            The triggering object.
     * @param string|null        $subject           Subject when one has been rendered.
     * @param string|null        $locale            Recipient locale.
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md
     */
    private function recordHistoryAcrossChannels(
        string $ruleId,
        string $recipient,
        array $channels,
        array $broadcastChannels,
        string $status,
        ObjectEntity $object,
        ?string $subject,
        ?string $locale
    ): void {
        foreach ($channels as $channel) {
            if (in_array($channel, $broadcastChannels, true) === true) {
                continue;
            }

            $this->recordHistory(
                ruleId: $ruleId,
                channel: (string) $channel,
                recipient: $recipient,
                status: $status,
                object: $object,
                subject: $subject,
                locale: $locale
            );
        }

    }//end recordHistoryAcrossChannels()

    /**
     * Decide whether the rule's organisation gate (if declared) lets
     * the current object through.
     *
     * The rule may declare:
     * - no `organisation` field — the gate is open and every object passes.
     * - a single string — must match the object's organisation exactly.
     * - an array of strings — any-of match: at least one entry must equal
     *   the object's organisation.
     *
     * Matching is loose-equal-string (the saved organisation column may
     * be a UUID or a slug; schema authors typically pin by the same
     * representation they store). When the object has no organisation
     * set, only rules without a gate (or rules whose gate explicitly
     * lists `null`/empty-string) match — guarantees that org-pinned
     * rules never fire for legacy un-tenanted data.
     *
     * @param array<string, mixed> $spec   The notification spec block.
     * @param ObjectEntity         $object The object the event happened on.
     *
     * @return bool True when dispatch may proceed.
     */
    private function organisationGateAllows(array $spec, ObjectEntity $object): bool
    {
        $pinned = ($spec['organisation'] ?? null);
        if ($pinned === null) {
            return true;
        }

        $objectOrg = (string) ($object->getOrganisation() ?? '');

        if (is_string($pinned) === true) {
            return $pinned === $objectOrg;
        }

        if (is_array($pinned) === true) {
            foreach ($pinned as $candidate) {
                if (is_string($candidate) === true && $candidate === $objectOrg) {
                    return true;
                }
            }

            return false;
        }

        // Malformed gate (not string / array) — fail closed so an
        // accidental misconfiguration doesn't silently leak the
        // notification cross-tenant.
        return false;

    }//end organisationGateAllows()

    /**
     * Post a chat message into a Talk room.
     *
     * Uses the standard NC Talk REST API at
     * /ocs/v2.php/apps/spreed/api/v1/chat/{token}. Goes through the
     * server-local HTTP loopback so we avoid round-tripping the public
     * URL — Talk routes are local. Skip silently when the Talk app
     * isn't enabled (status check from the IAppManager would be ideal
     * but we let the HTTP 404 path log at warning instead).
     *
     * @param array<string, mixed> $spec    Notification spec block.
     * @param string               $message Already-interpolated subject.
     *
     * @return void
     */
    private function emitTalk(array $spec, string $message): void
    {
        $talk = ($spec['talk'] ?? null);
        if (is_array($talk) === false) {
            return;
        }

        $token = (string) ($talk['token'] ?? '');
        if ($token === '') {
            return;
        }

        try {
            $client = $this->httpClient->newClient();
            // Resolve the local OC URL — Talk's chat endpoint is internal
            // to the NC instance, so we route via the configured overwrite
            // host or fall back to the loopback. The injected IConfig
            // dependency is preferred; the server container fallback
            // exists for callers that constructed the dispatcher with
            // the legacy (no-IConfig) signature.
            if ($this->config !== null) {
                $base = (string) $this->config->getSystemValue('overwrite.cli.url', 'http://localhost');
            } else {
                $base = (string) $this->serverContainer->get(\OCP\IConfig::class)->getSystemValue('overwrite.cli.url', 'http://localhost');
            }

            $base = rtrim($base, '/');
            $url  = $base.'/ocs/v2.php/apps/spreed/api/v1/chat/'.rawurlencode($token);

            $client->post(
                $url,
                [
                    'headers' => [
                        'OCS-APIRequest' => 'true',
                        'Accept'         => 'application/json',
                        'Content-Type'   => 'application/x-www-form-urlencoded',
                    ],
                    'body'    => [
                        'message'   => $message,
                        'actorType' => 'bots',
                        'actorId'   => 'openregister',
                    ],
                    'timeout' => 5,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[AnnotationNotificationDispatcher] talk to "%s" failed: %s', $token, $e->getMessage())
            );
        }//end try
    }//end emitTalk()

    /**
     * POST a JSON payload to the configured webhook URL.
     *
     * @param array<string, mixed> $spec             The notification spec block.
     * @param ObjectEntity         $object           The object the event happened on.
     * @param string               $notificationName Annotation name.
     * @param string               $subject          Interpolated subject.
     * @param array<int, string>   $recipients       Resolved recipient uids.
     * @param array<string, mixed> $context          Trigger context (action, from, to).
     *
     * @return void
     */
    private function emitWebhook(
        array $spec,
        ObjectEntity $object,
        string $notificationName,
        string $subject,
        array $recipients,
        array $context
    ): void {
        $hook = ($spec['webhook'] ?? null);
        if (is_array($hook) === false) {
            return;
        }

        // When the webhook is declared persistent, NotificationsAnnotationInstaller
        // has already provisioned a Webhook entity that the standard webhook
        // delivery pipeline (retry, HMAC, dead-letter) handles on the same
        // events. Skipping here prevents a double-fire (inline POST + pipeline
        // delivery) for the same notification.
        if (($hook['persistent'] ?? false) === true) {
            return;
        }

        $url = (string) ($hook['url'] ?? '');
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return;
        }

        $method  = strtoupper((string) ($hook['method'] ?? 'POST'));
        $headers = is_array($hook['headers'] ?? null) === true ? $hook['headers'] : [];

        $payload = [
            'notification' => $notificationName,
            'subject'      => $subject,
            'object'       => [
                'uuid'     => (string) ($object->getUuid() ?? ''),
                'register' => $object->getRegister(),
                'schema'   => $object->getSchema(),
                'data'     => $object->getObject() ?? [],
            ],
            'recipients'   => $recipients,
            'context'      => $context,
            'timestamp'    => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];

        try {
            $client = $this->httpClient->newClient();
            $client->request(
                $method,
                $url,
                [
                    'json'    => $payload,
                    'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
                    'timeout' => 5,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[AnnotationNotificationDispatcher] webhook %s failed: %s', $url, $e->getMessage())
            );
        }
    }//end emitWebhook()

    /**
     * Decide whether a notification's `trigger` block matches the active event.
     *
     * For `calculatedChange` triggers, both `condition` (new value) and
     * `previously` (old value) must be satisfied for the rule to fire —
     * this is the boundary-crossing / debounce check.
     *
     * @param array<string, mixed> $triggerSpec The declared `trigger` sub-document.
     * @param string               $trigger     The active event type.
     * @param array<string, mixed> $context     Per-event context (e.g. `action`).
     *
     * @return bool True when the rule should fire for this event.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function matches(array $triggerSpec, string $trigger, array $context): bool
    {
        if ((string) ($triggerSpec['type'] ?? '') !== $trigger) {
            return false;
        }

        // Optional action filter for `transition` triggers.
        if ($trigger === 'transition' && isset($triggerSpec['action']) === true) {
            $expected = $triggerSpec['action'];
            $actual   = ($context['action'] ?? null);
            if (is_array($expected) === true) {
                if (in_array($actual, $expected, true) === false) {
                    return false;
                }
            } else if ((string) $expected !== (string) $actual) {
                return false;
            }
        }

        // `calculatedChange` boundary-crossing check.
        // `field` names the calculated property to monitor.
        // `condition` operators the NEW value must satisfy.
        // `previously` operators the OLD value must satisfy.
        // Both must hold simultaneously. When either condition or
        // previously is absent the gate is open (partial spec is treated
        // as "just the declared side must match"). When _newData/_oldData
        // are absent in context (e.g. missing old object) the check
        // cannot be evaluated and the rule is skipped (fail-closed).
        if ($trigger === 'calculatedChange') {
            $field = (string) ($triggerSpec['field'] ?? '');
            if ($field === '') {
                return false;
            }

            $newData = ($context['_newData'] ?? null);
            $oldData = ($context['_oldData'] ?? null);
            if (is_array($newData) === false || is_array($oldData) === false) {
                return false;
            }

            $newValue = ($newData[$field] ?? null);
            $oldValue = ($oldData[$field] ?? null);

            $condition  = ($triggerSpec['condition'] ?? null);
            $previously = ($triggerSpec['previously'] ?? null);

            if (is_array($condition) === true
                && $this->numericConditionMatches(value: $newValue, operators: $condition) === false
            ) {
                return false;
            }

            if (is_array($previously) === true
                && $this->numericConditionMatches(value: $oldValue, operators: $previously) === false
            ) {
                return false;
            }
        }//end if

        return true;
    }//end matches()

    /**
     * Evaluate a set of plain comparison operators against a numeric value.
     *
     * Operators mirror the JSON-schema style used by the notification spec:
     * `lt`, `lte`, `gt`, `gte`, `eq`, `ne`. All comparisons are numeric
     * (int/float); a non-numeric value returns false for every ordering
     * operator (`lt`, `lte`, `gt`, `gte`) and casts to string for `eq`/`ne`.
     *
     * Multiple operators in the map are ANDed together (all must hold).
     *
     * @param mixed                $value     The field value to test.
     * @param array<string, mixed> $operators Map of operator → threshold (e.g. `['lt' => 0.85]`).
     *
     * @return bool True when the value satisfies every declared operator.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function numericConditionMatches(mixed $value, array $operators): bool
    {
        foreach ($operators as $op => $threshold) {
            $numeric = is_numeric($value) === true && is_numeric($threshold) === true;
            $result  = match ((string) $op) {
                'lt'  => $numeric && (float) $value < (float) $threshold,
                'lte' => $numeric && (float) $value <= (float) $threshold,
                'gt'  => $numeric && (float) $value > (float) $threshold,
                'gte' => $numeric && (float) $value >= (float) $threshold,
                'eq'  => (string) $value === (string) $threshold,
                'ne'  => (string) $value !== (string) $threshold,
                default => false,
            };

            if ($result === false) {
                return false;
            }
        }

        return true;
    }//end numericConditionMatches()

    /**
     * Resolve a `recipients` block to a flat list of UIDs.
     *
     * @param array<int, array<string, mixed>> $recipientsSpec The declared recipients block.
     * @param array<string, mixed>             $data           Object payload (used by `field` resolvers).
     * @param ObjectEntity|null                $object         Optional owning object (needed for ACL/expression kinds).
     * @param array<string, mixed>             $context        Per-event context.
     *
     * @return array<int, string>
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function resolveRecipients(array $recipientsSpec, array $data, ?ObjectEntity $object=null, array $context=[]): array
    {
        $uids = [];
        foreach ($recipientsSpec as $r) {
            if (is_array($r) === false) {
                continue;
            }

            $kind = (string) ($r['kind'] ?? '');
            if ($kind === 'users') {
                foreach ((array) ($r['users'] ?? []) as $u) {
                    if (is_string($u) === true && $u !== '' && $this->userExists(uid: $u) === true) {
                        $uids[] = $u;
                    }
                }

                continue;
            }

            if ($kind === 'field') {
                // The field's value comes from the object's stored data,
                // which is writeable by anyone with `update` permission
                // on the object. An attacker who controls the field
                // could otherwise direct notifications at any uid string,
                // including admins, with an attacker-shaped subject.
                // Verify the value names a real Nextcloud user before
                // adding it to the recipient list.
                $field = (string) ($r['field'] ?? '');
                $value = ($data[$field] ?? null);
                if (is_string($value) === true && $value !== '' && $this->userExists(uid: $value) === true) {
                    $uids[] = $value;
                }

                continue;
            }

            if ($kind === 'relation') {
                // Resolve a typed relation (declared via x-openregister-relations).
                // Reads $data[<relationFieldName>] which by convention holds
                // either a string UID, an array of string UIDs, or an
                // array of objects each carrying a userId field. Same
                // attacker-controlled-input reasoning as the `field`
                // kind above — every extracted uid is checked against
                // IUserManager::userExists().
                $relName = (string) ($r['relation'] ?? '');
                if ($relName === '') {
                    continue;
                }

                $value = ($data[$relName] ?? null);
                foreach ($this->extractUidsFromRelation(value: $value) as $uid) {
                    if ($this->userExists(uid: $uid) === true) {
                        $uids[] = $uid;
                    }
                }

                continue;
            }//end if

            if ($kind === 'object-acl') {
                if ($object !== null) {
                    $perm = (string) ($r['permission'] ?? 'read');
                    foreach ($this->resolveObjectAclRecipients(object: $object, permission: $perm) as $uid) {
                        $uids[] = $uid;
                    }
                }

                continue;
            }

            if ($kind === 'expression') {
                if ($object !== null) {
                    $resolverTag = (string) ($r['resolver'] ?? '');
                    $resolved    = $this->resolveExpressionRecipients(
                        resolverTag: $resolverTag,
                        object: $object,
                        context: $context
                    );
                    foreach ($resolved as $uid) {
                        $uids[] = $uid;
                    }
                }

                continue;
            }

            if ($kind === 'groups') {
                foreach ((array) ($r['groups'] ?? []) as $gid) {
                    if (is_string($gid) === false || $gid === '') {
                        continue;
                    }

                    try {
                        $group = $this->groupManager->get($gid);
                        if ($group === null) {
                            continue;
                        }

                        foreach ($group->getUsers() as $user) {
                            $uids[] = $user->getUID();
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning(
                            sprintf('[AnnotationNotificationDispatcher] group "%s" lookup failed: %s', $gid, $e->getMessage())
                        );
                    }
                }
            }//end if
        }//end foreach

        return array_values(array_unique($uids));
    }//end resolveRecipients()

    /**
     * Resolve recipients from the object's per-object ACL.
     *
     * Reads `$object->getAuthorization()` (Schema entity carries the
     * permission map per object). Returns every uid (and group-member
     * uids) holding the requested permission level.
     *
     * v1 implementation: best-effort. Reads the object's `groups` and
     * `owner` fields directly. Per-object ACL granularity (read vs
     * manage) is treated as: `read` matches any user/group in the ACL;
     * `manage` matches only the object owner. A future iteration can
     * walk the full RBAC `OrObjectAclMapper` once that surface is
     * stable.
     *
     * @param ObjectEntity $object     The object whose ACL should be read.
     * @param string       $permission The required permission (`read` or `manage`).
     *
     * @return array<int, string>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function resolveObjectAclRecipients(ObjectEntity $object, string $permission): array
    {
        $uids  = [];
        $owner = $object->getOwner();
        if (is_string($owner) === true && $owner !== '') {
            $uids[] = $owner;
        }

        if ($permission === 'manage') {
            return $uids;
        }

        // Read permission: also include groups via getGroups(). The
        // Entity base uses __call magic for accessors, so method_exists()
        // is unreliable — fall through and let the magic call surface
        // the value (or throw, which is caught below).
        try {
            $groupsRaw = $object->getGroups();
            if (is_array($groupsRaw) === true) {
                foreach ($groupsRaw as $gid) {
                    if (is_string($gid) === false || $gid === '') {
                        continue;
                    }

                    $group = $this->groupManager->get($gid);
                    if ($group === null) {
                        continue;
                    }

                    foreach ($group->getUsers() as $user) {
                        $uids[] = $user->getUID();
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[AnnotationNotificationDispatcher] object-acl read resolution failed: %s', $e->getMessage())
            );
        }//end try

        return $uids;
    }//end resolveObjectAclRecipients()

    /**
     * Resolve recipients via a DI-tagged RecipientResolverInterface.
     *
     * Looks up the resolver via the injected IServerContainer so apps
     * can register their resolver class by FQCN and have NC autowire
     * its dependencies. Skips silently when the resolver doesn't exist
     * or doesn't implement the interface.
     *
     * The previous implementation reached for the `\OC::$server` static
     * accessor; this PR's ADR (`docs/development-notes/AUDIT_2026-05-01.md`)
     * bans that pattern in `lib/`. The injected container is functionally
     * equivalent without coupling to the static accessor.
     *
     * @param string               $resolverTag DI tag (or FQCN) of the resolver service.
     * @param ObjectEntity         $object      The object whose recipients are being resolved.
     * @param array<string, mixed> $context     Per-event context passed through to the resolver.
     *
     * @return array<int, string>
     */
    private function resolveExpressionRecipients(string $resolverTag, ObjectEntity $object, array $context): array
    {
        if ($resolverTag === '') {
            return [];
        }

        try {
            $resolver = $this->serverContainer->get($resolverTag);
            if (($resolver instanceof RecipientResolverInterface) === false) {
                $this->logger->warning(
                    sprintf('[AnnotationNotificationDispatcher] expression resolver "%s" does not implement RecipientResolverInterface', $resolverTag)
                );
                return [];
            }

            return array_values($resolver->resolve($object, $context));
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[AnnotationNotificationDispatcher] expression resolver "%s" failed: %s', $resolverTag, $e->getMessage())
            );
            return [];
        }
    }//end resolveExpressionRecipients()

    /**
     * Verify that a uid corresponds to an actual Nextcloud user.
     *
     * Notification recipient lists pull strings from object data
     * (`field` / `relation` kinds) and from schema annotations
     * (`users` kind). Without this check, an attacker who can write
     * objects in a schema using `field` recipients could direct a
     * notification (with an attacker-shaped subject) at any uid string
     * — including admins. Backed by a per-request cache to keep the
     * cost flat across N recipients in a single dispatch.
     *
     * @param string $uid Candidate Nextcloud user identifier.
     *
     * @return bool True when the uid corresponds to a real Nextcloud user.
     */
    private function userExists(string $uid): bool
    {
        if ($uid === '') {
            return false;
        }

        if (isset($this->userExistsCache[$uid]) === true) {
            return $this->userExistsCache[$uid];
        }

        // R06: only cache definitive verdicts. A `\Throwable` from
        // IUserManager (transient DB/LDAP failure, momentary container
        // hiccup) is NOT a definitive "user doesn't exist" — caching it
        // would silently drop every notification for this uid for the
        // rest of the request, even after the underlying problem
        // clears. Log + return false WITHOUT writing to the cache so
        // the next call within the same request retries the lookup.
        try {
            $exists = $this->userManager->userExists($uid);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[AnnotationNotificationDispatcher] userExists check failed for "%s" (not cached, will retry): %s', $uid, $e->getMessage())
            );
            return false;
        }

        $this->userExistsCache[$uid] = (bool) $exists;
        return $this->userExistsCache[$uid];
    }//end userExists()

    /**
     * Per-request cache for userExists() lookups.
     *
     * @var array<string, bool>
     */
    private array $userExistsCache = [];

    /**
     * Extract candidate UIDs from a relation value. The relation value
     * can be:
     *   - a string (treat as UID directly)
     *   - an array of strings (each treated as a UID)
     *   - an array of objects with a `userId` or `uid` field
     *   - any nested combination of the above
     *
     * @param mixed $value The raw relation value.
     *
     * @return array<int, string>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function extractUidsFromRelation(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value) === true && $value !== '') {
            return [$value];
        }

        if (is_array($value) === false) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) === true && $entry !== '') {
                $out[] = $entry;
                continue;
            }

            if (is_array($entry) === true) {
                $candidate = ($entry['userId'] ?? $entry['uid'] ?? $entry['user_id'] ?? null);
                if (is_string($candidate) === true && $candidate !== '') {
                    $out[] = $candidate;
                }
            }
        }

        return $out;
    }//end extractUidsFromRelation()

    /**
     * Resolve a localized subject template against a recipient locale.
     *
     * Subject templates can be declared in three shapes:
     *
     *   1. Legacy single-language string:
     *        subject: "Object {{title}} updated"
     *   2. Per-locale map:
     *        subject:
     *          nl: "Object {{title}} bijgewerkt"
     *          en: "Object {{title}} updated"
     *   3. Per-locale map with explicit default:
     *        subject:
     *          defaultLocale: nl
     *          nl: "..."
     *          en: "..."
     *
     * The resolver picks the recipient's locale when present in the
     * map, then falls back to:
     *   a. the explicit `defaultLocale` key if set,
     *   b. `nl` (Dutch — the primary language for Conduction's NL
     *      government audience),
     *   c. `en`,
     *   d. the first non-default key in declaration order,
     *   e. the rule's annotation name (last-resort identifier).
     *
     * Closes the spec's NL/EN i18n requirement; new locales beyond the
     * NL/EN minimum just need to be added under their ISO 639-1 code in
     * the schema annotation — no code change required.
     *
     * @param mixed                $template     Raw subject value (string or array).
     * @param string|null          $locale       Recipient locale, or null for broadcast channels.
     * @param array<string, mixed> $data         Object data for `{{prop}}` interpolation.
     * @param array<string, mixed> $context      Trigger-specific context.
     * @param string               $fallbackName Annotation name (last-resort fallback).
     *
     * @return string The interpolated subject string.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function resolveLocalizedSubject(
        mixed $template,
        ?string $locale,
        array $data,
        array $context,
        string $fallbackName
    ): string {
        if (is_string($template) === true && $template !== '') {
            // Legacy single-language path — no per-locale map declared.
            return $this->interpolate(template: $template, data: $data, context: $context);
        }

        if (is_array($template) === true) {
            $declared      = isset($template['defaultLocale']) === true && is_string($template['defaultLocale']) === true;
            $defaultLocale = $declared === true ? $template['defaultLocale'] : 'nl';

            // Recipient locale wins when declared.
            if ($locale !== null && isset($template[$locale]) === true && is_string($template[$locale]) === true) {
                return $this->interpolate(template: $template[$locale], data: $data, context: $context);
            }

            // Explicit default locale next.
            if (isset($template[$defaultLocale]) === true && is_string($template[$defaultLocale]) === true) {
                return $this->interpolate(template: $template[$defaultLocale], data: $data, context: $context);
            }

            // NL/EN baseline.
            foreach (['nl', 'en'] as $candidate) {
                if (isset($template[$candidate]) === true && is_string($template[$candidate]) === true) {
                    return $this->interpolate(template: $template[$candidate], data: $data, context: $context);
                }
            }

            // First locale in declaration order (skip the meta key).
            foreach ($template as $key => $value) {
                if ($key === 'defaultLocale') {
                    continue;
                }

                if (is_string($value) === true) {
                    return $this->interpolate(template: $value, data: $data, context: $context);
                }
            }
        }//end if

        return $fallbackName;

    }//end resolveLocalizedSubject()

    /**
     * Resolve a Nextcloud user's preferred locale.
     *
     * Reads `core.lang` from the user's NC config — the same value
     * Nextcloud's own UI consults for translations. Returns the bare
     * 2-letter language code (`nl`, `en`, …) so the result aligns with
     * the per-locale subject map keys. Returns null when the IConfig
     * dependency is absent (older test fixtures) or when the user has
     * no preference set; callers fall through to the default locale
     * fallback chain in `resolveLocalizedSubject()`.
     *
     * @param string $uid Nextcloud user identifier.
     *
     * @return string|null The 2-letter language code, or null when unknown.
     */
    private function resolveUserLocale(string $uid): ?string
    {
        if ($this->config === null) {
            return null;
        }

        try {
            $raw = $this->config->getUserValue($uid, 'core', 'lang', '');
        } catch (\Throwable $e) {
            return null;
        }

        if (is_string($raw) === false || $raw === '') {
            return null;
        }

        // NC stores values like `nl`, `en_GB`, `de_DE` — strip the
        // region suffix so the lookup matches the simple ISO 639-1
        // keys we expect in the per-locale subject map.
        $sep = strpos($raw, '_');
        if ($sep !== false) {
            $raw = substr($raw, 0, $sep);
        }

        return strtolower($raw);

    }//end resolveUserLocale()

    /**
     * Replace {{prop}} tokens with values from $data, then $context.
     *
     * Substituted values are HTML-escaped at the source as defence in
     * depth. The rendered subject ends up in:
     *   - INotificationManager (HTML render path in the NC notification UI),
     *   - the Activity stream (HTML render path),
     *   - email subject/body (plain-text setPlainBody, but still rendered
     *     by mail clients that may interpret HTML in the subject line).
     * Nextcloud's own rendering layers escape on output, so this is a
     * second layer rather than the only one — but it keeps the
     * `<script>` / `"` / `&` characters that come from object data
     * from being placed into a notification context without escaping.
     *
     * The literal template fragments authored by the schema author
     * pass through unchanged (they aren't sourced from object data).
     *
     * @param string               $template The raw subject template.
     * @param array<string, mixed> $data     Object data for `{{prop}}` lookup.
     * @param array<string, mixed> $context  Per-event context for `{{prop}}` lookup.
     *
     * @return string The interpolated string.
     */
    private function interpolate(string $template, array $data, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            static function (array $matches) use ($data, $context): string {
                $key = $matches[1];
                if (array_key_exists($key, $data) === true) {
                    if (is_scalar($data[$key]) === false) {
                        return '';
                    }

                    return htmlspecialchars((string) $data[$key], ENT_QUOTES, 'UTF-8');
                }

                if (array_key_exists($key, $context) === true) {
                    if (is_scalar($context[$key]) === false) {
                        return '';
                    }

                    return htmlspecialchars((string) $context[$key], ENT_QUOTES, 'UTF-8');
                }

                return '';
            },
            $template
        ) ?? $template;
    }//end interpolate()

    /**
     * Persist + dispatch a single in-app Nextcloud notification row.
     *
     * @param string               $uid        Recipient user UID.
     * @param string               $objectId   The owning object's UUID (or rule name fallback).
     * @param string               $name       Annotation name (notification type identifier).
     * @param string               $subject    Pre-interpolated subject text.
     * @param array<string, mixed> $parameters Extra notification parameters.
     *
     * @return void
     */
    private function emitNotification(string $uid, string $objectId, string $name, string $subject, array $parameters): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('openregister')
                ->setUser($uid)
                ->setDateTime(new DateTime())
                ->setObject('object', $objectId !== '' ? $objectId : $name)
                ->setSubject($name, array_merge($parameters, ['_text' => $subject]));
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Notification "%s" to "%s" failed: %s', $name, $uid, $e->getMessage())
            );
        }
    }//end emitNotification()

    /**
     * Send a transactional email to a Nextcloud user.
     *
     * Resolves the user's email via IUserManager and short-circuits if
     * SMTP isn't configured (mailer->validateMailFrom would fail) or
     * the user has no email on file.
     *
     * @param string $uid     Recipient user UID.
     * @param string $subject Email subject line.
     * @param string $body    Email body text.
     *
     * @return void
     */
    private function emitEmail(string $uid, string $subject, string $body): void
    {
        try {
            $user = $this->userManager->get($uid);
            if ($user === null) {
                return;
            }

            $to = $user->getEMailAddress();
            if ($to === null || $to === '') {
                return;
            }

            $msg = $this->mailer->createMessage();
            $msg->setTo([$to => $user->getDisplayName()]);
            $msg->setSubject($subject);
            $msg->setPlainBody($body);
            $this->mailer->send($msg);
        } catch (\Throwable $e) {
            // Don't escalate — email is best-effort. SMTP not configured
            // is normal in dev containers.
            $this->logger->debug(
                sprintf('[AnnotationNotificationDispatcher] email to "%s" failed (%s)', $uid, $e->getMessage())
            );
        }//end try
    }//end emitEmail()

    /**
     * Publish an entry to the Nextcloud Activity stream.
     *
     * @param string $uid      Affected user UID.
     * @param string $objectId Owning object's UUID (or rule name fallback).
     * @param string $name     Annotation name (activity subject identifier).
     * @param string $subject  Pre-interpolated activity text.
     *
     * @return void
     */
    private function emitActivity(string $uid, string $objectId, string $name, string $subject): void
    {
        try {
            $event = $this->activityManager->generateEvent();
            $event
                ->setApp('openregister')
                ->setType('openregister_objects')
                ->setAffectedUser($uid)
                ->setSubject($name, ['_text' => $subject])
                ->setObject('object', 0, $objectId !== '' ? $objectId : $name)
                ->setTimestamp(time());
            $this->activityManager->publish($event);
        } catch (\Throwable $e) {
            $this->logger->debug(
                sprintf('[AnnotationNotificationDispatcher] activity to "%s" failed (%s)', $uid, $e->getMessage())
            );
        }
    }//end emitActivity()

    /**
     * Resolve the schema referenced by an object, returning null on failure.
     *
     * @param ObjectEntity $object The object whose schema should be looked up.
     *
     * @return Schema|null The resolved schema, or null when missing/unresolvable.
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $ref = $object->getSchema();
        if ($ref === null || $ref === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($ref, _multitenancy: false);
        } catch (\Throwable) {
            return null;
        }
    }//end loadSchema()

    /**
     * Pull the `x-openregister-notifications` annotation off a schema.
     *
     * @param Schema $schema The schema whose annotation should be read.
     *
     * @return array<string, mixed>|null
     */
    private function getAnnotation(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-notifications'] ?? null);
        return is_array($value) === true ? $value : null;
    }//end getAnnotation()

    /**
     * Filter resolved recipients down to users who have subscribed to
     * the object's (register, schema). Non-uid recipients (email
     * literals, webhook urls, etc) pass through unchanged because the
     * subscription store is user-scoped only.
     *
     * Null-safe: when the SubscriptionMapper isn't wired (legacy
     * fixtures) or the object lacks register/schema metadata, the
     * filter is a no-op and every recipient is kept.
     *
     * @param array<int, array> $recipients The resolved recipient list.
     * @param ObjectEntity      $object     The object the event fired on.
     *
     * @return array<int, array>
     */
    private function filterBySubscription(array $recipients, ObjectEntity $object): array
    {
        if ($this->subscriptionMapper === null) {
            return $recipients;
        }

        $registerId = $object->getRegister();
        $schemaId   = $object->getSchema();
        if (is_numeric($registerId) === false || is_numeric($schemaId) === false) {
            return $recipients;
        }

        try {
            $subscribed = $this->subscriptionMapper->findSubscribedUids(
                registerId: (int) $registerId,
                schemaId: (int) $schemaId
            );
        } catch (\Throwable $e) {
            // A query failure MUST NOT block dispatch — log and pass
            // every recipient through.
            $this->logger->warning(
                '[AnnotationNotificationDispatcher] subscription lookup failed: '.$e->getMessage(),
                ['file' => __FILE__, 'line' => __LINE__]
            );
            return $recipients;
        }

        $subscribedSet = array_flip($subscribed);

        return array_values(
                array_filter(
            $recipients,
            static function (array $recipient) use ($subscribedSet): bool {
                $kind = ($recipient['kind'] ?? null);
                $uid  = ($recipient['uid'] ?? null);
                if ($kind !== 'user' || is_string($uid) === false || $uid === '') {
                    // Non-user recipients (email literal, webhook url,
                    // talk room, group expansion that already produced
                    // a user) bypass the subscription filter so legacy
                    // wire shapes still receive notifications.
                    return true;
                }

                return isset($subscribedSet[$uid]);
            }
        )
                );

    }//end filterBySubscription()
}//end class
