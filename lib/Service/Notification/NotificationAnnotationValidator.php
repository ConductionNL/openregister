<?php

/**
 * NotificationAnnotationValidator
 *
 * Validates notification rule annotations declared on schemas under
 * configuration['x-openregister-notifications'].
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Validates notification annotations on schema configurations.
 *
 * A schema may declare an array of notification rules:
 *   configuration['x-openregister-notifications'] = [
 *     {
 *       "name": "on-create-notify-admins",
 *       "trigger": "created",
 *       "subject": "Object created",
 *       "recipients": [{"kind": "groups", "value": ["admin"]}],
 *       "channels": ["nc-notification"]
 *     }
 *   ]
 *
 * @psalm-suppress UnusedClass
 */
class NotificationAnnotationValidator
{

    /**
     * Valid trigger types.
     *
     * @var list<string>
     */
    private const VALID_TRIGGERS = ['created', 'updated', 'transition', 'scheduled', 'threshold'];

    /**
     * Valid recipient kinds.
     *
     * @var list<string>
     */
    private const VALID_RECIPIENT_KINDS = ['users', 'field', 'groups', 'relation', 'object-acl', 'expression'];

    /**
     * Valid delivery channels.
     *
     * @var list<string>
     */
    private const VALID_CHANNELS = ['nc-notification', 'email', 'activity', 'webhook', 'talk'];

    /**
     * Validate all notification rules in a schema configuration array.
     *
     * @param array<string, mixed> $configuration Schema configuration array.
     * @param string               $schemaName    Schema name (for error context).
     *
     * @return list<string> List of error strings (empty = valid).
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-2
     */
    public function validate(array $configuration, string $schemaName=''): array
    {
        $rules = $configuration['x-openregister-notifications'] ?? null;
        if ($rules === null) {
            return [];
        }

        if (is_array(value: $rules) === false) {
            return ["$schemaName: x-openregister-notifications must be an array"];
        }

        $errors = [];
        foreach ($rules as $index => $rule) {
            $ruleErrors = $this->validateRule(rule: $rule, index: $index, schemaName: $schemaName);
            $errors     = array_merge($errors, $ruleErrors);
        }

        return $errors;
    }//end validate()

    /**
     * Validate a single notification rule.
     *
     * @param mixed  $rule       Rule spec (must be array).
     * @param int    $index      Rule index in the rules array.
     * @param string $schemaName Schema name for error context.
     *
     * @return list<string>
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-2
     */
    private function validateRule(mixed $rule, int $index, string $schemaName): array
    {
        if (is_array(value: $rule) === false) {
            return ["$schemaName rule[$index]: must be an object"];
        }

        $errors = [];
        $prefix = "$schemaName rule[$index]";

        // Validate trigger.
        if (isset($rule['trigger']) === false) {
            $errors[] = "$prefix: missing required field 'trigger'";
        } else if (in_array(needle: $rule['trigger'], haystack: self::VALID_TRIGGERS, strict: true) === false) {
            $errors[] = "$prefix: unknown trigger '{$rule['trigger']}'; valid: ".implode(separator: ', ', array: self::VALID_TRIGGERS);
        }

        // Validate subject.
        $errors = array_merge($errors, $this->validateSubject(rule: $rule, prefix: $prefix));

        // Validate recipients.
        $errors = array_merge($errors, $this->validateRecipients(rule: $rule, prefix: $prefix));

        // Validate channels.
        $errors = array_merge($errors, $this->validateChannels(rule: $rule, prefix: $prefix));

        // Validate organisation gate (optional).
        $errors = array_merge($errors, $this->validateOrganisationGate(rule: $rule, prefix: $prefix));

        return $errors;
    }//end validateRule()

