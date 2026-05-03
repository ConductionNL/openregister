<?php

/**
 * OpenRegister NotificationAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-notifications` annotation.
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

    private const VALID_TRIGGERS = ['created', 'updated', 'transition', 'scheduled', 'threshold'];

    private const VALID_RECIPIENT_KINDS = ['users', 'field', 'groups', 'relation', 'object-acl', 'expression'];

    private const VALID_CHANNELS = ['nc-notification', 'email', 'activity', 'webhook', 'talk'];

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
        $propKeys   = is_array($properties) === true ? array_keys($properties) : [];

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
            $triggerType = is_array($trigger) === true ? (string) ($trigger['type'] ?? '') : '';
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
                $intervalSec = is_array($trigger) === true ? ($trigger['intervalSec'] ?? null) : null;
                if (is_int($intervalSec) === false || $intervalSec < 60) {
                    $errors[] = [
                        'code'    => 'notification-scheduled-bad-interval',
                        'message' => sprintf(
                            'Notification "%s" trigger.type=scheduled requires trigger.intervalSec (integer >= 60).',
                            $name
                        ),
                    ];
                }
            }

            if ($triggerType === 'threshold') {
                $aggregation = is_array($trigger) === true ? (string) ($trigger['aggregation'] ?? '') : '';
                $op          = is_array($trigger) === true ? (string) ($trigger['op'] ?? '') : '';
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
}//end class
