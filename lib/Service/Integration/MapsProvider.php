<?php

/**
 * MapsProvider — integration provider for NC Maps (geolocation).
 *
 * Declares the integration metadata consumed by the pluggable integration registry
 * (ADR-019). Backend half of the maps integration; frontend half lives in
 *
 * @conduction/nextcloud-vue src/integrations/builtin/maps.js.
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
 * @spec openspec/changes/integration-maps/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * MapsProvider declares the integration metadata for NC Maps geolocations.
 *
 * Properties align with the ADR-019 integration registry contract:
 * id, label, icon, group, requiredApp, storageStrategy, requiresPermission.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 */
class MapsProvider
{

    /**
     * Unique integration ID; matches the frontend registration id.
     */
    public const ID = 'maps';

    /**
     * Human-readable label shown in the sidebar and widget headers.
     */
    public const LABEL = 'Location';

    /**
     * Icon name from @mdi/js used for the integration.
     */
    public const ICON = 'MapMarker';

    /**
     * Sidebar / dashboard group that this integration belongs to.
     */
    public const GROUP = 'docs';

    /**
     * Nextcloud app that must be installed for this integration to be available.
     */
    public const REQUIRED_APP = 'maps';

    /**
     * Storage strategy identifier — uses a dedicated link table.
     */
    public const STORAGE_STRATEGY = 'link-table';

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
     * Return the icon name.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return self::ICON;
    }//end getIcon()

    /**
     * Return the sidebar group.
     *
     * @return string
     */
    public function getGroup(): string
    {
        return self::GROUP;
    }//end getGroup()

    /**
     * Return the Nextcloud app that must be installed.
     *
     * @return string
     */
    public function getRequiredApp(): string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    /**
     * Return the storage strategy identifier.
     *
     * @return string
     */
    public function getStorageStrategy(): string
    {
        return self::STORAGE_STRATEGY;
    }//end getStorageStrategy()

    /**
     * Return null — permission is inherited from the object and the Maps ACL.
     *
     * @return null
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()

    /**
     * Return the full metadata array for serialisation / OCS capabilities.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                 => $this->getId(),
            'label'              => $this->getLabel(),
            'icon'               => $this->getIcon(),
            'group'              => $this->getGroup(),
            'requiredApp'        => $this->getRequiredApp(),
            'storageStrategy'    => $this->getStorageStrategy(),
            'requiresPermission' => $this->requiresPermission(),
        ];
    }//end toArray()
}//end class
