<?php

/**
 * Pluggable Integration Provider contract.
 *
 * Provider for an integration that exposes a "linked thing" against an OR
 * object. Currently used for NC-native and external (via OpenConnector)
 * entities, but the contract is shaped generically — listing/CRUD methods
 * speak about "linked things" rather than NC entities — so that
 * RelationsService (object<->object) can be unified under the same
 * registry in a future change without breaking the interface (AD-14).
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
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Provider contract for pluggable integrations.
 *
 * Implementations register via the Nextcloud DI container with the
 * `IntegrationProvider` tag (see AD-1). `IntegrationRegistry` discovers
 * tagged providers at request time and uses the metadata methods
 * (`getId`, `getRequiredApp`, `getStorageStrategy`, `isEnabled`, ...) to
 * route sub-resource calls and render UI surfaces.
 *
 * The four storage strategies that providers MAY declare:
 *   - 'magic-column' — link stored as a column on the OR object row.
 *   - 'link-table'   — link stored in a dedicated openregister_*_links table.
 *   - 'external'     — no local persistence; CRUD routed through OpenConnector.
 *   - 'query-time'   — no local persistence; source system queried live on
 *                      every list() call. Mutation methods throw
 *                      NotImplementedException (AD-22).
 */
interface IntegrationProvider
{

    /**
     * Stable id used to address this integration.
     *
     * Conventionally lowercase kebab-case ('files', 'audit-trail',
     * 'openproject'). MUST be unique across the registry; collisions
     * are detected at container build time (AD-13).
     *
     * @return string Stable identifier.
     */
    public function getId(): string;

    /**
     * Translatable label shown to end users.
     *
     * Implementations are expected to return a Nextcloud-translated
     * string (e.g. `$this->l10n->t('Emails')`). The registry does NOT
     * call `t()` on the value.
     *
     * @return string Human-readable label.
     */
    public function getLabel(): string;

    /**
     * Material Design Icons name matching the frontend icon.
     *
     * Returned without the `mdi-` prefix and without file extension —
     * e.g. `'FileDocumentMultiple'`, `'Calendar'`, `'Paperclip'`. The
     * frontend resolves the icon by importing from
     * `vue-material-design-icons`.
     *
     * @return string MDI icon name.
     */
    public function getIcon(): string;

    /**
     * Optional named group used to cluster integrations in admin UI.
     *
     * One of: 'core', 'comms', 'docs', 'workflow', 'external'.
     * Returning null leaves the integration ungrouped (rendered at
     * the bottom of any group-sorted list).
     *
     * @return string|null Group name or null.
     */
    public function getGroup(): ?string;

    /**
     * Nextcloud app id this integration requires to be installed.
     *
     * Used by `isEnabled()` and the registry's existence filter
     * (stage 1 of the three-stage filter — AD-5). Built-in integrations
     * that are always available (e.g. tags, audit-trail) MUST return
     * null — they ride on OpenRegister itself.
     *
     * @return string|null NC app id or null when always-available.
     */
    public function getRequiredApp(): ?string;

    /**
     * Where this integration's links are stored.
     *
     * One of `'magic-column' | 'link-table' | 'external' | 'query-time'`.
     * See the interface-level docblock for semantics.
     *
     * The registry uses this to choose the dispatch path:
     *   - magic-column / link-table -> ObjectsController routes
     *   - external -> ExternalIntegrationRouter -> OpenConnector
     *   - query-time -> live read against the upstream source.
     *
     * @return string Storage strategy.
     */
    public function getStorageStrategy(): string;

    /**
     * OpenConnector source id for `storage='external'` providers.
     *
     * Providers with any other storage strategy MUST return null;
     * the registry rejects external providers that fail to declare
     * a source.
     *
     * @return string|null OpenConnector source id, or null.
     */
    public function getOpenConnectorSource(): ?string;

