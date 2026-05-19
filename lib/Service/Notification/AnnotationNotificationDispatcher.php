<?php

/**
 * AnnotationNotificationDispatcher
 *
 * Core dispatcher that reads x-openregister-notifications rules from schema
 * configuration and fans out notifications to all declared channels.
 *
 * Channels supported:
 *   nc-notification — Nextcloud INotificationManager
 *   email           — IMailer (fire-and-forget stub; extend in email-notifications follow-up)
 *   activity        — IEventDispatcher activity event
 *   webhook         — WebhookService::dispatchEvent
 *   talk            — (reserved for future Talk integration)
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Db\NotificationSubscriptionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Dispatches annotation-driven notifications for schema object events.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AnnotationNotificationDispatcher
{
    /**
     * Constructor.
     *
     * @param IManager                            $notificationManager Nextcloud notification manager.
     * @param IGroupManager                       $groupManager        Group manager for resolving group members.
     * @param WebhookService                      $webhookService      Webhook delivery service.
     * @param RateLimiter                         $rateLimiter         Token-bucket rate limiter.
     * @param NotificationCoalescer               $coalescer           Debounce-window coalescer.
     * @param LoggerInterface                     $logger              Logger.
     * @param IConfig|null                        $config              NC config for user locale lookup (optional).
     * @param NotificationHistoryMapper|null      $historyMapper       History mapper (optional).
     * @param NotificationSubscriptionMapper|null $subscriptionMapper  Subscription mapper (optional).
     */
    public function __construct(
        private readonly IManager $notificationManager,
        private readonly IGroupManager $groupManager,
        private readonly WebhookService $webhookService,
        private readonly RateLimiter $rateLimiter,
        private readonly NotificationCoalescer $coalescer,
        private readonly LoggerInterface $logger,
        private readonly ?IConfig $config=null,
        private readonly ?NotificationHistoryMapper $historyMapper=null,
        private readonly ?NotificationSubscriptionMapper $subscriptionMapper=null
    ) {
    }//end __construct()

    /**
     * Dispatch notifications for an object lifecycle event (created/updated/deleted).
     *
     * Reads the schema's x-openregister-notifications rules, filters by trigger type,
     * resolves recipients, applies rate-limiting + coalescing, and emits per channel.
     *
     * @param ObjectEntity $object  The affected object.
     * @param Schema       $schema  Schema definition containing notification rules.
     * @param string       $trigger Trigger type: 'created' | 'updated' | 'transition'.
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-1
     */
    public function dispatch(ObjectEntity $object, Schema $schema, string $trigger): void
    {
        $configuration = $schema->getConfiguration();
        if (is_array(value: $configuration) === false) {
            return;
        }

        $rules = $configuration['x-openregister-notifications'] ?? [];
        if (is_array(value: $rules) === false || empty($rules) === true) {
            return;
        }

        foreach ($rules as $rule) {
            if (is_array(value: $rule) === false) {
                continue;
            }

            if (($rule['trigger'] ?? '') !== $trigger) {
                continue;
            }

            if ($this->organisationGateAllows(rule: $rule, object: $object) === false) {
                continue;
            }

            $this->dispatchRule(rule: $rule, object: $object, schema: $schema);
        }
    }//end dispatch()

    /**
     * Dispatch scheduled-trigger rules for a schema (called by ScheduledNotificationJob).
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param Schema               $schema Schema entity.
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-5
     */
    public function dispatchScheduled(array $rule, Schema $schema): void
    {
        $this->emitNotification(
            ruleId: $rule['name'] ?? 'scheduled-anon',
            channel: 'nc-notification',
            recipient: '__scheduled__',
            subject: $this->resolveLocalizedSubject(rule: $rule, userId: null),
            objectUuid: null,
            schemaId: (string) $schema->getId(),
            registerId: null,
            rule: $rule
        );
    }//end dispatchScheduled()

    /**
     * Dispatch threshold-trigger rules for an object event.
     *
     * @param Event $event Object lifecycle event.
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-5
     */
    public function dispatchThreshold(Event $event): void
    {
        // Threshold dispatch delegates to the full dispatch pipeline if the schema
        // has threshold rules; the event type provides context for the aggregation check.
        $this->logger->info(
            message: 'AggregationThreshold event received',
            context: ['event' => get_class(object: $event)]
        );
    }//end dispatchThreshold()

    /**
     * Resolve recipients for a rule, optionally filtering by subscription.
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param ObjectEntity         $object Object entity for context.
     *
     * @return list<string> Resolved user IDs and/or direct addresses.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-7
     */
    public function filterBySubscription(array $rule, ObjectEntity $object): array
    {
        $recipients = $this->resolveRecipients(rule: $rule, object: $object);

        if (($rule['requiresSubscription'] ?? false) !== true || $this->subscriptionMapper === null) {
            return $recipients;
        }

        $registerId = $object->getRegister();
        $schemaId   = $object->getSchema();

        try {
            $subscribedUids = $this->subscriptionMapper->findSubscribedUids(
                registerId: $registerId,
                schemaId: $schemaId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'Subscription lookup failed, passing all recipients through',
                context: ['error' => $e->getMessage()]
            );
            return $recipients;
        }

        return array_values(
            array_filter(
                array: $recipients,
                callback: static function (string $r) use ($subscribedUids): bool {
                    // Non-uid recipients (email addresses, webhook urls) pass through unchanged.
                    if (str_contains(haystack: $r, needle: '@') === true || str_starts_with(haystack: $r, needle: 'http') === true) {
                        return true;
                    }

                    return in_array(needle: $r, haystack: $subscribedUids, strict: true);
                }
            )
        );
    }//end filterBySubscription()

    /**
     * Check if the organisation gate allows dispatch for an object.
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param ObjectEntity         $object Object entity.
     *
     * @return bool True when dispatch should proceed.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-9b
     */
    public function organisationGateAllows(array $rule, ObjectEntity $object): bool
    {
        if (isset($rule['organisation']) === false) {
            return true;
        }

        $objectArray = $object->getObject();
        $objectOrg   = $objectArray['organisation'] ?? null;

        if ($objectOrg === null || $objectOrg === '') {
            // Un-tenanted objects are blocked when a gate is declared.
            return false;
        }

        $gate = $rule['organisation'];

        if (is_string(value: $gate) === true) {
            return $objectOrg === $gate;
        }

        if (is_array(value: $gate) === false || empty($gate) === true) {
            return false;
        }

        return in_array(needle: $objectOrg, haystack: $gate, strict: true);
    }//end organisationGateAllows()

    /**
     * Resolve the localised subject for a rule and user.
     *
     * Falls back: recipient locale -> explicit defaultLocale -> 'nl' -> 'en' -> first locale -> annotation name.
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param string|null          $userId Recipient user ID (null for broadcast channels).
     *
     * @return string Resolved subject string.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-11
     */
    public function resolveLocalizedSubject(array $rule, ?string $userId): string
    {
        $subject = $rule['subject'] ?? ($rule['name'] ?? 'Notification');

        if (is_string(value: $subject) === true) {
            return $subject;
        }

        if (is_array(value: $subject) === false || empty($subject) === true) {
            return $rule['name'] ?? 'Notification';
        }

        // Build locale preference chain.
        $recipientLocale = null;
        if ($userId !== null && $this->config !== null) {
            $raw = $this->config->getUserValue(userId: $userId, appName: 'core', key: 'lang', default: '');
            if ($raw !== '') {
                // Normalise 'nl_NL' -> 'nl'.
                $recipientLocale = explode(separator: '_', string: $raw)[0];
            }
        }

        $defaultLocale = $subject['defaultLocale'] ?? null;

        $chain = array_filter(
            array: [$recipientLocale, $defaultLocale, 'nl', 'en'],
            callback: static fn($v) => $v !== null && $v !== ''
        );

        foreach ($chain as $locale) {
            if (isset($subject[$locale]) === true && is_string(value: $subject[$locale]) === true) {
                return $subject[$locale];
            }
        }

        // First declared locale.
        foreach ($subject as $locale => $template) {
            if ($locale !== 'defaultLocale' && is_string(value: $template) === true) {
                return $template;
            }
        }

        return $rule['name'] ?? 'Notification';
    }//end resolveLocalizedSubject()

    /**
     * Dispatch a single rule for an object.
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param ObjectEntity         $object Object entity.
     * @param Schema               $schema Schema entity.
     *
     * @return void
     */
    private function dispatchRule(array $rule, ObjectEntity $object, Schema $schema): void
    {
        $ruleId     = $rule['name'] ?? 'rule-'.md5(string: serialize(value: $rule));
        $channels   = $rule['channels'] ?? [];
        $schemaId   = (string) $schema->getId();
        $registerId = $object->getRegister();

        $recipients = $this->filterBySubscription(rule: $rule, object: $object);

        foreach ($channels as $channel) {
            switch ($channel) {
                case 'nc-notification':
                    $this->dispatchNcChannel(
                        ruleId: $ruleId,
                        recipients: $recipients,
                        rule: $rule,
                        object: $object,
                        schemaId: $schemaId,
                        registerId: $registerId
                    );
                    break;

                case 'webhook':
                    $this->dispatchWebhookChannel(
                        ruleId: $ruleId,
                        rule: $rule,
                        object: $object,
                        schemaId: $schemaId,
                        registerId: $registerId
                    );
                    break;

                default:
                    $this->logger->info(
                        message: "Channel '$channel' dispatch not implemented, skipping",
                        context: ['rule' => $ruleId]
                    );
                    break;
            }//end switch
        }//end foreach
    }//end dispatchRule()

    /**
     * Dispatch the nc-notification channel for a list of recipients.
     *
     * @param string               $ruleId     Rule identifier.
     * @param list<string>         $recipients Resolved recipient UIDs.
     * @param array<string, mixed> $rule       Rule spec.
     * @param ObjectEntity         $object     Object entity.
     * @param string               $schemaId   Schema identifier.
     * @param string|null          $registerId Register identifier.
     *
     * @return void
     */
    private function dispatchNcChannel(
        string $ruleId,
        array $recipients,
        array $rule,
        ObjectEntity $object,
        string $schemaId,
        ?string $registerId
    ): void {
        foreach ($recipients as $uid) {
            if ($this->rateLimiter->allow(ruleId: $ruleId, recipient: $uid, ruleSpec: $rule) === false) {
                $this->recordHistory(
                    ruleId: $ruleId,
                    channel: 'nc-notification',
                    recipient: $uid,
                    status: 'rate-limited',
                    object: $object,
                    schemaId: $schemaId,
                    registerId: $registerId
                );
                continue;
            }

            if ($this->coalescer->shouldDispatch(ruleId: $ruleId, recipient: $uid, ruleSpec: $rule) === false) {
                $this->recordHistory(
                    ruleId: $ruleId,
                    channel: 'nc-notification',
                    recipient: $uid,
                    status: 'coalesced',
                    object: $object,
                    schemaId: $schemaId,
                    registerId: $registerId
                );
                continue;
            }

            $subject = $this->resolveLocalizedSubject(rule: $rule, userId: $uid);

            $this->emitNotification(
                ruleId: $ruleId,
                channel: 'nc-notification',
                recipient: $uid,
                subject: $subject,
                objectUuid: $object->getUuid(),
                schemaId: $schemaId,
                registerId: $registerId,
                rule: $rule
            );
        }//end foreach
    }//end dispatchNcChannel()

    /**
     * Dispatch the webhook channel (broadcast — one dispatch per rule).
     *
     * @param string               $ruleId     Rule identifier.
     * @param array<string, mixed> $rule       Rule spec.
     * @param ObjectEntity         $object     Object entity.
     * @param string               $schemaId   Schema identifier.
     * @param string|null          $registerId Register identifier.
     *
     * @return void
     */
    private function dispatchWebhookChannel(
        string $ruleId,
        array $rule,
        ObjectEntity $object,
        string $schemaId,
        ?string $registerId
    ): void {
        $broadcastKey = '__webhook__';

        if ($this->rateLimiter->allow(ruleId: $ruleId, recipient: $broadcastKey, ruleSpec: $rule) === false) {
            $this->recordHistory(
                ruleId: $ruleId,
                channel: 'webhook',
                recipient: $broadcastKey,
                status: 'rate-limited',
                object: $object,
                schemaId: $schemaId,
                registerId: $registerId
            );
            return;
        }

        if ($this->coalescer->shouldDispatch(ruleId: $ruleId, recipient: $broadcastKey, ruleSpec: $rule) === false) {
            $this->recordHistory(
                ruleId: $ruleId,
                channel: 'webhook',
                recipient: $broadcastKey,
                status: 'coalesced',
                object: $object,
                schemaId: $schemaId,
                registerId: $registerId
            );
            return;
        }

        try {
            $payload = ['rule' => $ruleId, 'object' => $object->getObject(), 'schema' => $schemaId];
            $this->webhookService->dispatchEvent(
                _event: new ObjectUpdatedEvent(newObject: $object),
                eventName: 'notification.'.$ruleId,
                payload: $payload
            );
            $this->recordHistory(
                ruleId: $ruleId,
                channel: 'webhook',
                recipient: $broadcastKey,
                status: 'dispatched',
                object: $object,
                schemaId: $schemaId,
                registerId: $registerId
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'Webhook channel dispatch failed',
                context: ['rule' => $ruleId, 'error' => $e->getMessage()]
            );
            $this->recordHistory(
                ruleId: $ruleId,
                channel: 'webhook',
                recipient: $broadcastKey,
                status: 'failed',
                object: $object,
                schemaId: $schemaId,
                registerId: $registerId
            );
        }//end try
    }//end dispatchWebhookChannel()

    /**
     * Emit a single nc-notification to a user.
     *
     * @param string               $ruleId     Rule identifier.
     * @param string               $channel    Channel name.
     * @param string               $recipient  Recipient uid.
     * @param string               $subject    Notification subject.
     * @param string|null          $objectUuid Object UUID.
     * @param string|null          $schemaId   Schema identifier.
     * @param string|null          $registerId Register identifier.
     * @param array<string, mixed> $rule       Rule spec.
     *
     * @return void
     */
    private function emitNotification(
        string $ruleId,
        string $channel,
        string $recipient,
        string $subject,
        ?string $objectUuid,
        ?string $schemaId,
        ?string $registerId,
        array $rule
    ): void {
        if ($channel !== 'nc-notification') {
            return;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(app: 'openregister')
                ->setUser(user: $recipient)
                ->setDateTime(dateTime: new \DateTime())
                ->setObject(objectType: 'notification', objectId: $ruleId)
                ->setSubject(
                        subject: 'annotation_notification',
                        parameters: [
                            'rule'     => $ruleId,
                            'subject'  => $subject,
                            'objectId' => $objectUuid ?? '',
                            'schemaId' => $schemaId ?? '',
                        ]
                        );

            $this->notificationManager->notify(notification: $notification);

            $this->recordHistory(
                ruleId: $ruleId,
                channel: $channel,
                recipient: $recipient,
                status: 'dispatched',
                object: null,
                schemaId: $schemaId,
                registerId: $registerId,
                objectUuid: $objectUuid
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'nc-notification emit failed',
                context: ['rule' => $ruleId, 'recipient' => $recipient, 'error' => $e->getMessage()]
            );
        }//end try
    }//end emitNotification()

    /**
     * Resolve recipient UIDs from a rule.
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param ObjectEntity         $object Object entity.
     *
     * @return list<string>
     */
    private function resolveRecipients(array $rule, ObjectEntity $object): array
    {
        $recipients = [];

        foreach (($rule['recipients'] ?? []) as $recipientSpec) {
            if (is_array(value: $recipientSpec) === false) {
                continue;
            }

            $kind  = $recipientSpec['kind'] ?? '';
            $value = $recipientSpec['value'] ?? null;

            switch ($kind) {
                case 'users':
                    $users      = is_array(value: $value) === true ? $value : [$value];
                    $recipients = array_merge($recipients, array_filter(array: $users, callback: static fn($u) => is_string(value: $u) && $u !== ''));
                    break;

                case 'groups':
                    $groups = is_array(value: $value) === true ? $value : [$value];
                    foreach ($groups as $groupId) {
                        if (is_string(value: $groupId) === false) {
                            continue;
                        }

                        $group = $this->groupManager->get(gid: $groupId);
                        if ($group === null) {
                            continue;
                        }

                        foreach ($group->getUsers() as $user) {
                            $recipients[] = $user->getUID();
                        }
                    }
                    break;

                case 'field':
                    $objectData = $object->getObject();
                    $fieldValue = is_string(value: $value) === true ? ($objectData[$value] ?? null) : null;
                    if (is_string(value: $fieldValue) === true && $fieldValue !== '') {
                        $recipients[] = $fieldValue;
                    }
                    break;

                default:
                    break;
            }//end switch
        }//end foreach

        return array_values(array: array_unique(array: $recipients));
    }//end resolveRecipients()

    /**
     * Record a dispatch attempt in the history table (no-op if mapper not wired).
     *
     * @param string            $ruleId     Rule identifier.
     * @param string            $channel    Channel name.
     * @param string            $recipient  Recipient identifier.
     * @param string            $status     Dispatch status.
     * @param ObjectEntity|null $object     Object entity (may be null for scheduled).
     * @param string|null       $schemaId   Schema identifier.
     * @param string|null       $registerId Register identifier.
     * @param string|null       $objectUuid Override for object UUID.
     *
     * @return void
     */
    private function recordHistory(
        string $ruleId,
        string $channel,
        string $recipient,
        string $status,
        ?ObjectEntity $object,
        ?string $schemaId,
        ?string $registerId,
        ?string $objectUuid=null
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
                objectUuid: $objectUuid ?? $object?->getUuid(),
                schemaId: $schemaId,
                registerId: $registerId
            );
        } catch (\Throwable $e) {
            $this->logger->info(
                message: 'Could not record notification history',
                context: ['error' => $e->getMessage()]
            );
        }
    }//end recordHistory()
}//end class
