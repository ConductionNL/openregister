<?php

/**
 * OpenRegister NotificationAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-notifications` annotation.
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

/**
 * Validates the shape of the `x-openregister-notifications` annotation.
 *
 * Each notification entry has:
 * - `trigger`: { type: created|updated|transition, action?: string }
 * - `recipients`: [{ kind: users|field, users?: [...] | field?: "name" }]
 * - `channels`: ["nc-notification"]   (v1)
 * - `subject`: string template OR per-locale map
 *   ({nl: "...", en: "...", defaultLocale?: "nl"}; supports {{field}}
 *   interpolation; recipient locale via `core.lang` user preference)
 * - optional `message`: string
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class NotificationAnnotationValidator
{

    private const VALID_TRIGGERS = ['created', 'updated', 'transition', 'scheduled', 'threshold', 'calculatedChange'];

    private const VALID_RECIPIENT_KINDS = ['users', 'field', 'groups', 'relation', 'object-acl', 'expression'];

    private const VALID_CHANNELS = ['nc-notification', 'email', 'activity', 'webhook', 'talk', 'web-push'];

    /**
     * Valid action `target.kind` values (foundation contract / ADR-031 dialect).
     *
     * @var array<int, string>
     */
    private const VALID_ACTION_TARGET_KINDS = ['object-detail', 'route', 'url'];

    /**
     * Hard cap on declared action buttons — the Web Notification API renders
     * at most two action buttons on the desktop OS popup.
     *
     * @var int
     */
    private const MAX_ACTIONS = 2;

    /**
     * Validate the `x-openregister-notifications` annotation.
     *
     * @param array<string, mixed> $schema Full schema (must include `properties`).
     *
     * @return array<int, array{code: string, message: string}>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    public function validate(array $schema): array
    {
        if (isset($schema['x-openregister-notifications']) === false) {
            return [];
        }

        $notifications = $schema['x-openregister-notifications'];
        if (is_array($notifications) === false || count($notifications) === 0) {
            return [
                [
                    'code'    => 'notifications-empty',
                    'message' => 'x-openregister-notifications must declare at least one notification.',
                ],
            ];
        }

        $properties = ($schema['properties'] ?? []);
        $propKeys   = [];
        if (is_array($properties) === true) {
            $propKeys = array_keys($properties);
        }

        $errors = [];
        foreach ($notifications as $name => $spec) {
            if (is_string($name) === false || $name === '') {
                $errors[] = [
                    'code'    => 'notification-bad-name',
                    'message' => 'Notification names must be non-empty strings.',
                ];
                continue;
            }

            if (is_array($spec) === false) {
                $errors[] = [
                    'code'    => 'notification-malformed',
                    'message' => sprintf('Notification "%s" must be an object.', $name),
                ];
                continue;
            }

            $trigger     = ($spec['trigger'] ?? null);
            $triggerType = '';
            if (is_array($trigger) === true) {
                $triggerType = (string) ($trigger['type'] ?? '');
            }

            if (in_array($triggerType, self::VALID_TRIGGERS, true) === false) {
                $errors[] = [
                    'code'    => 'notification-bad-trigger',
                    'message' => sprintf(
                        'Notification "%s" trigger.type must be one of [%s].',
                        $name,
                        implode(', ', self::VALID_TRIGGERS)
                    ),
                ];
            }

            if ($triggerType === 'scheduled') {
                $intervalSec = null;
                if (is_array($trigger) === true) {
                    $intervalSec = ($trigger['intervalSec'] ?? null);
                }

                if (is_int($intervalSec) === false || $intervalSec < 60) {
                    $errors[] = [
                        'code'    => 'notification-scheduled-bad-interval',
                        'message' => sprintf(
                            'Notification "%s" trigger.type=scheduled requires trigger.intervalSec (integer >= 60).',
                            $name
                        ),
                    ];
                }

                // Validate filter operator grammar (Phase 2 — save-time grammar validation).
                // Scalar entries always pass; operator objects must declare a known operator,
                // a present `value`, and an ISO-8601 DateInterval-parseable duration for
                // withinNext / olderThan. See notification-engine-scheduled-conditions.
                if (is_array($trigger) === true && isset($trigger['filter']) === true) {
                    $filter = $trigger['filter'];
                    if (is_array($filter) === false) {
                        $errors[] = [
                            'code'    => 'notification-scheduled-bad-filter',
                            'ruleKey' => $name,
                            'field'   => 'trigger.filter',
                            'value'   => $filter,
                            'message' => sprintf(
                                'Notification "%s" trigger.filter must be an object/map.',
                                $name
                            ),
                        ];
                    } else {
                        foreach ($filter as $fieldKey => $filterSpec) {
                            $entryErrors = $this->validateScheduledFilterEntry(
                                ruleKey: $name,
                                field: (string) $fieldKey,
                                spec: $filterSpec
                            );
                            foreach ($entryErrors as $entryError) {
                                $errors[] = $entryError;
                            }
                        }//end foreach
                    }//end if
                }//end if

                if (is_array($trigger) === true && isset($trigger['dedupeFields']) === true) {
                    $dedupeFields = $trigger['dedupeFields'];
                    if (is_array($dedupeFields) === false || $dedupeFields === []) {
                        $errors[] = [
                            'code'    => 'notification-scheduled-bad-dedupe-fields',
                            'ruleKey' => $name,
                            'field'   => 'trigger.dedupeFields',
                            'value'   => $dedupeFields,
                            'message' => sprintf(
                                'Notification "%s" trigger.dedupeFields must be a non-empty array of strings.',
                                $name
                            ),
                        ];
                    } else {
                        foreach ($dedupeFields as $idx => $field) {
                            if (is_string($field) === false || $field === '') {
                                $errors[] = [
                                    'code'    => 'notification-scheduled-bad-dedupe-fields',
                                    'ruleKey' => $name,
                                    'field'   => sprintf('trigger.dedupeFields[%d]', (int) $idx),
                                    'value'   => $field,
                                    'message' => sprintf(
                                        'Notification "%s" trigger.dedupeFields entries must be non-empty strings.',
                                        $name
                                    ),
                                ];
                                break;
                            }
                        }//end foreach
                    }//end if
                }//end if
            }//end if

            if ($triggerType === 'threshold') {
                $aggregation = '';
                $op          = '';
                if (is_array($trigger) === true) {
                    $aggregation = (string) ($trigger['aggregation'] ?? '');
                    $op          = (string) ($trigger['op'] ?? '');
                }

                if ($aggregation === '') {
                    $errors[] = [
                        'code'    => 'notification-threshold-no-aggregation',
                        'message' => sprintf(
                            'Notification "%s" trigger.type=threshold requires trigger.aggregation referencing a declared aggregation.',
                            $name
                        ),
                    ];
                }

                if (in_array($op, ['gt', 'gte', 'lt', 'lte', 'eq', 'ne'], true) === false) {
                    $errors[] = [
                        'code'    => 'notification-threshold-bad-op',
                        'message' => sprintf(
                            'Notification "%s" trigger.type=threshold trigger.op must be one of [gt, gte, lt, lte, eq, ne]; got "%s".',
                            $name,
                            $op
                        ),
                    ];
                }

                if (is_array($trigger) === true && array_key_exists('value', $trigger) === false) {
                    $errors[] = [
                        'code'    => 'notification-threshold-no-value',
                        'message' => sprintf(
                            'Notification "%s" trigger.type=threshold requires trigger.value.',
                            $name
                        ),
                    ];
                }
            }//end if

            if ($triggerType === 'calculatedChange') {
                $field = null;
                if (is_array($trigger) === true) {
                    $field = ($trigger['field'] ?? null);
                }

                if (is_string($field) === false || $field === '') {
                    $errors[] = [
                        'code'    => 'notification-calculated-change-no-field',
                        'message' => sprintf(
                            'Notification "%s" trigger.type=calculatedChange requires trigger.field (non-empty string).',
                            $name
                        ),
                    ];
                }

                $validOps = ['lt', 'lte', 'gt', 'gte', 'eq', 'ne'];

                foreach (['condition', 'previously'] as $clauseKey) {
                    $clause = null;
                    if (is_array($trigger) === true) {
                        $clause = ($trigger[$clauseKey] ?? null);
                    }

                    if ($clause === null) {
                        continue;
                    }

                    if (is_array($clause) === false || count($clause) === 0) {
                        $errors[] = [
                            'code'    => 'notification-calculated-change-bad-clause',
                            'message' => sprintf(
                                'Notification "%s" trigger.%s must be a non-empty operator map.',
                                $name,
                                $clauseKey
                            ),
                        ];
                        continue;
                    }

                    foreach (array_keys($clause) as $op) {
                        if (in_array((string) $op, $validOps, true) === false) {
                            $errors[] = [
                                'code'    => 'notification-calculated-change-bad-op',
                                'message' => sprintf(
                                    'Notification "%s" trigger.%s operator "%s" must be one of [%s].',
                                    $name,
                                    $clauseKey,
                                    (string) $op,
                                    implode(', ', $validOps)
                                ),
                            ];
                        }
                    }
                }//end foreach
            }//end if

            $channels = ($spec['channels'] ?? []);
            if (is_array($channels) === false || count($channels) === 0) {
                $errors[] = [
                    'code'    => 'notification-channels-empty',
                    'message' => sprintf(
                        'Notification "%s" must declare at least one channel.',
                        $name
                    ),
                ];
                $channels = [];
            }

            foreach ($channels as $channel) {
                if (in_array((string) $channel, self::VALID_CHANNELS, true) === false) {
                    $errors[] = [
                        'code'    => 'notification-bad-channel',
                        'message' => sprintf(
                            'Notification "%s" channel "%s" is not in [%s].',
                            $name,
                            (string) $channel,
                            implode(', ', self::VALID_CHANNELS)
                        ),
                    ];
                }
            }

            // Optional `originApp` (foundation contract / ADR-031): identifies
            // the leaf app that owns the rule. Drives the notification
            // icon/badge and the deeplink base. When present it MUST be a
            // non-empty string; absence is valid (defaults to the
            // register-owning app at dispatch).
            if (array_key_exists('originApp', $spec) === true) {
                $originApp = $spec['originApp'];
                if (is_string($originApp) === false || $originApp === '') {
                    $errors[] = [
                        'code'    => 'notification-bad-origin-app',
                        'message' => sprintf(
                            'Notification "%s" originApp must be a non-empty string app id.',
                            $name
                        ),
                    ];
                }
            }

            // Optional `actions[]` (foundation contract / ADR-031): rich
            // action buttons rendered in the OS notification. Hard cap of 2
            // (Web Notification API desktop limit). Each action declares an
            // i18n `label` map, an optional `primary` bool, and a `target`
            // of kind object-detail | route | url.
            if (array_key_exists('actions', $spec) === true) {
                foreach ($this->validateActions(actions: $spec['actions'], name: $name) as $actionError) {
                    $errors[] = $actionError;
                }
            }

            // `subject` accepts either a single template string OR a
            // per-locale map ({nl: "...", en: "..."} optionally prefixed
            // with `defaultLocale: <code>`). The dispatcher resolves
            // the active recipient's locale at delivery time; the
            // broadcast channels (webhook/talk) use the default locale
            // fallback chain.
            $subject       = ($spec['subject'] ?? null);
            $subjectString = is_string($subject) === true;
            $subjectArray  = is_array($subject) === true;
            if ($subjectString === false && $subjectArray === false) {
                $errors[] = [
                    'code'    => 'notification-no-subject',
                    'message' => sprintf(
                        'Notification "%s" requires a subject string or per-locale map.',
                        $name
                    ),
                ];
            }

            if ($subjectString === true && $subject === '') {
                $errors[] = [
                    'code'    => 'notification-no-subject',
                    'message' => sprintf(
                        'Notification "%s" requires a non-empty subject string.',
                        $name
                    ),
                ];
            }

            if ($subjectArray === true) {
                $localeKeys = array_filter(
                    array_keys($subject),
                    static fn ($key): bool => $key !== 'defaultLocale' && is_string($key) === true
                );
                if (count($localeKeys) === 0) {
                    $errors[] = [
                        'code'    => 'notification-no-subject',
                        'message' => sprintf(
                            'Notification "%s" subject map must declare at least one locale (e.g. nl, en).',
                            $name
                        ),
                    ];
                }

                foreach ($localeKeys as $localeKey) {
                    if (is_string($subject[$localeKey]) === false || $subject[$localeKey] === '') {
                        $errors[] = [
                            'code'    => 'notification-bad-subject-locale',
                            'message' => sprintf(
                                'Notification "%s" subject for locale "%s" must be a non-empty string.',
                                $name,
                                $localeKey
                            ),
                        ];
                    }
                }

                if (isset($subject['defaultLocale']) === true) {
                    $defaultLocale     = $subject['defaultLocale'];
                    $defaultLocaleBad  = is_string($defaultLocale) === false;
                    $defaultLocaleBad |= isset($subject[$defaultLocale]) === false;
                    if ((bool) $defaultLocaleBad === true) {
                        $errors[] = [
                            'code'    => 'notification-bad-default-locale',
                            'message' => sprintf(
                                'Notification "%s" defaultLocale "%s" is not declared in the subject map.',
                                $name,
                                (string) $defaultLocale
                            ),
                        ];
                    }
                }
            }//end if

            // When the `webhook` channel is declared, the spec MUST include a `webhook.url` value.
            if (is_array($channels) === true && in_array('webhook', $channels, true) === true) {
                $hook    = ($spec['webhook'] ?? null);
                $hookBad = is_array($hook) === false;
                if ($hookBad === false) {
                    $hookBad = empty($hook['url']) === true
                        || filter_var($hook['url'], FILTER_VALIDATE_URL) === false;
                }

                if ($hookBad === true) {
                    $errors[] = [
                        'code'    => 'notification-webhook-no-url',
                        'message' => sprintf(
                            'Notification "%s" declares the `webhook` channel but webhook.url is missing or malformed.',
                            $name
                        ),
                    ];
                }
            }

            // When the `talk` channel is declared, the spec MUST include a `talk.token`.
            if (is_array($channels) === true && in_array('talk', $channels, true) === true) {
                $talk    = ($spec['talk'] ?? null);
                $talkBad = is_array($talk) === false;
                if ($talkBad === false) {
                    $talkBad = empty($talk['token']) === true || is_string($talk['token']) === false;
                }

                if ($talkBad === true) {
                    $errors[] = [
                        'code'    => 'notification-talk-no-token',
                        'message' => sprintf(
                            'Notification "%s" declares the `talk` channel but talk.token is missing or not a string.',
                            $name
                        ),
                    ];
                }
            }

            // Optional `organisation` gate — the dispatcher skips this
            // notification unless the saved object's organisation
            // matches. Accepts a single string (UUID or slug) or an
            // array of strings (any-of). Closes the spec's
            // "Notifications MUST be scoped to organisations" item by
            // letting schema authors pin a rule explicitly without
            // writing a custom expression resolver.
            if (array_key_exists('organisation', $spec) === true) {
                $orgError = $this->validateOrganisationGate(org: $spec['organisation'], name: $name);
                if ($orgError !== null) {
                    $errors[] = $orgError;
                }
            }

            $recipients = ($spec['recipients'] ?? []);
            if (is_array($recipients) === false || count($recipients) === 0) {
                $errors[] = [
                    'code'    => 'notification-no-recipients',
                    'message' => sprintf(
                        'Notification "%s" must declare at least one recipient.',
                        $name
                    ),
                ];
                continue;
            }

            foreach ($recipients as $i => $recipient) {
                if (is_array($recipient) === false) {
                    $errors[] = [
                        'code'    => 'notification-recipient-malformed',
                        'message' => sprintf(
                            'Notification "%s" recipient[%d] must be an object.',
                            $name,
                            $i
                        ),
                    ];
                    continue;
                }

                $kind = (string) ($recipient['kind'] ?? '');
                if (in_array($kind, self::VALID_RECIPIENT_KINDS, true) === false) {
                    $errors[] = [
                        'code'    => 'notification-bad-recipient-kind',
                        'message' => sprintf(
                            'Notification "%s" recipient[%d] kind "%s" not in [%s].',
                            $name,
                            $i,
                            $kind,
                            implode(', ', self::VALID_RECIPIENT_KINDS)
                        ),
                    ];
                    continue;
                }

                if ($kind === 'field') {
                    $field = (string) ($recipient['field'] ?? '');
                    if ($field === '' || in_array($field, $propKeys, true) === false) {
                        $errors[] = [
                            'code'    => 'notification-recipient-field-unknown',
                            'message' => sprintf(
                                'Notification "%s" recipient[%d] field "%s" is not declared on the schema.',
                                $name,
                                $i,
                                $field
                            ),
                        ];
                    }
                }

                if ($kind === 'object-acl') {
                    $perm = (string) ($recipient['permission'] ?? '');
                    if (in_array($perm, ['read', 'manage'], true) === false) {
                        $errors[] = [
                            'code'    => 'notification-recipient-acl-bad-permission',
                            'message' => sprintf(
                                'Notification "%s" recipient[%d] kind=object-acl requires permission in [read, manage]; got "%s".',
                                $name,
                                $i,
                                $perm
                            ),
                        ];
                    }
                }

                if ($kind === 'expression') {
                    $resolver = (string) ($recipient['resolver'] ?? '');
                    if ($resolver === '') {
                        $errors[] = [
                            'code'    => 'notification-recipient-expression-no-resolver',
                            'message' => sprintf(
                                'Notification "%s" recipient[%d] kind=expression requires a resolver string (DI tag or FQCN).',
                                $name,
                                $i
                            ),
                        ];
                    }
                }
            }//end foreach
        }//end foreach

        return $errors;
    }//end validate()

    /**
     * Validate the optional `actions[]` array (foundation contract / ADR-031).
     *
     * Rules:
     *  - `actions` MUST be an array; more than 2 entries → notification-too-many-actions.
     *  - each action's `label` MUST be a per-locale map with at least one
     *    non-empty locale value → otherwise notification-action-bad-label.
     *  - each action's `target.kind` MUST be one of object-detail | route | url
     *    → otherwise notification-action-bad-target.
     *
     * @param mixed  $actions Raw value of the `actions` key.
     * @param string $name    Notification name (for diagnostics).
     *
     * @return array<int, array{code: string, message: string}>
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
     */
    private function validateActions(mixed $actions, string $name): array
    {
        $errors = [];

        if (is_array($actions) === false) {
            return [
                [
                    'code'    => 'notification-action-bad-target',
                    'message' => sprintf('Notification "%s" actions must be an array.', $name),
                ],
            ];
        }

        if (count($actions) > self::MAX_ACTIONS) {
            $errors[] = [
                'code'    => 'notification-too-many-actions',
                'message' => sprintf(
                    'Notification "%s" declares %d actions; the Web Notification API renders at most %d.',
                    $name,
                    count($actions),
                    self::MAX_ACTIONS
                ),
            ];
        }

        foreach ($actions as $idx => $action) {
            if (is_array($action) === false) {
                $errors[] = [
                    'code'    => 'notification-action-bad-target',
                    'message' => sprintf('Notification "%s" action[%s] must be an object.', $name, (string) $idx),
                ];
                continue;
            }

            // Label MUST be a per-locale map with at least one non-empty string value.
            $label   = ($action['label'] ?? null);
            $labelOk = false;
            if (is_array($label) === true && count($label) > 0) {
                foreach ($label as $localeValue) {
                    if (is_string($localeValue) === true && $localeValue !== '') {
                        $labelOk = true;
                        break;
                    }
                }
            }

            if ($labelOk === false) {
                $errors[] = [
                    'code'    => 'notification-action-bad-label',
                    'message' => sprintf(
                        'Notification "%s" action[%s] label must be a per-locale map with at least one non-empty value.',
                        $name,
                        (string) $idx
                    ),
                ];
            }

            // `primary` (optional) MUST be a boolean when present.
            if (array_key_exists('primary', $action) === true && is_bool($action['primary']) === false) {
                $errors[] = [
                    'code'    => 'notification-action-bad-target',
                    'message' => sprintf(
                        'Notification "%s" action[%s] primary must be a boolean.',
                        $name,
                        (string) $idx
                    ),
                ];
            }

            // Target MUST be an object with a recognised kind.
            $target     = ($action['target'] ?? null);
            $targetKind = '';
            if (is_array($target) === true) {
                $targetKind = (string) ($target['kind'] ?? '');
            }

            if (in_array($targetKind, self::VALID_ACTION_TARGET_KINDS, true) === false) {
                $errors[] = [
                    'code'    => 'notification-action-bad-target',
                    'message' => sprintf(
                        'Notification "%s" action[%s] target.kind "%s" is not in [%s].',
                        $name,
                        (string) $idx,
                        $targetKind,
                        implode(', ', self::VALID_ACTION_TARGET_KINDS)
                    ),
                ];
            }
        }//end foreach

        return $errors;
    }//end validateActions()

    /**
     * Validate the optional `organisation` rule-level gate.
     *
     * Accepts either a single non-empty string (one tenant) or an
     * array of non-empty strings (any-of). Returns an error envelope
     * for malformed shapes and null when the gate is well-formed.
     *
     * @param mixed  $org  Raw value of the `organisation` key.
     * @param string $name The notification name (for error messages).
     *
     * @return array{code: string, message: string}|null
     */
    private function validateOrganisationGate(mixed $org, string $name): ?array
    {
        $code = 'notification-bad-organisation';

        if (is_string($org) === true) {
            if ($org === '') {
                return [
                    'code'    => $code,
                    'message' => sprintf(
                        'Notification "%s" organisation must be a non-empty string.',
                        $name
                    ),
                ];
            }

            return null;
        }

        if (is_array($org) === true) {
            if (count($org) === 0) {
                return [
                    'code'    => $code,
                    'message' => sprintf(
                        'Notification "%s" organisation array must declare at least one entry.',
                        $name
                    ),
                ];
            }

            foreach ($org as $candidate) {
                if (is_string($candidate) === false || $candidate === '') {
                    return [
                        'code'    => $code,
                        'message' => sprintf(
                            'Notification "%s" organisation array entries must be non-empty strings.',
                            $name
                        ),
                    ];
                }
            }//end foreach

            return null;
        }//end if

        return [
            'code'    => $code,
            'message' => sprintf(
                'Notification "%s" organisation must be a string or an array of strings.',
                $name
            ),
        ];
    }//end validateOrganisationGate()

    /**
     * Validate a single scheduled-trigger filter entry.
     *
     * Scalar entries always pass (legacy v1 byte-for-byte equality).
     * Operator-object entries must declare:
     *   - a recognised operator (equals | notEquals | withinNext | olderThan);
     *   - a `value` key (even if null) — for equals/notEquals;
     *   - an ISO-8601 DateInterval-parseable string `value` for withinNext / olderThan.
     *
     * @param string $ruleKey The notification rule key for diagnostics.
     * @param string $field   The filter field name being inspected.
     * @param mixed  $spec    The raw filter entry value (scalar or operator object).
     *
     * @return array<int, array{code: string, ruleKey: string, field: string, value: mixed, message: string}>
     */
    private function validateScheduledFilterEntry(string $ruleKey, string $field, $spec): array
    {
        // Scalar shortcut: always accepted (legacy v1 strict equality).
        if (is_array($spec) === false || array_key_exists('operator', $spec) === false) {
            return [];
        }

        $validOperators = ['equals', 'notEquals', 'withinNext', 'olderThan'];
        $operator       = (string) ($spec['operator'] ?? '');

        if (in_array($operator, $validOperators, true) === false) {
            return [
                [
                    'code'    => 'notification-scheduled-bad-filter-operator',
                    'ruleKey' => $ruleKey,
                    'field'   => sprintf('trigger.filter.%s.operator', $field),
                    'value'   => $operator,
                    'message' => sprintf(
                        'Notification "%s" trigger.filter.%s.operator must be one of [%s]; got "%s".',
                        $ruleKey,
                        $field,
                        implode(', ', $validOperators),
                        $operator
                    ),
                ],
            ];
        }

        if (array_key_exists('value', $spec) === false) {
            return [
                [
                    'code'    => 'notification-scheduled-bad-filter-missing-value',
                    'ruleKey' => $ruleKey,
                    'field'   => sprintf('trigger.filter.%s.value', $field),
                    'value'   => null,
                    'message' => sprintf(
                        'Notification "%s" trigger.filter.%s requires a "value" key.',
                        $ruleKey,
                        $field
                    ),
                ],
            ];
        }

        if ($operator === 'withinNext' || $operator === 'olderThan') {
            $duration = $spec['value'];
            if (is_string($duration) === false || $duration === '') {
                return [
                    [
                        'code'    => 'notification-scheduled-bad-filter-duration',
                        'ruleKey' => $ruleKey,
                        'field'   => sprintf('trigger.filter.%s.value', $field),
                        'value'   => $duration,
                        'message' => sprintf(
                            'Notification "%s" trigger.filter.%s.value must be an ISO-8601 DateInterval string for "%s".',
                            $ruleKey,
                            $field,
                            $operator
                        ),
                    ],
                ];
            }

            try {
                new \DateInterval($duration);
            } catch (\Exception $e) {
                return [
                    [
                        'code'    => 'notification-scheduled-bad-filter-duration',
                        'ruleKey' => $ruleKey,
                        'field'   => sprintf('trigger.filter.%s.value', $field),
                        'value'   => $duration,
                        'message' => sprintf(
                            'Notification "%s" trigger.filter.%s.value "%s" is not a valid ISO-8601 DateInterval for "%s".',
                            $ruleKey,
                            $field,
                            $duration,
                            $operator
                        ),
                    ],
                ];
            }//end try
        }//end if

        return [];
    }//end validateScheduledFilterEntry()
}//end class
