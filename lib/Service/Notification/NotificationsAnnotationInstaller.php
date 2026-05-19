<?php

/**
 * NotificationsAnnotationInstaller
 *
 * Creates managed Webhook entities for notification rules that declare
 * webhook.persistent: true, so those deliveries use the standard webhook
 * pipeline (exponential retry, dead-letter queue).
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use Psr\Log\LoggerInterface;

/**
 * Installs persistent Webhook entities from notification rule annotations.
 *
 * @psalm-suppress UnusedClass
 */
class NotificationsAnnotationInstaller
{
    /**
     * Constructor.
     *
     * @param WebhookMapper   $webhookMapper Webhook mapper.
     * @param LoggerInterface $logger        Logger.
     */
    public function __construct(
        private readonly WebhookMapper $webhookMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Install webhook entities for rules that declare webhook.persistent: true.
     *
     * @param string               $schemaId      Schema identifier (used as webhook name prefix).
     * @param array<string, mixed> $configuration Schema configuration.
     *
     * @return list<Webhook> Created or existing webhook entities.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-4
     */
    public function install(string $schemaId, array $configuration): array
    {
        $rules = $configuration['x-openregister-notifications'] ?? [];
        if (is_array(value: $rules) === false) {
            return [];
        }

        $webhooks = [];
        foreach ($rules as $index => $rule) {
            if ($this->isPersistentWebhookRule(rule: $rule) === false) {
                continue;
            }

            $webhookName = 'notification-'.$schemaId.'-rule-'.$index;
            $webhook     = $this->getOrCreateWebhook(
                name: $webhookName,
                rule: $rule,
                schemaId: $schemaId
            );

            if ($webhook !== null) {
                $webhooks[] = $webhook;
            }
        }

        return $webhooks;
    }//end install()

    /**
     * Check if a rule uses a persistent webhook channel.
     *
     * @param mixed $rule Rule spec.
     *
     * @return bool
     */
    private function isPersistentWebhookRule(mixed $rule): bool
    {
        if (is_array(value: $rule) === false) {
            return false;
        }

        $channels = $rule['channels'] ?? [];
        if (in_array(needle: 'webhook', haystack: (array) $channels, strict: true) === false) {
            return false;
        }

        return ($rule['webhook']['persistent'] ?? false) === true;
    }//end isPersistentWebhookRule()

    /**
     * Find an existing managed webhook by name, or create a new one.
     *
     * @param string               $name     Webhook name.
     * @param array<string, mixed> $rule     Notification rule spec.
     * @param string               $schemaId Schema identifier for event filtering.
     *
     * @return Webhook|null
     */
    private function getOrCreateWebhook(string $name, array $rule, string $schemaId): ?Webhook
    {
        $url = $rule['webhook']['url'] ?? '';
        if ($url === '') {
            $this->logger->warning(
                message: 'Persistent webhook rule has no URL, skipping',
                context: ['name' => $name, 'schemaId' => $schemaId]
            );
            return null;
        }

        try {
            $webhooks = $this->webhookMapper->findAll(
                limit: 1,
                offset: 0,
                filters: ['name' => $name]
            );

            if (empty($webhooks) === false) {
                return $webhooks[0];
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: 'Could not query existing webhooks',
                context: ['error' => $e->getMessage()]
            );
        }

        try {
            $webhook = new Webhook();
            $webhook->setName(name: $name);
            $webhook->setUrl(url: $url);
            $webhook->setMethod(method: 'POST');
            $webhook->setEnabled(enabled: true);
            $webhook->setMaxRetries(maxRetries: 5);
            $webhook->setRetryPolicy(retryPolicy: 'exponential');
            $events = [
                'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
                'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent',
                'OCA\\OpenRegister\\Event\\ObjectDeletedEvent',
            ];
            $webhook->setEvents(events: json_encode(value: $events, flags: JSON_THROW_ON_ERROR));

            return $this->webhookMapper->insert(entity: $webhook);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: 'Failed to create persistent webhook',
                context: ['name' => $name, 'error' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end getOrCreateWebhook()
}//end class
