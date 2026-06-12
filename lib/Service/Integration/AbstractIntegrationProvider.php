<?php

/**
 * Abstract base class for IntegrationProvider implementations.
 *
 * Carries the boilerplate defaults so concrete providers only declare
 * what's actually specific to them:
 *   - group        defaults to null (ungrouped)
 *   - requiresPermission defaults to null (inherits from object RBAC)
 *   - authRequirements   defaults to ['type' => 'none']
 *   - getOpenConnectorSource defaults to null
 *   - health() defaults to a static "ok / configured" descriptor;
 *     external providers override with a real check.
 *
 * Per AD-22 the get(), create(), update(), delete() methods default
 * to throwing NotImplementedException so that query-time and list-only
 * providers don't have to spell that out themselves.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Exception\NotImplementedException;

/**
 * Default starting point for concrete IntegrationProvider implementations.
 *
 * Concrete providers MUST override the metadata methods (getId,
 * getLabel, getIcon, getRequiredApp, getStorageStrategy) and the
 * read-path list(). They MAY override get/create/update/delete when
 * their storage strategy supports those operations.
 *
 * @SuppressWarnings(PHPMD.NumberOfChildren) ADR-019's pluggable integration
 *   registry is built around exactly this template-method base — each leaf
 *   (Calendar/Talk/Email/Bookmarks/Collectives/Deck/Polls/Activity/Photos/
 *   Maps/Forms/Cospend/Shares/TimeTracker/OpenProject/Flow/Analytics/Files/
 *   Notes/Tags/Tasks/AuditTrail/Xwiki, …) extends it; the count grows with
 *   the integration catalog, not from a design smell. Redesigning the base
 *   into composition would require redesigning the registry contract used
 *   by `useIntegrationRegistry`/`CnObjectSidebar`/`CnDashboardPage` across
 *   nextcloud-vue + every fleet app.
 */
abstract class AbstractIntegrationProvider implements IntegrationProvider
{
    /**
     * Optional group identifier.
     *
     * @return string|null Default null (ungrouped).
     */
    public function getGroup(): ?string
    {
        return null;
    }//end getGroup()

    /**
     * Permission required to use this integration.
     *
     * @return string|null Default null (inherits from object RBAC).
     *
     * @spec exclude Base-class default (trivial `return null`) — RBAC gating contract is owned by
     *              pluggable-integration-registry task-2/generic-integrations Permission Inheritance;
     *              concrete overrides carry the behaviour.
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()

    /**
     * Auth-requirements descriptor.
     *
     * @return array<string,mixed> Default `['type' => 'none']` for
     *                             integrations that need no credentials.
     *
     * @spec exclude Base-class default (static `['type' => 'none']`) — the auth-requirements contract is owned by
     *              pluggable-integration-registry task-2; external providers override with real descriptors.
     */
    public function authRequirements(): array
    {
        return ['type' => 'none'];
    }//end authRequirements()

    /**
     * OpenConnector source id.
     *
     * @return string|null Default null. External providers
     *                     (storage='external') MUST override.
     */
    public function getOpenConnectorSource(): ?string
    {
        return null;
    }//end getOpenConnectorSource()

    /**
     * Get a single linked thing.
     *
     * Default: throw NotImplementedException. List-only and query-time
     * providers can rely on this default; CRUD-capable providers
     * override.
     *
     * @param string $register Register slug or numeric id.
     * @param string $schema   Schema slug or numeric id.
     * @param string $objectId Owning object uuid.
     * @param string $entityId Linked-thing id.
     *
     * @return array<string,mixed>
     *
     * @throws NotImplementedException Always, unless overridden.
     *
     * @spec exclude Base-class default-throwing stub for list-only/query-time providers — the NotImplemented→501
     *              contract is owned by pluggable-integration-registry task-2/task-6; CRUD-capable providers override.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parameters are mandated by the IntegrationProvider interface;
     *   this abstract stub intentionally throws — concrete providers override and use all parameters.
     */
    public function get(string $register, string $schema, string $objectId, string $entityId): array
    {
        throw new NotImplementedException(
            message: sprintf('Provider %s does not support get()', $this->getId())
        );
    }//end get()

    /**
     * Create / attach a linked thing.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $payload  New linked-thing fields.
     *
     * @return array<string,mixed>
     *
     * @throws NotImplementedException Always, unless overridden.
     *
     * @spec exclude Base-class default-throwing stub for list-only/query-time providers — the NotImplemented→501
     *              contract is owned by pluggable-integration-registry task-2/task-6; CRUD-capable providers override.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parameters are mandated by the IntegrationProvider interface;
     *   this abstract stub intentionally throws — concrete providers override and use all parameters.
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array
    {
        throw new NotImplementedException(
            message: sprintf('Provider %s does not support create()', $this->getId())
        );
    }//end create()

    /**
     * Update a linked thing.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param string              $entityId Linked-thing id.
     * @param array<string,mixed> $payload  Update payload.
     *
     * @return array<string,mixed>
     *
     * @throws NotImplementedException Always, unless overridden.
     *
     * @spec exclude Base-class default-throwing stub for list-only/query-time providers — the NotImplemented→501
     *              contract is owned by pluggable-integration-registry task-2/task-6; CRUD-capable providers override.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parameters are mandated by the IntegrationProvider interface;
     *   this abstract stub intentionally throws — concrete providers override and use all parameters.
     */
    public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array
    {
        throw new NotImplementedException(
            message: sprintf('Provider %s does not support update()', $this->getId())
        );
    }//end update()

    /**
     * Delete / unlink a linked thing.
     *
     * @param string $register Register slug or numeric id.
     * @param string $schema   Schema slug or numeric id.
     * @param string $objectId Owning object uuid.
     * @param string $entityId Linked-thing id.
     *
     * @return void
     *
     * @throws NotImplementedException Always, unless overridden.
     *
     * @spec exclude Base-class default-throwing stub for list-only/query-time providers — the NotImplemented→501
     *              contract is owned by pluggable-integration-registry task-2/task-6; CRUD-capable providers override.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parameters are mandated by the IntegrationProvider interface;
     *   this abstract stub intentionally throws — concrete providers override and use all parameters.
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void
    {
        throw new NotImplementedException(
            message: sprintf('Provider %s does not support delete()', $this->getId())
        );
    }//end delete()

    /**
     * Default health descriptor — assumes a healthy, configured integration.
     *
     * External providers override with a real availability check. Per
     * AD-23 this is NOT called on every render; it's used by admin UI
     * and the OCS capabilities response for discovery.
     *
     * @return array<string,mixed>
     *
     * @spec exclude Base-class default health descriptor (static ok/configured) — the health/OCS-capabilities
     *              contract is owned by pluggable-integration-registry task-2; external providers override with a real probe.
     */
    public function health(): array
    {
        return [
            'status'     => 'ok',
            'authStatus' => 'configured',
            'message'    => null,
        ];
    }//end health()
}//end class
