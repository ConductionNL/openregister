<?php

/**
 * OpenRegister Abstract Integration Provider
 *
 * Default implementations for IntegrationProvider that concrete providers
 * inherit. Provides sensible defaults so providers only override what's
 * specific to their integration.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Abstract base providing default implementations for IntegrationProvider.
 *
 * Concrete providers extend this class and override only what differs.
 * The default getOpenConnectorSource() returns null (non-external providers).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-1
 */
abstract class AbstractIntegrationProvider implements IntegrationProvider
{
    /**
     * Default: no required NC app.
     *
     * Override in providers that require a specific Nextcloud app.
     *
     * @return string|null Always null in default implementation.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    /**
     * Default: local storage strategy.
     *
     * Override in external providers to return 'external'.
     *
     * @return string 'local' by default.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getStorageStrategy(): string
    {
        return 'local';
    }//end getStorageStrategy()

    /**
     * Default: no auth requirements.
     *
     * Override in providers that require OAuth2 or other auth configuration.
     *
     * @return array<string,mixed>|null Always null in default implementation.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function authRequirements(): ?array
    {
        return null;
    }//end authRequirements()

    /**
     * Default: no permission requirement.
     *
     * Returning null means the underlying service's own ACLs govern access.
     *
     * @return string|null Always null in default implementation.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()

    /**
     * Default: no OpenConnector source (non-external providers).
     *
     * External providers override this to return their source id.
     * Per ADR-019 AD-4: having this on the interface keeps dispatch uniform.
     *
     * @return string|null Always null in default implementation.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getOpenConnectorSource(): ?string
    {
        return null;
    }//end getOpenConnectorSource()

    /**
     * Default: available (for providers with no external dependencies).
     *
     * Providers with external checks override this.
     *
     * @return string 'available' by default.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function health(): string
    {
        return 'available';
    }//end health()
}//end class
