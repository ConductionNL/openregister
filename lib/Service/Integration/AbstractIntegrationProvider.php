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

use BadMethodCallException;
use OCA\OpenRegister\Exception\NotImplementedException;

/**
 * Default starting point for concrete IntegrationProvider implementations.
 *
 * Concrete providers MUST override the metadata methods (getId,
 * getLabel, getIcon, getRequiredApp, getStorageStrategy) and the
 * read-path list(). They MAY add get/create/update/delete when their
 * storage strategy supports those operations, in either published form
 * (see IntegrationProvider); an operation a provider does not declare
 * answers NotImplementedException, called directly or through
 * IntegrationProviderInvoker.
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
abstract class AbstractIntegrationProvider implements IntegrationProvider {
	/**
	 * Optional group identifier.
	 *
	 * @return string|null Default null (ungrouped).
	 */
	public function getGroup(): ?string {
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
	public function requiresPermission(): ?string {
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
	public function authRequirements(): array {
		return ['type' => 'none'];
	}//end authRequirements()

	/**
	 * OpenConnector source id.
	 *
	 * @return string|null Default null. External providers
	 *                     (storage='external') MUST override.
	 */
	public function getOpenConnectorSource(): ?string {
		return null;
	}//end getOpenConnectorSource()

	/**
	 * Answer NotImplementedException for a linked-thing operation the provider does not declare.
	 *
	 * The operations are not declared here so that a subclass can declare
	 * them in either published form; a declared method always wins over this.
	 *
	 * @param string      $name      The method called.
	 * @param list<mixed> $arguments Its arguments.
	 *
	 * @return never
	 *
	 * @throws NotImplementedException For get, create, update, delete or list.
	 * @throws BadMethodCallException  For any other undeclared method.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature of __call is fixed by PHP.
	 */
	public function __call(string $name, array $arguments): never {
		if (in_array($name, ['list', 'get', 'create', 'update', 'delete'], true) === true) {
			throw $this->unsupported(operation: $name);
		}

		throw new BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $name));
	}//end __call()

	/**
	 * The NotImplementedException a provider answers for an operation it does not support.
	 *
	 * @param string $operation The operation, as the message names it: `list`, `get`, `create`, `update` or `delete`.
	 *
	 * @return NotImplementedException
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	protected function unsupported(string $operation): NotImplementedException {
		return new NotImplementedException(
			message: sprintf('Provider %s does not support %s()', $this->getId(), $operation)
		);
	}//end unsupported()

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
	public function health(): array {
		return [
			'status' => 'ok',
			'authStatus' => 'configured',
			'message' => null,
		];
	}//end health()

	/**
	 * Health descriptor of a provider that needs one Nextcloud app: ok when it
	 * is available, otherwise unavailable with the given message.
	 *
	 * @param bool   $available Whether the backing app is available.
	 * @param string $missing   The message when it is not.
	 *
	 * @return array{status: string, authStatus: string, message: ?string}
	 */
	protected function installedHealth(bool $available, string $missing): array {
		if ($available === true) {
			return ['status' => 'ok', 'authStatus' => 'configured', 'message' => null];
		}

		return ['status' => 'unavailable', 'authStatus' => 'configured', 'message' => $missing];
	}//end installedHealth()
}//end class
