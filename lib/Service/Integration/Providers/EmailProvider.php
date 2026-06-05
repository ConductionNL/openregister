<?php

/**
 * EmailProvider
 *
 * Pluggable integration provider for Nextcloud Mail. Wraps EmailService to expose
 * email-to-object links through the IntegrationProvider contract. This is a
 * link-only integration — Mail owns send/compose.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-email/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;

/**
 * Integration provider for Nextcloud Mail messages linked to OR objects.
 *
 * Registered via the 'IntegrationProvider' DI tag. The registry filters this
 * provider out when the 'mail' NC app is not installed (getRequiredApp).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @spec openspec/changes/integration-email/tasks.md#task-1
 */
class EmailProvider implements IntegrationProvider
{

    /**
     * Integration identifier.
     */
    private const ID = 'email';

    /**
     * Human-readable label.
     */
    private const LABEL = 'Emails';

    /**
     * MDI icon identifier.
     */
    private const ICON = 'Email';

    /**
     * Registry group key.
     */
    private const GROUP = 'comms';

    /**
     * Required NC app identifier.
     */
    private const REQUIRED_APP = 'mail';

    /**
     * Storage strategy.
     */
    private const STORAGE = 'link-table';

    /**
     * Email service for link management.
     *
     * @var EmailService
     */
    private readonly EmailService $emailService;

    /**
     * Constructor.
     *
     * @param EmailService $emailService Email link service.
     *
     * @return void
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }//end __construct()

    /**
     * Return the integration id.
     *
     * @return string
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getId(): string
    {
        return self::ID;
    }//end getId()

    /**
     * Return the human-readable label.
     *
     * @return string
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getLabel(): string
    {
        return self::LABEL;
    }//end getLabel()

    /**
     * Return the MDI icon identifier.
     *
     * @return string
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getIcon(): string
    {
        return self::ICON;
    }//end getIcon()

    /**
     * Return the registry group key.
     *
     * @return string
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getGroup(): string
    {
        return self::GROUP;
    }//end getGroup()

    /**
     * Return the required NC app id.
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    /**
     * Return the storage strategy identifier.
     *
     * @return string
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getStorageStrategy(): string
    {
        return self::STORAGE;
    }//end getStorageStrategy()

    /**
     * Return null — access inherits from object RBAC + Mail app account ownership.
     *
     * @return null
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()

    /**
     * Retrieve email links for an object.
     *
     * Delegates to EmailService::getEmailsForObject(). Returns an empty
     * result set (no error) when the Mail app is not available — the registry
     * should have filtered this provider out, but this guard adds safety.
     *
     * @param string   $objectUuid The target object UUID.
     * @param int|null $limit      Maximum results.
     * @param int|null $offset     Pagination offset.
     *
     * @return array{results: array, total: int}
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getLinkedItems(string $objectUuid, ?int $limit=null, ?int $offset=null): array
    {
        if ($this->emailService->isMailAvailable() === false) {
            return ['results' => [], 'total' => 0];
        }

        return $this->emailService->getEmailsForObject(
            objectUuid: $objectUuid,
            limit: $limit,
            offset: $offset
        );
    }//end getLinkedItems()
}//end class
