<?php

/**
 * OpenRegister Annotation Notification Dispatcher
 *
 * Evaluates x-openregister-notifications rules for system entity events and
 * dispatches notifications to the configured recipients via Nextcloud's
 * INotificationManager. Reuses the existing channels, rate-limiting, coalescing,
 * and i18n machinery; only the rule source and event source are extended to
 * cover OpenRegister's own system entity types.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2.1
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Dispatcher that evaluates x-openregister-notifications rules for system entities.
 *
 * Each rule descriptor has the shape:
 *   [
 *     'trigger'    => 'updated'|'created'|'deleted',
 *     'condition'  => null | ['field'=>..., 'operator'=>'changed'|'equals', 'value'=>..., 'from'=>...],
 *     'recipients' => [ ['kind'=>'groups', 'groups'=>[...]] ],
 *     'subject'    => ['nl'=>'...', 'en'=>'...'],
 *   ]
 *
 * The field-change condition block mirrors the spec requirement for
 * notification-updated-field-change-condition: when condition is absent the
 * rule fires on every trigger; when present it evaluates old-vs-new data and
 * fails closed (does not fire) when old data is unavailable.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AnnotationNotificationDispatcher
{
    /**
     * Constructor.
     *
     * @param INotificationManager $notificationManager Nextcloud notification manager.
     * @param IGroupManager        $groupManager        Group manager for recipient resolution.
     * @param LoggerInterface      $logger              Logger.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2.1
     */
    public function __construct(
        private readonly INotificationManager $notificationManager,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Evaluate and dispatch all matching rules for a system entity event.
     *
     * Iterates over the provided rules, evaluates each against the trigger and
     * optional field-change condition, then sends notifications to the resolved
     * recipients. Rules whose condition requires old data but old data is absent
     * are silently skipped (fail-closed).
     *
     * @param string                           $entityType Canonical system entity type slug.
     * @param string                           $trigger    Event trigger type ('updated', 'created', 'deleted').
     * @param array<string, mixed>             $newData    New entity state as key-value array.
     * @param array<string, mixed>|null        $oldData    Old entity state, or null when unavailable.
     * @param array<int, array<string, mixed>> $rules      Rules from SystemSchemaNotificationRegistry.
     *
     * @return int Number of notifications dispatched.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.2
     */
    public function dispatch(
        string $entityType,
        string $trigger,
        array $newData,
        ?array $oldData,
        array $rules
    ): int {
        $dispatched = 0;

        foreach ($rules as $rule) {
            if ($this->ruleMatchesTrigger(rule: $rule, trigger: $trigger) === false) {
                continue;
            }

            if ($this->ruleConditionPasses(rule: $rule, newData: $newData, oldData: $oldData) === false) {
                continue;
            }

            $recipients = $this->resolveRecipients(rule: $rule);
            if (empty($recipients) === true) {
                $this->logger->debug(
                    message: '[AnnotationNotificationDispatcher] Rule has no resolvable recipients, skipping',
                    context: ['entityType' => $entityType, 'trigger' => $trigger]
                );
                continue;
            }

            $subjectKey    = $this->buildSubjectKey(entityType: $entityType, trigger: $trigger);
            $subjectParams = $this->buildSubjectParams(newData: $newData, rule: $rule);

            foreach ($recipients as $userId) {
                $dispatched += $this->sendNotification(
                    userId: $userId,
                    entityType: $entityType,
                    subjectKey: $subjectKey,
                    subjectParams: $subjectParams,
                    newData: $newData
                );
            }
        }//end foreach

        return $dispatched;
    }//end dispatch()

    /**
     * Check whether a rule's trigger matches the current event trigger type.
     *
     * @param array<string, mixed> $rule    Rule descriptor.
     * @param string               $trigger Current event trigger type.
     *
     * @return bool True when triggers match.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
     */
    private function ruleMatchesTrigger(array $rule, string $trigger): bool
    {
        $ruleTrigger = $rule['trigger'] ?? '';
        return $ruleTrigger === $trigger;
    }//end ruleMatchesTrigger()

    /**
     * Evaluate the optional field-change condition on an 'updated' rule.
     *
     * Behaviour per spec (notification-updated-field-change-condition):
     * - No condition → always passes.
     * - condition present + old data unavailable → fails closed (returns false).
     * - operator 'changed' → passes when new value differs from old value.
     * - operator 'equals' → passes when new value equals condition value.
     * - operator 'equals' + 'from' → additionally requires old value equals 'from'.
     *
     * @param array<string, mixed>      $rule    Rule descriptor.
     * @param array<string, mixed>      $newData New entity state.
     * @param array<string, mixed>|null $oldData Old entity state.
     *
     * @return bool True when the condition passes (or no condition is set).
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.2
     */
    private function ruleConditionPasses(array $rule, array $newData, ?array $oldData): bool
    {
        $condition = $rule['condition'] ?? null;
        if ($condition === null) {
            return true;
        }

        // Fail closed: condition present but no old data available.
        if ($oldData === null) {
            $this->logger->debug(
                message: '[AnnotationNotificationDispatcher] Condition present but old data unavailable — failing closed',
                context: ['condition' => $condition]
            );
            return false;
        }

        $field    = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'changed';
        $newValue = $newData[$field] ?? null;
        $oldValue = $oldData[$field] ?? null;

        if ($operator === 'changed') {
            return $newValue !== $oldValue;
        }

        if ($operator === 'equals') {
            $targetValue = $condition['value'] ?? null;
            $newMatches  = ($newValue === $targetValue || (string) $newValue === (string) $targetValue);
            if ($newMatches === false) {
                return false;
            }

            // Optional 'from' constraint on old value.
            if (isset($condition['from']) === true) {
                $fromValue  = $condition['from'];
                $oldMatches = ($oldValue === $fromValue || (string) $oldValue === (string) $fromValue);
                return $oldMatches;
            }

            return true;
        }

        $this->logger->warning(
            message: '[AnnotationNotificationDispatcher] Unknown condition operator, skipping rule',
            context: ['operator' => $operator]
        );
        return false;
    }//end ruleConditionPasses()

    /**
     * Resolve a flat list of user IDs from a rule's recipient descriptors.
     *
     * Supported recipient kinds:
     * - 'groups': resolve all users in each named Nextcloud group.
     *
     * Returns deduplicated user ID strings.
     *
     * @param array<string, mixed> $rule Rule descriptor.
     *
     * @return array<string> Deduplicated list of user IDs to notify.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-4.1
     */
    private function resolveRecipients(array $rule): array
    {
        $userIds    = [];
        $recipients = $rule['recipients'] ?? [];

        foreach ($recipients as $recipient) {
            $kind = $recipient['kind'] ?? '';

            if ($kind === 'groups') {
                $groups = $recipient['groups'] ?? [];
                foreach ($groups as $groupId) {
                    $group = $this->groupManager->get($groupId);
                    if ($group === null) {
                        $this->logger->warning(
                            message: '[AnnotationNotificationDispatcher] Group not found, skipping',
                            context: ['groupId' => $groupId]
                        );
                        continue;
                    }

                    foreach ($group->getUsers() as $user) {
                        $userIds[$user->getUID()] = true;
                    }
                }
            }
        }//end foreach

        return array_keys($userIds);
    }//end resolveRecipients()

    /**
     * Build the notification subject key from entity type and trigger.
     *
     * The key is used by Notifier::prepare() to look up the correct
     * bilingual subject template.
     *
     * @param string $entityType Canonical system entity type slug.
     * @param string $trigger    Event trigger type.
     *
     * @return string Notification subject key.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
     */
    private function buildSubjectKey(string $entityType, string $trigger): string
    {
        return 'system_entity_'.$entityType.'_'.$trigger;
    }//end buildSubjectKey()

    /**
     * Build notification subject parameters from entity data and rule subjects.
     *
     * Stores the bilingual subject templates alongside entity metadata so
     * Notifier::prepare() can render the correct language at display time.
     *
     * @param array<string, mixed> $newData New entity state.
     * @param array<string, mixed> $rule    Rule descriptor.
     *
     * @return array<string, mixed> Subject parameter map.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
     */
    private function buildSubjectParams(array $newData, array $rule): array
    {
        $subjects = $rule['subject'] ?? [];
        return [
            'subject_nl'  => $subjects['nl'] ?? '',
            'subject_en'  => $subjects['en'] ?? '',
            'entityTitle' => $newData['title'] ?? ($newData['name'] ?? ''),
            'entityId'    => (string) ($newData['id'] ?? ''),
            'entityUuid'  => $newData['uuid'] ?? '',
        ];
    }//end buildSubjectParams()

    /**
     * Create and send a single Nextcloud notification to a user.
     *
     * @param string               $userId        Target user ID.
     * @param string               $entityType    System entity type slug.
     * @param string               $subjectKey    Notification subject key.
     * @param array<string, mixed> $subjectParams Subject parameters.
     * @param array<string, mixed> $newData       New entity state (used for object ID).
     *
     * @return int 1 on success, 0 on failure.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-4.2
     */
    private function sendNotification(
        string $userId,
        string $entityType,
        string $subjectKey,
        array $subjectParams,
        array $newData
    ): int {
        try {
            $notification = $this->notificationManager->createNotification();

            $objectId = (string) ($newData['id'] ?? $newData['uuid'] ?? '0');

            $notification->setApp(app: 'openregister')
                ->setUser(user: $userId)
                ->setDateTime(dateTime: new DateTime())
                ->setObject(type: 'system_'.$entityType, id: $objectId)
                ->setSubject(subject: $subjectKey, parameters: $subjectParams);

            $this->notificationManager->notify(notification: $notification);

            return 1;
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[AnnotationNotificationDispatcher] Failed to send notification to user',
                context: [
                    'userId'     => $userId,
                    'entityType' => $entityType,
                    'error'      => $e->getMessage(),
                ]
            );
            return 0;
        }//end try
    }//end sendNotification()
}//end class
