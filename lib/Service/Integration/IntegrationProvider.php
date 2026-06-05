<?php

/**
 * IntegrationProvider interface for OpenRegister integrations.
 *
 * Contract for declaring "things that can be linked to or rendered alongside
 * an OpenRegister object." Each integration ships a vertical slice via a PHP
 * class implementing this interface (ADR-019).
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
 * @spec openspec/changes/integration-photos/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Interface for OpenRegister integration providers.
 *
 * Registered via the DI tag 'IntegrationProvider'. Frontend and backend
 * registrations are paired by the shared id returned from getId().
 */
interface IntegrationProvider
{
    /**
     * Unique integration identifier (e.g. 'photos', 'files', 'notes').
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Human-readable label.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Icon name (Material Design Icon or Nextcloud icon key).
     *
     * @return string
     */
    public function getIcon(): string;

    /**
     * Functional group for UI organisation (e.g. 'docs', 'communication').
     *
     * @return string
     */
    public function getGroup(): string;

    /**
     * Nextcloud app ID that must be installed for this integration to be available.
     * Return null if there is no dependency.
     *
     * @return string|null
     */
    public function getRequiredApp(): ?string;

    /**
     * Storage strategy: 'link-table' or 'external'.
     *
     * @return string
     */
    public function getStorageStrategy(): string;

    /**
     * Whether this integration requires explicit object-level permissions.
     * Return null to inherit file permissions.
     *
     * @return string|null
     */
    public function requiresPermission(): ?string;
}//end interface
