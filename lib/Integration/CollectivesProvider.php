<?php

/**
 * CollectivesProvider — integration provider descriptor for the Collectives integration.
 *
 * Declares the provider metadata consumed by the pluggable integration registry
 * (ADR-019). When the registry interface is landed, this class MUST implement
 * IntegrationProvider.
 *
 * @category Integration
 * @package  OCA\OpenRegister\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-collectives/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Integration;

/**
 * Class CollectivesProvider
 *
 * Describes the Collectives integration to the OpenRegister registry.
 *
 * @category Integration
 * @package  OCA\OpenRegister\Integration
 */
class CollectivesProvider
{

    /**
     * Unique integration identifier.
     */
    public const ID = 'collectives';

    /**
     * Human-readable label shown in the UI.
     */
    public const LABEL = 'Knowledge';

    /**
     * MDI icon name used in the sidebar tab.
     */
    public const ICON = 'BookOpenPageVariant';

    /**
     * Integration group for grouping in the registry UI.
     */
    public const GROUP = 'docs';

    /**
     * The Nextcloud app that must be installed for this integration to activate.
     */
    public const REQUIRED_APP = 'collectives';

    /**
     * Storage strategy: links are kept in a dedicated link table.
     */
    public const STORAGE = 'link-table';

    /**
     * Return the integration ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return self::ID;
    }//end getId()

    /**
     * Return the human-readable label.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return self::LABEL;
    }//end getLabel()

    /**
     * Return the MDI icon name.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return self::ICON;
    }//end getIcon()

    /**
     * Return the integration group.
     *
     * @return string
     */
    public function getGroup(): string
    {
        return self::GROUP;
    }//end getGroup()

    /**
     * Return the required Nextcloud app ID.
     *
     * @return string
     */
    public function getRequiredApp(): string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    /**
     * Return the storage strategy.
     *
     * @return string
     */
    public function getStorageStrategy(): string
    {
        return self::STORAGE;
    }//end getStorageStrategy()

    /**
     * Collectives enforces its own ACLs; no additional OR permission required.
     *
     * @return null
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()
}//end class
