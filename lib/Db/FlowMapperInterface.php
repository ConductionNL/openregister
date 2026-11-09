<?php

/**
 * FlowMapperInterface: the surface of FlowMapper that callers type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The methods of FlowMapper that its callers use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FlowMapper. See FlowMapper for what each read scopes.
 */
interface FlowMapperInterface {

	/**
	 * Find a flow by its public uuid. Not organisation-scoped.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow The flow.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such flow exists.
	 */
	public function findByUuid(string $uuid): Flow;

	/**
	 * The stored flow a schema declares under this name.
	 *
	 * @param string $name       The declared flow's name.
	 * @param string $schemaSlug The declaring schema's slug.
	 *
	 * @return Flow|null The flow, or null when none is stored.
	 */
	public function findDeclared(string $name, string $schemaSlug): ?Flow;

	/**
	 * List flows, newest first.
	 *
	 * @param string|null  $app             Restrict to one owning app id.
	 * @param string|null  $applicationSlug Restrict to one OpenBuild virtual-app slug.
	 * @param string|null  $organisation    Restrict to one organisation uuid.
	 * @param boolean|null $enabled         Restrict to enabled or disabled flows.
	 * @param integer      $limit           Page size.
	 * @param integer      $offset          Page offset.
	 *
	 * @return array<int, Flow> The flows.
	 */
	public function findAllFlows(
		?string $app = null, ?string $applicationSlug = null, ?string $organisation = null,
		?bool $enabled = null, int $limit = 100, int $offset = 0,
	): array;

	/**
	 * Count flows matching the same scoping as findAllFlows().
	 *
	 * @param string|null $app             Restrict to one owning app id.
	 * @param string|null $applicationSlug Restrict to one OpenBuild virtual-app slug.
	 * @param string|null $organisation    Restrict to one organisation uuid.
	 *
	 * @return integer The number of matching flows.
	 */
	public function countFlows(
		?string $app = null,
		?string $applicationSlug = null,
		?string $organisation = null,
	): int;

	/**
	 * The enabled flows wired to one trigger.
	 *
	 * @param string      $trigger      The catalog trigger id.
	 * @param string|null $register     The subject's register slug, when object-scoped.
	 * @param string|null $schema       The subject's schema slug, when object-scoped.
	 * @param string|null $organisation Restrict to one organisation uuid.
	 *
	 * @return array<int, Flow> The candidate flows.
	 */
	public function findByTrigger(
		string $trigger,
		?string $register = null,
		?string $schema = null,
		?string $organisation = null,
	): array;

	/**
	 * The enabled flows that run on a cron schedule.
	 *
	 * @param string|null $organisation Restrict to one organisation uuid.
	 *
	 * @return array<int, Flow> The scheduled flows.
	 */
	public function findScheduled(?string $organisation = null): array;

	/**
	 * The ids of the flows one user owns.
	 *
	 * @param string $uid The owner's Nextcloud uid.
	 *
	 * @return array<int, string> The flow uuids that user owns.
	 */
	public function findIdsOwnedBy(string $uid): array;

	/**
	 * Every flow that declares its own retention override.
	 *
	 * @return array<int, Flow> The flows carrying a retention override.
	 */
	public function findWithRetentionOverride(): array;

	/**
	 * Insert a flow.
	 *
	 * @param Entity $entity The flow to insert.
	 *
	 * @return Flow The inserted flow.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update a flow.
	 *
	 * @param Entity $entity The flow to update.
	 *
	 * @return Flow The updated flow.
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete a flow.
	 *
	 * @param Entity $entity The flow to delete.
	 *
	 * @return Flow The deleted flow.
	 */
	public function delete(Entity $entity): Entity;
}//end interface
