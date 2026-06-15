<?php

/**
 * OpenRegister AnnotationNotificationDispatcher
 *
 * Reads `x-openregister-notifications` from the schema and fires
 * Nextcloud INotificationManager notifications when a triggering event
 * matches. v1 supports trigger types created/updated/transition,
 * recipient kinds users/field, channel `nc-notification`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCA\OpenRegister\BackgroundJob\WebPushDispatchJob;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\Activity\IManager as IActivityManager;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Reads notification annotations and dispatches matching ones.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Dispatcher wires SchemaMapper, INotificationManager,
 * IGroupManager, IUserManager, IMailer, IActivityManager, IClientService, IServerContainer, RateLimiter,
 * IConfig, NotificationHistoryMapper, NotificationCoalescer, NotificationSubscriptionMapper,
 * NotificationDispatchLogMapper, NotificationPreferenceService — every dependency serves a distinct
 * notification channel or cross-cutting concern (audit, dedup, rate-limit, preference).
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Class implements the full notification dispatch pipeline
 * (nc-notification, email, activity, webhook, talk channels) plus recipient resolution, rate-limiting,
 * coalescing, idempotency, history audit, and organisation gating; each concern requires its own helper
 * method and cannot be moved to a separate class without breaking the single-dispatch-per-call contract.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity is spread across 20+ private helpers each
 * testing a distinct dispatch condition; PHPMD accumulates scores from every helper into the class total.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Each notification channel (nc-notification, email, activity,
 * webhook, talk), each gate (rate-limit, coalesce, preference, subscription, organisation), each helper
 * (recipient resolve, locale, history record, idempotency) requires its own method per the
 * single-responsibility principle.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
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
     * @param \OCA\OpenRegister\Db\NotificationSubscriptionMapper|null $subscriptionMapper  Optional subscription mapper (DEPRECATED).
     * @param NotificationDispatchLogMapper|null                       $dispatchLogMapper   Optional dispatch-log mapper for idempotency-key dedup.
     * @param NotificationPreferenceService|null                       $preferenceService   Override-only preference resolver (delivery gate).
     * @param IJobList|null                                            $jobList             Job list used to enqueue the web-push dispatch job.
     * @param IURLGenerator|null                                       $urlGenerator        URL generator for action-target deeplinks.
     * @param IAppManager|null                                         $appManager          App manager used to resolve named app routes.
     * @param RegisterMapper|null                                      $registerMapper      Register mapper used for the default originApp.
     * @param \OCA\OpenRegister\Service\ObjectService|null             $objectService       Object resolver for relation-target deeplinks (RBAC).
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
        private readonly ?NotificationDispatchLogMapper $dispatchLogMapper=null,
        private readonly ?NotificationPreferenceService $preferenceService=null,
        private readonly ?IJobList $jobList=null,
        private readonly ?IURLGenerator $urlGenerator=null,
        private readonly ?IAppManager $appManager=null,
        private readonly ?RegisterMapper $registerMapper=null,
        private readonly ?\OCA\OpenRegister\Service\ObjectService $objectService=null
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  dispatch() iterates notifications, filters by
     * organisation gate, idempotency, recipient resolution, rate-limit, coalesce, and preference per
     * recipient per channel — each branch is a required dispatch condition; extracting sub-methods
     * would split the sequential guard chain.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Per-rule: trigger match + org gate + idempotency claim
     * + subscription filter + rate-limit + coalesce + preference check + channel fan-out produce many
     * independent paths; all are required for the notification contract.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) dispatch() is the single entry point that chains
     * all notification gates in order; splitting the chain would break the sequential guard semantics
     * (e.g. rate-limit must run after idempotency claim).
     * @SuppressWarnings(PHPMD.LongVariable)          $resolvedIdempotencyKey and $idempotencyKeyTemplate are
     * descriptive names for distinct lifecycle stages of the same template value; abbreviating would
     * obscure the claim-before-send ordering that prevents duplicate dispatches.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
     */
    public function dispatch(ObjectEntity $object, string $trigger, array $context=[]): void
    {
        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return;
        }

        $this->dispatchWithSchema(object: $object, trigger: $trigger, context: $context, schema: $schema);

    }//end dispatch()

    /**
     * Fire notifications declared on a pre-resolved Schema.
     *
     * Called by dispatch() after schema resolution, and directly by the
     * SystemEntityNotificationBridge for system entities whose schema is not in
     * the SchemaMapper but is instead provided by SystemSchemaRules::buildSchema().
     *
     * This is the single entry point for the notification pipeline: every
     * dispatch path (stored objects and system entities) goes through here.
     *
     * @param ObjectEntity         $object  The object (or virtual adapter) the event happened on.
     * @param string               $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
     * @param array<string, mixed> $context Trigger-specific extras.
     * @param Schema               $schema  Pre-resolved schema carrying the rules.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.LongVariable)
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
     */
    public function dispatchWithSchema(ObjectEntity $object, string $trigger, array $context, Schema $schema): void
    {
        $notifications = $this->getAnnotation(schema: $schema);
        if ($notifications === null) {
            return;
        }

        $data = $object->getObject() ?? [];

        // Canonical INotification subject derived from the trigger. The
        // Notifier renders these (object_created / object_updated /
        // object_transitioned) with localised text + an object-detail
        // action link — decoupling rendering from how the schema author
        // happened to name the rule, so EVERY schema-declared in-app
        // notification renders rather than throwing "Unknown subject".
        $subjectKey = $this->canonicalSubject(trigger: $trigger);
        $schemaSlug = (string) ($schema->getSlug() ?? $schema->getId());

        foreach ($notifications as $name => $spec) {
            if (is_array($spec) === false) {
                continue;
            }

            $matches = $this->matches(
                triggerSpec: $spec['trigger'] ?? [],
                trigger: $trigger,
                context: array_merge($context, ['_data' => $data])
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

            $rateLimit = null;
            if (is_array($spec['rateLimit'] ?? null) === true) {
                $rateLimit = $spec['rateLimit'];
            }

            $coalesce = null;
            if (is_array($spec['coalesce'] ?? null) === true) {
                $coalesce = $spec['coalesce'];
            }

            $ruleId = (string) $name;

            // Resolve the rule's originApp (declared value wins, else the
            // app owning the schema's register) and the per-action deeplinks
            // once per rule — these drive the icon + action buttons in the
            // emitted notification and the web-push payload.
            $originApp        = $this->resolveOriginApp(spec: $spec, object: $object);
            $resolvedActions  = $this->resolveActions(
                spec: $spec,
                object: $object,
                data: $data,
                originApp: $originApp
            );

            // Route the web-push channel out of band: enqueue a background
            // job per recipient so the originating save request is never
            // blocked on push I/O. The job re-resolves recipients to their
            // stored subscriptions and sends the encrypted VAPID payload.
            if (in_array('web-push', $channels, true) === true) {
                $this->enqueueWebPush(
                    recipients: $recipients,
                    ruleId: $ruleId,
                    originApp: $originApp,
                    subject: $broadcastSubject,
                    actions: $resolvedActions,
                    object: $object
                );
            }

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

                // Per-recipient preference gate (override-only). The schema
                // declares the default on/off + channels; a stored user
                // override flips it. Absence of an override falls through to
                // the schema default (zero-migration). Webhook/talk are
                // broadcast (fired once above) and intentionally unaffected.
                $effectiveChannels = $channels;
                if ($this->preferenceService !== null) {
                    $pref = $this->preferenceService->resolveEffective(
                        schemaDefault: $spec,
                        userId: $uid,
                        schemaSlug: $schemaSlug,
                        notificationKey: (string) $name
                    );
                    if ($pref['enabled'] === false) {
                        $this->recordHistoryAcrossChannels(
                            ruleId: $ruleId,
                            recipient: $uid,
                            channels: $channels,
                            broadcastChannels: ['webhook', 'talk'],
                            status: 'preference-off',
                            object: $object,
                            subject: $recipientSubject,
                            locale: $recipientLocale
                        );
                        continue;
                    }

                    if ($pref['channels'] !== null) {
                        $effectiveChannels = array_values(array_intersect($channels, $pref['channels']));
                    }
                }//end if

                if (in_array('nc-notification', $effectiveChannels, true) === true) {
                    $this->emitNotification(
                        uid: $uid,
                        object: $object,
                        subjectKey: $subjectKey,
                        name: (string) $name,
                        subject: $recipientSubject,
                        context: $context,
                        originApp: $originApp,
                        actions: $resolvedActions,
                        webPushActive: in_array('web-push', $channels, true)
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

                if (in_array('email', $effectiveChannels, true) === true) {
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

                if (in_array('activity', $effectiveChannels, true) === true) {
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

    }//end dispatchWithSchema()

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
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) dispatchBroadcastChannel() centralises the
     * rate-limit+coalesce+history pattern for webhook and talk; all 11 parameters are forwarded from
     * the dispatch() call-site context and cannot be bundled into a value-object without creating a
     * throw-away struct that obscures the data flow.
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

        $historySchemaId = null;
        if ($object->getSchema() !== null && $object->getSchema() !== '') {
            $historySchemaId = (string) $object->getSchema();
        }

        $historyRegisterId = null;
        if ($object->getRegister() !== null && $object->getRegister() !== '') {
            $historyRegisterId = (string) $object->getRegister();
        }

        $historyObjectUuid = null;
        if ($object->getUuid() !== null && $object->getUuid() !== '') {
            $historyObjectUuid = (string) $object->getUuid();
        }

        try {
            $this->historyMapper->record(
                ruleId: $ruleId,
                channel: $channel,
                recipient: $recipient,
                status: $status,
                schemaId: $historySchemaId,
                registerId: $historyRegisterId,
                objectUuid: $historyObjectUuid,
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
            $base = (string) $this->serverContainer->get(\OCP\IConfig::class)->getSystemValue('overwrite.cli.url', 'http://localhost');
            if ($this->config !== null) {
                $base = (string) $this->config->getSystemValue('overwrite.cli.url', 'http://localhost');
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
        $headers = [];
        if (is_array($hook['headers'] ?? null) === true) {
            $headers = $hook['headers'];
        }

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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) matches() handles four trigger types
     * (created/updated/transition/calculatedChange) each with sub-conditions (action filter,
     * field-change condition, boundary-crossing operators); each branch is a distinct spec
     * requirement and cannot be merged.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Each trigger type has optional sub-conditions that
     * can independently pass or fail; the NPath count reflects spec-mandated combinations,
     * not accidental branching.
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

        // Optional field filter for `created` triggers — the rule fires only
        // when the created object's fields satisfy every declared equality
        // (e.g. `channel == "telefoon"`). Evaluated against the created
        // object's data forwarded as `_data`. A condition-less `created` rule
        // matches on type alone (back-compat). Fail-closed when data is absent.
        if ($trigger === 'created' && isset($triggerSpec['filter']) === true) {
            $filter = $triggerSpec['filter'];
            if (is_array($filter) === false) {
                return false;
            }

            $data = ($context['_data'] ?? null);
            if (is_array($data) === false) {
                return false;
            }

            return $this->createdFilterMatches(filter: $filter, data: $data);
        }

        // Optional non-numeric field-change `condition` for `updated` triggers.
        // Engages ONLY when the trigger declares a `condition`; condition-less
        // `updated` rules match on type alone (back-compat). Reads the old/new
        // object data the listener forwards; fail-closed when either is absent
        // (mirrors the `calculatedChange` guard below).
        if ($trigger === 'updated' && isset($triggerSpec['condition']) === true) {
            $condition = $triggerSpec['condition'];
            if (is_array($condition) === false) {
                return false;
            }

            $newData = ($context['_newData'] ?? null);
            $oldData = ($context['_oldData'] ?? null);
            if (is_array($newData) === false || is_array($oldData) === false) {
                return false;
            }

            return $this->fieldChangeConditionMatches(condition: $condition, oldData: $oldData, newData: $newData);
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) numericConditionMatches() evaluates six comparison
     * operators (lt, lte, gt, gte, eq, ne) in a match expression; each arm is a distinct spec-defined
     * operator and cannot be reduced further.
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
                // BUG-SVC-8: when both sides are numeric, compare as floats so
                // numerically-equal-but-differently-formatted values match
                // (e.g. `1.0` eq `1`). Fall back to string compare otherwise.
                'eq'  => $numeric === true ? ((float) $value === (float) $threshold) : ((string) $value === (string) $threshold),
                'ne'  => $numeric === true ? ((float) $value !== (float) $threshold) : ((string) $value !== (string) $threshold),
                default => false,
            };

            if ($result === false) {
                return false;
            }
        }

        return true;
    }//end numericConditionMatches()

    /**
     * Evaluate a `created`-trigger field filter against the created object's data.
     *
     * Supports the contract's single-field shape `{ field, operator, value|values }`
     * with operators `equals` (default), `in`, and `notIn`. Scalar comparison is
     * string-cast (mirrors `fieldChangeConditionMatches`). A filter naming an
     * unknown field fails closed.
     *
     * @param array<string, mixed> $filter The `trigger.filter` sub-document.
     * @param array<string, mixed> $data   The created object's data.
     *
     * @return bool True when the object satisfies the filter.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    private function createdFilterMatches(array $filter, array $data): bool
    {
        $field = (string) ($filter['field'] ?? '');
        if ($field === '') {
            return false;
        }

        $operator = (string) ($filter['operator'] ?? 'equals');
        $actual   = ($data[$field] ?? null);
        $actualStr = '';
        if (is_scalar($actual) === true) {
            $actualStr = (string) $actual;
        }

        if ($operator === 'in' || $operator === 'notIn') {
            $values = ($filter['values'] ?? []);
            if (is_array($values) === false) {
                return false;
            }

            $haystack = array_map(static fn ($v): string => is_scalar($v) === true ? (string) $v : '', $values);
            $present  = in_array($actualStr, $haystack, true);
            return ($operator === 'in') ? $present : ($present === false);
        }

        // Default: equals.
        return $actualStr === (string) ($filter['value'] ?? '');
    }//end createdFilterMatches()

    /**
     * Evaluate a non-numeric field-change condition for an `updated` trigger.
     *
     * Compares one field's value between the old and new object data:
     *   - `changed`            — matches when old != new.
     *   - `equals` (+ `value`) — matches when new == value; when optional
     *                            `from` is present, also requires old == from
     *                            (i.e. a specific `from` -> `value` transition).
     *
     * Comparison is string-normalised (scalars cast to string, non-scalars to
     * the empty string), consistent with the `eq`/`ne` handling in
     * `numericConditionMatches()`. An empty/missing `field` returns false.
     *
     * @param array<string, mixed> $condition The `condition` sub-document (`field`, `operator`, `value`, `from`).
     * @param array<string, mixed> $oldData   The object's data before the update.
     * @param array<string, mixed> $newData   The object's data after the update.
     *
     * @return bool True when the declared change occurred.
     */
    private function fieldChangeConditionMatches(array $condition, array $oldData, array $newData): bool
    {
        $field = (string) ($condition['field'] ?? '');
        if ($field === '') {
            return false;
        }

        $operator = (string) ($condition['operator'] ?? 'changed');
        $oldValue = ($oldData[$field] ?? null);
        $newValue = ($newData[$field] ?? null);

        $oldStr = '';
        if (is_scalar($oldValue) === true) {
            $oldStr = (string) $oldValue;
        }

        $newStr = '';
        if (is_scalar($newValue) === true) {
            $newStr = (string) $newValue;
        }

        if ($operator === 'changed') {
            return $oldStr !== $newStr;
        }

        if ($operator === 'equals') {
            if ($newStr !== (string) ($condition['value'] ?? '')) {
                return false;
            }

            if (isset($condition['from']) === true) {
                return $oldStr === (string) $condition['from'];
            }

            return true;
        }

        return false;
    }//end fieldChangeConditionMatches()

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
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) resolveRecipients() handles five recipient kinds
     * (users, groups, field, acl-read, acl-manage) plus expression evaluation, deduplication, and
     * exclusion; each kind requires its own resolution logic and must run in one pass to produce a
     * deduplicated uid list.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Each recipient entry is dispatched by kind; within
     * each kind there are null-guards and type checks — all are required branches of the spec's
     * recipient model.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Combinations of recipient kinds, expression evaluation,
     * null-guards, and exclusion list produce many paths; each is required by the spec's
     * recipient-resolution contract.
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) resolveObjectAclRecipients() walks owner,
     * per-user, per-role, and per-group ACL entries; each entry type requires separate null-guards
     * and uid-extraction logic that cannot be merged without losing the distinction between
     * role-based and explicit-user grants.
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) extractUidsFromRelation() handles six distinct
     * relation shapes (null, array-of-strings, array-of-objects with uid/id/userId, plain string);
     * each shape requires a separate extraction branch that cannot be unified.
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) resolveLocalizedSubject() handles three template
     * shapes (string, locale-keyed map, default-locale fallback) and within each shape applies
     * template-variable interpolation; each branch is required by the i18n subject contract.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Template shape × locale-match × defaultLocale fallback
     * × interpolation presence produce many paths; all are required by the spec's subject-resolution
     * priority chain.
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
            $defaultLocale = 'nl';
            if ($declared === true) {
                $defaultLocale = $template['defaultLocale'];
            }

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
     * Map a trigger to the canonical INotification subject the Notifier
     * renders. Decouples the displayed subject from the schema author's
     * rule name so every schema-declared in-app notification renders.
     *
     * @param string $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
     *
     * @return string The canonical subject identifier.
     */
    private function canonicalSubject(string $trigger): string
    {
        return match ($trigger) {
            'created'    => 'object_created',
            'transition' => 'object_transitioned',
            default      => 'object_updated',
        };
    }//end canonicalSubject()

    /**
     * Resolve the rule's originApp: the declared value, else the app owning
     * the schema's register, else 'openregister'.
     *
     * @param array<string, mixed> $spec   The notification rule spec.
     * @param ObjectEntity         $object The triggering object.
     *
     * @return string The resolved origin app id.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    private function resolveOriginApp(array $spec, ObjectEntity $object): string
    {
        $declared = ($spec['originApp'] ?? null);
        if (is_string($declared) === true && $declared !== '') {
            return $declared;
        }

        // Default: the app that owns the schema's register.
        $registerId = $object->getRegister();
        if ($this->registerMapper !== null && $registerId !== null && (string) $registerId !== '') {
            try {
                $register = $this->registerMapper->find($registerId, null, false, false);
                $owningApp = $register->getApplication();
                if (is_string($owningApp) === true && $owningApp !== '') {
                    return $owningApp;
                }
            } catch (\Throwable $e) {
                $this->logger->debug(
                    sprintf('[AnnotationNotificationDispatcher] originApp register lookup failed: %s', $e->getMessage())
                );
            }
        }

        return 'openregister';

    }//end resolveOriginApp()


    /**
     * Resolve declared `actions[]` to concrete, server-side deeplinks.
     *
     * Each returned action carries the i18n `label` map, the `primary` flag,
     * and a resolved absolute `url`. Targets that cannot be resolved (e.g. an
     * unreadable relation object) are dropped, never leaked.
     *
     * @param array<string, mixed> $spec      The notification rule spec.
     * @param ObjectEntity         $object    The triggering object.
     * @param array<string, mixed> $data      The triggering object's data.
     * @param string               $originApp The resolved origin app id.
     *
     * @return array<int, array{label: array<string,string>, primary: bool, url: string}>
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    private function resolveActions(array $spec, ObjectEntity $object, array $data, string $originApp): array
    {
        $declared = ($spec['actions'] ?? null);
        if (is_array($declared) === false || count($declared) === 0) {
            return [];
        }

        $resolved = [];
        foreach ($declared as $action) {
            if (is_array($action) === false) {
                continue;
            }

            $label = ($action['label'] ?? []);
            if (is_array($label) === false) {
                $label = [];
            }

            $url = $this->resolveActionTarget(
                target: ($action['target'] ?? []),
                object: $object,
                data: $data,
                originApp: $originApp
            );
            if ($url === null || $url === '') {
                // Target unresolved (e.g. relation not readable) — drop the
                // action rather than emit a dead or leaking button.
                continue;
            }

            $resolved[] = [
                'label'   => $label,
                'primary' => (bool) ($action['primary'] ?? false),
                'url'     => $url,
            ];
        }//end foreach

        return $resolved;

    }//end resolveActions()


    /**
     * Resolve a single action `target` to an absolute deeplink.
     *
     * Supported kinds:
     *  - object-detail: deeplink to the triggering object's detail page.
     *  - object-detail + { object: { kind: "relation", field } }: resolve the
     *    relation field on the triggering object to a related object's
     *    register/schema/uuid THROUGH OR RBAC ("Open client") — the related
     *    id is never trusted from the wire.
     *  - route: an originApp frontend route with {{prop}} interpolation (HTML-escaped).
     *  - url: an absolute URL, passed through verbatim.
     *
     * @param mixed                $target    The raw target spec.
     * @param ObjectEntity         $object    The triggering object.
     * @param array<string, mixed> $data      The triggering object's data.
     * @param string               $originApp The resolved origin app id.
     *
     * @return string|null The resolved absolute URL, or null when unresolvable.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per target kind.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    private function resolveActionTarget(mixed $target, ObjectEntity $object, array $data, string $originApp): ?string
    {
        if (is_array($target) === false) {
            return null;
        }

        $kind = (string) ($target['kind'] ?? '');

        if ($kind === 'url') {
            $href = (string) ($target['href'] ?? '');
            if (filter_var($href, FILTER_VALIDATE_URL) === false) {
                return null;
            }

            return $href;
        }

        if ($kind === 'route') {
            $app   = (string) ($target['app'] ?? $originApp);
            $route = (string) ($target['route'] ?? '');
            if ($route === '') {
                return null;
            }

            // Interpolate {{prop}} tokens from the object data, HTML-escaped.
            $interpolated = preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
                static function (array $m) use ($data): string {
                    $value = ($data[$m[1]] ?? '');
                    if (is_scalar($value) === false) {
                        return '';
                    }

                    return rawurlencode(htmlspecialchars((string) $value, (ENT_QUOTES | ENT_HTML5)));
                },
                $route
            );

            return $this->appRouteBase(app: $app).ltrim((string) $interpolated, '/');
        }

        if ($kind === 'object-detail') {
            $relationSpec = ($target['object'] ?? null);
            if (is_array($relationSpec) === true && (string) ($relationSpec['kind'] ?? '') === 'relation') {
                return $this->resolveRelationDeeplink(
                    field: (string) ($relationSpec['field'] ?? ''),
                    data: $data,
                    originApp: $originApp
                );
            }

            // Deeplink to the triggering object itself.
            return $this->buildObjectDetailLink(
                originApp: $originApp,
                registerId: (string) ($object->getRegister() ?? ''),
                schemaId: (string) ($object->getSchema() ?? ''),
                objectUuid: (string) ($object->getUuid() ?? '')
            );
        }//end if

        return null;

    }//end resolveActionTarget()


    /**
     * Resolve a relation-field deeplink ("Open client") through OR RBAC.
     *
     * The field value on the triggering object is treated only as a lookup
     * key; the related object is re-resolved via ObjectService::find() with
     * RBAC enabled so a deeplink is built only for an object the current
     * context may read. The wire-supplied id is never trusted directly.
     *
     * @param string               $field     The relation field name.
     * @param array<string, mixed> $data      The triggering object's data.
     * @param string               $originApp The resolved origin app id.
     *
     * @return string|null The deeplink, or null when the relation is empty/unreadable.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    private function resolveRelationDeeplink(string $field, array $data, string $originApp): ?string
    {
        if ($field === '' || $this->objectService === null) {
            return null;
        }

        $raw = ($data[$field] ?? null);
        $lookup = null;
        if (is_string($raw) === true && $raw !== '') {
            $lookup = $raw;
        } else if (is_array($raw) === true) {
            // Array relation: take the first id/uuid candidate.
            $candidate = ($raw['id'] ?? ($raw['uuid'] ?? ($raw[0] ?? null)));
            if (is_string($candidate) === true && $candidate !== '') {
                $lookup = $candidate;
            }
        }

        if ($lookup === null) {
            return null;
        }

        try {
            $related = $this->objectService->find(id: $lookup, _rbac: true);
        } catch (\Throwable $e) {
            $this->logger->debug(
                sprintf('[AnnotationNotificationDispatcher] relation deeplink resolve failed: %s', $e->getMessage())
            );
            return null;
        }

        if ($related === null) {
            return null;
        }

        return $this->buildObjectDetailLink(
            originApp: $originApp,
            registerId: (string) ($related->getRegister() ?? ''),
            schemaId: (string) ($related->getSchema() ?? ''),
            objectUuid: (string) ($related->getUuid() ?? '')
        );

    }//end resolveRelationDeeplink()


    /**
     * Build an object-detail deeplink against the originApp frontend route.
     *
     * @param string $originApp  The resolved origin app id.
     * @param string $registerId The object's register id.
     * @param string $schemaId   The object's schema id.
     * @param string $objectUuid The object's uuid.
     *
     * @return string|null The absolute deeplink, or null when ids are missing.
     */
    private function buildObjectDetailLink(string $originApp, string $registerId, string $schemaId, string $objectUuid): ?string
    {
        if ($registerId === '' || $schemaId === '' || $objectUuid === '') {
            return null;
        }

        return $this->appRouteBase(app: $originApp)
            .sprintf('#/registers/%s/schemas/%s/objects/%s', $registerId, $schemaId, $objectUuid);

    }//end buildObjectDetailLink()


    /**
     * Resolve the absolute frontend route base for an app id.
     *
     * Falls back to the openregister dashboard route when the app is not
     * installed or the URL generator is unavailable.
     *
     * @param string $app The app id.
     *
     * @return string The absolute route base (always ends without a trailing fragment).
     */
    private function appRouteBase(string $app): string
    {
        if ($this->urlGenerator === null) {
            return '/index.php/apps/'.$app.'/';
        }

        $routeName = $app.'.dashboard.page';
        if ($app === 'openregister') {
            $routeName = 'openregister.dashboard.page';
        }

        try {
            return $this->urlGenerator->linkToRouteAbsolute($routeName);
        } catch (\Throwable $e) {
            // App has no dashboard.page route — fall back to the app base path.
            return $this->urlGenerator->getAbsoluteURL('/index.php/apps/'.$app.'/');
        }

    }//end appRouteBase()


    /**
     * Enqueue a background web-push dispatch job per recipient.
     *
     * Push I/O (VAPID signing + aes128gcm encryption + endpoint POST) runs in
     * WebPushDispatchJob so the originating object-save request returns
     * immediately. Anonymous / non-uid recipients are skipped (web-push is
     * user+browser scoped).
     *
     * @param array<int, string>               $recipients Resolved recipient uids.
     * @param string                           $ruleId     The rule id.
     * @param string                           $originApp  The resolved origin app id.
     * @param string                           $subject    The notification subject text.
     * @param array<int, array<string, mixed>> $actions    Resolved action buttons.
     * @param ObjectEntity                     $object     The triggering object.
     *
     * @return void
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    private function enqueueWebPush(
        array $recipients,
        string $ruleId,
        string $originApp,
        string $subject,
        array $actions,
        ObjectEntity $object
    ): void {
        if ($this->jobList === null) {
            $this->logger->debug('[AnnotationNotificationDispatcher] web-push declared but IJobList unavailable.');
            return;
        }

        $objectUuid = (string) ($object->getUuid() ?? '');
        $tag        = sprintf('openregister-%s-%s', $ruleId, ($objectUuid !== '' ? $objectUuid : $ruleId));

        foreach ($recipients as $uid) {
            if (is_string($uid) === false || $uid === '' || $this->userExists(uid: $uid) === false) {
                continue;
            }

            $this->jobList->add(
                WebPushDispatchJob::class,
                [
                    'uid'       => $uid,
                    'ruleId'    => $ruleId,
                    'originApp' => $originApp,
                    'title'     => $subject,
                    'body'      => $subject,
                    'tag'       => $tag,
                    'actions'   => $actions,
                ]
            );
        }

    }//end enqueueWebPush()


    /**
     * Persist + dispatch a single in-app Nextcloud notification row.
     *
     * The INotification carries the canonical `$subjectKey` (which the
     * Notifier switches on to render localised text + an object-detail
     * action link), the routing parameters the action link needs
     * (`objectTitle`, `registerId`, `schemaId`, `objectUuid`), the rule's
     * own name under `notificationType`, and the pre-rendered subject text
     * under `_text` (so a schema's custom per-locale subject still wins).
     *
     * Push delivery needs no extra code: `notify_push` auto-intercepts this
     * same `IManager::notify()` call and relays it to connected devices.
     *
     * @param string                    $uid           Recipient user UID.
     * @param ObjectEntity              $object        The object the event happened on.
     * @param string                    $subjectKey    Canonical subject identifier (object_created/_updated/_transitioned).
     * @param string                    $name          Annotation rule name (notification type identifier).
     * @param string                    $subject       Pre-interpolated subject text.
     * @param array<string, mixed>      $context       Trigger context (action, from, to).
     * @param string                    $originApp     Resolved originApp (declared or register-owning app).
     * @param array<int, array<string, mixed>> $actions Resolved action buttons (label map + deeplink url + primary).
     * @param bool                      $webPushActive Whether the rule also delivers over web-push (drives duplicate suppression).
     *
     * @return void
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    private function emitNotification(
        string $uid,
        ObjectEntity $object,
        string $subjectKey,
        string $name,
        string $subject,
        array $context,
        string $originApp='openregister',
        array $actions=[],
        bool $webPushActive=false
    ): void {
        $objectUuid = (string) ($object->getUuid() ?? '');
        $linkParams = [
            'objectTitle' => (string) ($object->getName() ?? $objectUuid),
            'registerId'  => $object->getRegister(),
            'schemaId'    => $object->getSchema(),
            'objectUuid'  => $objectUuid,
            // The resolved origin app drives the notifier icon (originApp hex
            // composite) and the deeplink base for declared actions.
            'originApp'   => $originApp,
            // Declared, server-resolved action buttons. The notifier renders
            // these via addAction(); an empty array keeps the implicit "View".
            '_actions'    => $actions,
            // Stable notification tag used by the Service Worker / foreground
            // client to COLLAPSE the web-push and the stock popup so the
            // recipient never sees a duplicate. Keyed by (rule, object).
            '_tag'        => sprintf('openregister-%s-%s', $name, ($objectUuid !== '' ? $objectUuid : $name)),
            // Foreground-suppression flag: when web-push is active for this
            // rule, an open tab that holds an active push subscription
            // declines to render the plain duplicate popup for this tag
            // (see js/openregister-push-sw.js + src/webpush/register.js).
            '_suppressForegroundPopup' => $webPushActive,
        ];

        $objectRef = $name;
        if ($objectUuid !== '') {
            $objectRef = $objectUuid;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('openregister')
                ->setUser($uid)
                ->setDateTime(new DateTime())
                ->setObject('object', $objectRef)
                ->setSubject(
                    $subjectKey,
                    array_merge($context, $linkParams, ['_text' => $subject, 'notificationType' => $name])
                );
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
        $objectRef = $name;
        if ($objectId !== '') {
            $objectRef = $objectId;
        }

        try {
            $event = $this->activityManager->generateEvent();
            $event
                ->setApp('openregister')
                ->setType('openregister_objects')
                ->setAffectedUser($uid)
                ->setSubject($name, ['_text' => $subject])
                ->setObject('object', 0, $objectRef)
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
        if (is_array($value) === true) {
            return $value;
        }

        return null;
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