    /**
     * Validate the subject field: either a plain string or a locale map.
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param string               $prefix Error message prefix.
     *
     * @return list<string>
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-11
     */
    private function validateSubject(array $rule, string $prefix): array
    {
        if (isset($rule['subject']) === false) {
            return ["$prefix: missing required field 'subject' (notification-no-subject)"];
        }

        $subject = $rule['subject'];

        // Plain string is always valid.
        if (is_string(value: $subject) === true) {
            return [];
        }

        if (is_array(value: $subject) === false) {
            return ["$prefix: subject must be a string or locale map"];
        }

        $errors = [];

        // Validate locale map — each key must be a locale code, each value a non-empty string.
        foreach ($subject as $locale => $template) {
            if ($locale === 'defaultLocale') {
                continue;
            }

            if (is_string(value: $template) === false || $template === '') {
                $errors[] = "$prefix: subject locale '$locale' must be a non-empty string (notification-bad-subject-locale)";
            }
        }

        // If empty map (or map with only defaultLocale key).
        $localeKeys = array_filter(array: array_keys(array: $subject), callback: static fn($k) => $k !== 'defaultLocale');
        if (empty($localeKeys) === true) {
            return ["$prefix: subject locale map must have at least one locale entry (notification-no-subject)"];
        }

        // Validate defaultLocale points to an existing entry.
        if (isset($subject['defaultLocale']) === true) {
            $dl = $subject['defaultLocale'];
            if (isset($subject[$dl]) === false) {
                $errors[] = "$prefix: subject defaultLocale '$dl' references a locale not in the map (notification-bad-default-locale)";
            }
        }

        return $errors;
    }//end validateSubject()

    /**
     * Validate the recipients array.
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param string               $prefix Error message prefix.
     *
     * @return list<string>
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-2
     */
    private function validateRecipients(array $rule, string $prefix): array
    {
        if (isset($rule['recipients']) === false) {
            return ["$prefix: missing required field 'recipients'"];
        }

        if (is_array(value: $rule['recipients']) === false || empty($rule['recipients']) === true) {
            return ["$prefix: 'recipients' must be a non-empty array"];
        }

        $errors = [];
        foreach ($rule['recipients'] as $ri => $recipient) {
            if (is_array(value: $recipient) === false) {
                $errors[] = "$prefix recipients[$ri]: must be an object";
                continue;
            }

            if (isset($recipient['kind']) === false) {
                $errors[] = "$prefix recipients[$ri]: missing 'kind'";
            } else if (in_array(needle: $recipient['kind'], haystack: self::VALID_RECIPIENT_KINDS, strict: true) === false) {
                $errors[] = "$prefix recipients[$ri]: unknown kind '{$recipient['kind']}'";
            }
        }

        return $errors;
    }//end validateRecipients()

    /**
     * Validate the channels array.
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param string               $prefix Error message prefix.
     *
     * @return list<string>
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-2
     */
    private function validateChannels(array $rule, string $prefix): array
    {
        if (isset($rule['channels']) === false) {
            return ["$prefix: missing required field 'channels'"];
        }

        if (is_array(value: $rule['channels']) === false || empty($rule['channels']) === true) {
            return ["$prefix: 'channels' must be a non-empty array"];
        }

        $errors = [];
        foreach ($rule['channels'] as $channel) {
            if (in_array(needle: $channel, haystack: self::VALID_CHANNELS, strict: true) === false) {
                $errors[] = "$prefix: unknown channel '$channel'; valid: ".implode(separator: ', ', array: self::VALID_CHANNELS);
            }
        }

        return $errors;
    }//end validateChannels()

    /**
     * Validate the optional organisation gate field.
     *
     * Accepts:
     * - A non-empty string (single org uuid or slug)
     * - A non-empty array of non-empty strings
     *
     * @param array<string, mixed> $rule   Rule spec.
     * @param string               $prefix Error message prefix.
     *
     * @return list<string>
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-9b
     */
    public function validateOrganisationGate(array $rule, string $prefix=''): array
    {
        if (isset($rule['organisation']) === false) {
            return [];
        }

        $org = $rule['organisation'];

        if (is_string(value: $org) === true) {
            if ($org === '') {
                return ["$prefix: organisation must not be an empty string (notification-bad-organisation)"];
            }

            return [];
        }

        if (is_array(value: $org) === false) {
            return ["$prefix: organisation must be a string or array of strings (notification-bad-organisation)"];
        }

        if (empty($org) === true) {
            return ["$prefix: organisation array must not be empty (notification-bad-organisation)"];
        }

        foreach ($org as $entry) {
            if (is_string(value: $entry) === false || $entry === '') {
                return ["$prefix: each organisation entry must be a non-empty string (notification-bad-organisation)"];
            }
        }

        return [];
    }//end validateOrganisationGate()
}//end class