    /**
     * Whether the integration is currently usable on this instance.
     *
     * Typical implementation: check if `getRequiredApp()` (when set)
     * is installed and enabled via `IAppManager`. External providers
     * additionally validate that their OpenConnector source exists.
     *
     * @return bool True when the integration may be invoked.
     */
    public function isEnabled(): bool;

    /**
     * Optional permission required to use this integration.
     *
     * Returning null (default) means access inherits from the
     * underlying object's RBAC + the NC app's own permissions. When
     * a string is returned the registry calls AuthorizationService
     * with the current user and target object to gate visibility
     * (AD-16).
     *
     * @return string|null Permission string or null.
     */
    public function requiresPermission(): ?string;

    /**
     * Credential requirements for the integration.
     *
     * Shape: `['type' => 'none'|'oauth2'|'api-key'|'basic', 'configSchema' => [...]]`.
     * Built-in NC integrations return `['type' => 'none']`. External
     * providers describe the auth shape so OpenRegister's admin UI
     * can render configure-buttons that delegate to OpenConnector's
     * credential management (AD-15).
     *
     * @return array<string,mixed> Auth-requirements descriptor.
     */
    public function authRequirements(): array;

    /**
     * List linked things for an OR object.
     *
     * Implementations MAY honour the `$filters` array — common keys
     * are `_limit` (int), `_page` (int), `_search` (string). Unknown
     * filters MUST be ignored, not rejected.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional list filters.
     *
     * @return array<int,array<string,mixed>> List of linked things.
     */
    public function list(string $register, string $schema, string $objectId, array $filters = []): array;

    /**
     * Get a single linked thing by id.
     *
     * Used by widget surface='single-entity' rendering — e.g. when a
     * schema property of type 'reference' carries a referenceType
     * marker pointing at this integration, `CnFormDialog` / `CnDetailGrid`
     * fetch the referenced entity through this method (AD-18).
     *
     * Implementations MAY throw a `NotImplementedException` for
     * list-only providers; the registry surfaces the failure via the
     * widget's empty state.
     *
     * @param string $register Register slug or numeric id.
     * @param string $schema   Schema slug or numeric id.
     * @param string $objectId Owning object uuid.
     * @param string $entityId Linked-thing id.
     *
     * @return array<string,mixed> The linked thing.
     */
    public function get(string $register, string $schema, string $objectId, string $entityId): array;

    /**
     * Create / attach a new linked thing.
     *
     * Providers with `storage='query-time'` MUST throw
     * NotImplementedException — there is no local store to write to.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $payload  New linked-thing fields.
     *
     * @return array<string,mixed> The created linked thing.
     */
    public function create(string $register, string $schema, string $objectId, array $payload): array;

    /**
     * Update a linked thing.
     *
     * Providers with `storage='query-time'` MUST throw
     * NotImplementedException.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param string              $entityId Linked-thing id.
     * @param array<string,mixed> $payload  Update payload.
     *
     * @return array<string,mixed> The updated linked thing.
     */
    public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array;

    /**
     * Delete / unlink a linked thing.
     *
     * Providers with `storage='query-time'` MUST throw
     * NotImplementedException.
     *
     * @param string $register Register slug or numeric id.
     * @param string $schema   Schema slug or numeric id.
     * @param string $objectId Owning object uuid.
     * @param string $entityId Linked-thing id.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $entityId): void;

    /**
     * Health + auth status for display in admin UI and OCS capabilities.
     *
     * Shape: `['status' => 'ok'|'degraded'|'unavailable', 'authStatus' => 'configured'|'missing'|'expired', 'message' => ?string]`.
     *
     * SHOULD be cheap to call — implementations are expected to use
     * a per-request cache. Per AD-23 health is NOT called on every
     * render; it's used by admin UI and the OCS capabilities response
     * for discovery (AD-17).
     *
     * @return array<string,mixed> Health + auth descriptor.
     */
    public function health(): array;

}//end interface
