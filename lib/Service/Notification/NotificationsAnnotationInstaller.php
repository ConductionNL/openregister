<?php

/**
 * OpenRegister NotificationsAnnotationInstaller
 *
 * Runs at schema-save time. For every notification declared in
 * x-openregister-notifications with `webhook.persistent: true`,
 * upserts a Webhook entity so the existing WebhookService delivery
 * pipeline (retry, HMAC, dead-letter, multi-tenancy) handles
 * dispatch instead of the inline fire-and-forget POST.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Installer service. Subscribes to SchemaCreated/UpdatedEvent and
 * synchronises Webhook entities for declared persistent webhooks.
 *
 * @template-implements IEventListener<SchemaCreatedEvent|SchemaUpdatedEvent>
 */
class NotificationsAnnotationInstaller implements IEventListener
{
    /**
     * Constructor.
     *
     * @param WebhookMapper   $webhookMapper Mapper used to upsert Webhook rows.
     * @param LoggerInterface $logger        Logger for installer diagnostics.
     */
    public function __construct(
        private readonly WebhookMapper $webhookMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Listener entry point: dispatches schema-saved events to the installer.
     *
     * @param Event $event The event carrying the saved schema.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        $schema = null;
        if (method_exists($event, 'getSchema') === true) {
            $schema = $event->getSchema();
        } else if (method_exists($event, 'getNewSchema') === true) {
            $schema = $event->getNewSchema();
        }

        if (($schema instanceof Schema) === false) {
            return;
        }

        $this->installSchema(schema: $schema);
    }//end handle()

    /**
     * Upsert a Webhook entity for every declared persistent webhook.
     *
     * @param Schema $schema The schema whose annotations should be installed.
     *
     * @return void
     */
    public function installSchema(Schema $schema): void
    {
        $config        = ($schema->getConfiguration() ?? []);
        $notifications = ($config['x-openregister-notifications'] ?? null);
        if (is_array($notifications) === false) {
            return;
        }

        $schemaSlug = (string) ($schema->getSlug() ?? $schema->getId());

        foreach ($notifications as $name => $spec) {
            if (is_array($spec) === false) {
                continue;
            }

            $channels = (array) ($spec['channels'] ?? []);
            if (in_array('webhook', $channels, true) === false) {
                continue;
            }

            $hook = ($spec['webhook'] ?? null);
            if (is_array($hook) === false || ($hook['persistent'] ?? false) !== true) {
                continue;
            }

            $url = (string) ($hook['url'] ?? '');
            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $this->upsertWebhook(
                schemaSlug: $schemaSlug,
                notificationName: (string) $name,
                hookSpec: $hook
            );
        }//end foreach
    }//end installSchema()

    /**
     * Upsert a single Webhook row from one notification's `webhook` spec.
     *
     * @param string               $schemaSlug       Slug of the owning schema.
     * @param string               $notificationName Name of the declared notification.
     * @param array<string, mixed> $hookSpec         The `webhook` sub-document of the annotation.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function upsertWebhook(string $schemaSlug, string $notificationName, array $hookSpec): void
    {
        $webhookName = sprintf('or-notif-%s-%s', $schemaSlug, $notificationName);

        try {
            $existing = $this->findByName(name: $webhookName);
            $events   = (array) ($hookSpec['events'] ?? ['ObjectUpdatedEvent', 'ObjectTransitionedEvent']);
            $headers  = (array) ($hookSpec['headers'] ?? []);
            $secret   = ($hookSpec['secret'] ?? null);

            $payload = [
                'name'        => $webhookName,
                'url'         => (string) $hookSpec['url'],
                'method'      => strtoupper((string) ($hookSpec['method'] ?? 'POST')),
                'events'      => json_encode($events),
                'headers'     => count($headers) > 0 ? json_encode($headers) : null,
                'secret'      => is_string($secret) === true && $secret !== '' ? $secret : null,
                'enabled'     => true,
                'retryPolicy' => 'exponential',
                'maxRetries'  => 5,
                'timeout'     => 5,
            ];

            if ($existing === null) {
                $payload['uuid'] = (string) \Symfony\Component\Uid\Uuid::v4();
                $this->webhookMapper->createFromArray($payload);
                $this->logger->info(
                    sprintf('[NotificationsAnnotationInstaller] created Webhook "%s"', $webhookName)
                );
                return;
            }

            $this->webhookMapper->updateFromArray($existing->getId(), $payload);
            $this->logger->info(
                sprintf('[NotificationsAnnotationInstaller] updated Webhook "%s"', $webhookName)
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[NotificationsAnnotationInstaller] upsert "%s" failed: %s', $webhookName, $e->getMessage())
            );
        }//end try
    }//end upsertWebhook()

    /**
     * Look up an existing Webhook row by its unique name.
     *
     * @param string $name The webhook's `name` field.
     *
     * @return Webhook|null The matching webhook, or null when none is found.
     */
    private function findByName(string $name): ?Webhook
    {
        try {
            $matches = $this->webhookMapper->findAll(filters: ['name' => $name]);
            foreach ($matches as $w) {
                if ($w->getName() === $name) {
                    return $w;
                }
            }
        } catch (\Throwable $e) {
            // Fall through: treat as not-found.
        }

        return null;
    }//end findByName()
}//end class
