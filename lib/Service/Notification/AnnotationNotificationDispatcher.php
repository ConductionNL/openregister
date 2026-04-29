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
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Reads notification annotations and dispatches matching ones.
 */
class AnnotationNotificationDispatcher
{

    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IMailer $mailer,
        private readonly IActivityManager $activityManager,
        private readonly IClientService $httpClient
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

            $recipients = $this->resolveRecipients(($spec['recipients'] ?? []), $data, $object, $context);
            if (count($recipients) === 0) {
                continue;
            }

            $subject  = (string) ($spec['subject'] ?? (string) $name);
            $rendered = $this->interpolate($subject, $data, $context);
            $channels = (array) ($spec['channels'] ?? ['nc-notification']);

            // Webhook is fired once per dispatch, not once per recipient,
            // and includes the recipient list in the payload.
            if (in_array('webhook', $channels, true) === true) {
                $this->emitWebhook(
                    spec: $spec,
                    object: $object,
                    notificationName: (string) $name,
                    subject: $rendered,
                    recipients: $recipients,
                    context: $context
                );
            }

            // Talk channel is fired once per dispatch (chat message goes
            // to the configured Talk room, recipients aren't @-mentioned).
            if (in_array('talk', $channels, true) === true) {
                $this->emitTalk(spec: $spec, message: $rendered);
            }

            foreach ($recipients as $uid) {
                if (in_array('nc-notification', $channels, true) === true) {
                    $this->emitNotification(
                        uid: $uid,
                        objectId: (string) ($object->getUuid() ?? ''),
                        name: (string) $name,
                        subject: $rendered,
                        parameters: $context
                    );
                }
                if (in_array('email', $channels, true) === true) {
                    $this->emitEmail(
                        uid: $uid,
                        subject: $rendered,
                        body: $rendered
                    );
                }
                if (in_array('activity', $channels, true) === true) {
                    $this->emitActivity(
                        uid: $uid,
                        objectId: (string) ($object->getUuid() ?? ''),
                        name: (string) $name,
                        subject: $rendered
                    );
                }
            }
        }
    }//end dispatch()

    /**
     * POST a JSON payload to the configured webhook URL.
     *
     * @param array<string, mixed> $spec       The notification spec block.
     * @param ObjectEntity         $object     The object the event happened on.
     * @param string               $notificationName Annotation name.
     * @param string               $subject    Interpolated subject.
     * @param array<int, string>   $recipients Resolved recipient uids.
     * @param array<string, mixed> $context    Trigger context (action, from, to).
     */
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
            // host or fall back to the loopback.
            $base = (string) \OC::$server->get(\OCP\IConfig::class)->getSystemValue('overwrite.cli.url', 'http://localhost');
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
        }
    }//end emitTalk()

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
            'timestamp'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
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
    private function resolveRecipients(array $recipientsSpec, array $data, ?ObjectEntity $object = null, array $context = []): array
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
                continue;
            }
            if ($kind === 'relation') {
                // Resolve a typed relation (declared via x-openregister-relations).
                // Reads $data[<relationFieldName>] which by convention holds
                // either a string UID, an array of string UIDs, or an
                // array of objects each carrying a userId field.
                $relName = (string) ($r['relation'] ?? '');
                if ($relName === '') {
                    continue;
                }
                $value = ($data[$relName] ?? null);
                foreach ($this->extractUidsFromRelation($value) as $uid) {
                    $uids[] = $uid;
                }
                continue;
            }
            if ($kind === 'object-acl') {
                if ($object !== null) {
                    $perm = (string) ($r['permission'] ?? 'read');
                    foreach ($this->resolveObjectAclRecipients($object, $perm) as $uid) {
                        $uids[] = $uid;
                    }
                }
                continue;
            }
            if ($kind === 'expression') {
                if ($object !== null) {
                    $resolverTag = (string) ($r['resolver'] ?? '');
                    foreach ($this->resolveExpressionRecipients($resolverTag, $object, $context) as $uid) {
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
            }
        }
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
     * @return array<int, string>
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
        // Read permission: also include groups via getGroups().
        try {
            $groupsRaw = method_exists($object, 'getGroups') === true ? $object->getGroups() : null;
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
        }
        return $uids;
    }//end resolveObjectAclRecipients()

    /**
     * Resolve recipients via a DI-tagged RecipientResolverInterface.
     *
     * Looks up the resolver via \OC::$server (server container) so apps
     * can register their resolver class by FQCN and have NC autowire
     * its dependencies. Skips silently when the resolver doesn't exist
     * or doesn't implement the interface.
     *
     * @param array<string, mixed> $context
     * @return array<int, string>
     */
    private function resolveExpressionRecipients(string $resolverTag, ObjectEntity $object, array $context): array
    {
        if ($resolverTag === '') {
            return [];
        }
        try {
            $resolver = \OC::$server->get($resolverTag);
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
     * Extract candidate UIDs from a relation value. The relation value
     * can be:
     *   - a string (treat as UID directly)
     *   - an array of strings (each treated as a UID)
     *   - an array of objects with a `userId` or `uid` field
     *   - any nested combination of the above
     *
     * @return array<int, string>
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

    /**
     * Send a transactional email to a Nextcloud user.
     *
     * Resolves the user's email via IUserManager and short-circuits if
     * SMTP isn't configured (mailer->validateMailFrom would fail) or
     * the user has no email on file.
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
        }
    }//end emitEmail()

    /**
     * Publish an entry to the Nextcloud Activity stream.
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
