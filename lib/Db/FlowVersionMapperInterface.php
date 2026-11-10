<?php

/**
 * FlowVersionMapperInterface: what callers outside the component use of FlowVersionMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * The flow version history: the published graph, the version listing and pinning, and the repair-step back-fills.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to FlowVersionMapper.
 */
interface FlowVersionMapperInterface {

	/**
	 * Find one exact version of one flow.
	 *
	 * @param string  $flowUuid The flow.
	 * @param integer $version  The version number.
	 *
	 * @return FlowVersion|null The version, or null when there is no such row.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function find(string $flowUuid, int $version): ?FlowVersion;

	/**
	 * Find the single published version of a flow.
	 *
	 * 🔑 ORDERED BY VERSION DESCENDING AND CAPPED AT ONE, even though the
	 * service guarantees there is at most one published row. The guarantee is
	 * enforced in a transaction; this read runs on the object-write path and
	 * must give a DETERMINISTIC answer even if that invariant were ever
	 * broken by a bad migration or a manual edit. An unordered `LIMIT 1` over
	 * two published rows would hand different workers different graphs and the
	 * symptom would be intermittent.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return FlowVersion|null The published version, or null when none is published.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function findPublished(string $flowUuid): ?FlowVersion;

	/**
	 * List every version of a flow, newest first.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return FlowVersion[] The versions.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function findAllForFlow(string $flowUuid): array;

	/**
	 * The highest version number a flow has, or 0 when it has none.
	 *
	 * Read from the version rows rather than from the flow's own `version`
	 * column, deliberately: the flow column is a convenience mirror for the
	 * UI, and deriving the next number from a mirror is how two drafts end up
	 * claiming the same number. The unique index would catch that, but only
	 * after the transaction had done its other work.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return integer The highest version number, or 0 for a flow with no versions.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function highestVersion(string $flowUuid): int;

	/**
	 * Delete every version row of one flow.
	 *
	 * Called when the flow itself is deleted, AFTER its runs are gone. A
	 * version row exists so an in-flight run can resolve the graph it was
	 * pinned to; once no run of this flow remains, nothing can reach these rows
	 * through any read path the app has — every version read is by flow.
	 *
	 * @param string $flowUuid The flow being deleted.
	 *
	 * @return integer The number of version rows removed.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function deleteByFlow(string $flowUuid): int;

	/**
	 * Delete version rows whose flow no longer exists.
	 *
	 * 🔑 A ONE-OFF SWEEP FOR INSTANCES UPGRADED MID-FLIGHT. `FlowService::delete()`
	 * now cascades to this table, so no NEW orphan can appear — but any
	 * instance that deleted a flow between the version table landing and that
	 * cascade landing kept the rows. Measured on the dev instance: 38, growing
	 * with every flow anyone removed.
	 *
	 * Safe on the same terms as the cascade: `delete()` removes a flow's RUNS
	 * as well, so a version row whose flow is gone has no run left that could
	 * be pinned to it.
	 *
	 * @return integer The number of orphaned rows removed.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function deleteOrphaned(): int;

	/**
	 * Insert a new version row (QBMapper).
	 *
	 * @param Entity $entity The version.
	 *
	 * @return FlowVersion The stored version.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update a version row (QBMapper).
	 *
	 * @param Entity $entity The version.
	 *
	 * @return FlowVersion The stored version.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function update(Entity $entity): Entity;
}//end interface
