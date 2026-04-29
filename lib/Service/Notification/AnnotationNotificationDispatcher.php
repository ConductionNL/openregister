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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Reads notification annotations and dispatches matching ones.
 */
final class AnnotationNotificationDispatcher
{

    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger
    ) {}//end __construct()

    /**
     * Fire any notifications declared on the schema whose trigger matches.
     *
     * @param ObjectEntity         $object  The object the event happened on.
     * @param string               $trigger 'created' | 'updated' | 'transition'.
     * @param array<string, mixed> $context Trigger-specific extras (e.g. `action`, `from`, `to`).
     */
    public function dispatch(ObjectEntity $object, string $trigger, array $context = []): void
    {
        $schema = $this->loadSchema($object);
        if ($schema === null) {
            return;
        }

        $notifications = $this->getAnnotation($schema);
        if ($notifications === null) {
            return;
        }

        $data = $object->getObject() ?? [];

        foreach ($notifications as $name => $spec) {
            if (is_array($spec) === false) {
                continue;
            }
            if ($this->matches($spec['trigger'] ?? [], $trigger, $context) === false) {
                continue;
            }

            $recipients = $this->resolveRecipients(($spec['recipients'] ?? []), $data);
            if (count($recipients) === 0) {
                continue;
            }

            $subject  = (string) ($spec['subject'] ?? (string) $name);
            $rendered = $this->interpolate($subject, $data, $context);

            foreach ($recipients as $uid) {
                $this->emitNotification(
                    uid: $uid,
                    objectId: (string) ($object->getUuid() ?? ''),
                    name: (string) $name,
                    subject: $rendered,
                    parameters: $context
                );
            }
        }
    }//end dispatch()

    /**
     * @param array<string, mixed> $triggerSpec
     * @param array<string, mixed> $context
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
        return true;
    }//end matches()

    /**
     * @param array<int, array<string, mixed>> $recipientsSpec
     * @param array<string, mixed>             $data
     *
     * @return array<int, string>
     */
    private function resolveRecipients(array $recipientsSpec, array $data): array
    {
        $uids = [];
        foreach ($recipientsSpec as $r) {
            if (is_array($r) === false) {
                continue;
            }
            $kind = (string) ($r['kind'] ?? '');
            if ($kind === 'users') {
                foreach ((array) ($r['users'] ?? []) as $u) {
                    if (is_string($u) === true && $u !== '') {
                        $uids[] = $u;
                    }
                }
                continue;
            }
            if ($kind === 'field') {
                $field = (string) ($r['field'] ?? '');
                $value = ($data[$field] ?? null);
                if (is_string($value) === true && $value !== '') {
                    $uids[] = $value;
                }
            }
        }
        return array_values(array_unique($uids));
    }//end resolveRecipients()

    /**
     * Replace {{prop}} tokens with values from $data, then $context.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function interpolate(string $template, array $data, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            static function (array $m) use ($data, $context): string {
                $key = $m[1];
                if (array_key_exists($key, $data) === true) {
                    return is_scalar($data[$key]) === true ? (string) $data[$key] : '';
                }
                if (array_key_exists($key, $context) === true) {
                    return is_scalar($context[$key]) === true ? (string) $context[$key] : '';
                }
                return '';
            },
            $template
        ) ?? $template;
    }//end interpolate()

    /**
     * @param array<string, mixed> $parameters
     */
    private function emitNotification(string $uid, string $objectId, string $name, string $subject, array $parameters): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('openregister')
                ->setUser($uid)
                ->setDateTime(new \DateTime())
                ->setObject('object', $objectId !== '' ? $objectId : $name)
                ->setSubject($name, array_merge($parameters, ['_text' => $subject]));
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Notification "%s" to "%s" failed: %s', $name, $uid, $e->getMessage())
            );
        }
    }//end emitNotification()

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
     * @return array<string, mixed>|null
     */
    private function getAnnotation(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-notifications'] ?? null);
        return is_array($value) === true ? $value : null;
    }//end getAnnotation()

}//end class
