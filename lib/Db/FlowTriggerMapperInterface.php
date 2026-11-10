<?php

/**
 * FlowTriggerMapperInterface: what callers outside the Db component use of FlowTriggerMapper.
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

use OCA\OpenRegister\Db\Flow\FlowTriggerSet;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;

/**
 * The methods of FlowTriggerMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FlowTriggerMapper.
 */
interface FlowTriggerMapperInterface {

	/**
	 * Drop one flow's trigger rows.
	 *
	 * @param string $flowUuid The flow's UUID.
	 *
	 * @return int The number of rows removed.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function deleteFor(string $flowUuid): int;

	/**
	 * Every trigger row that names one schema, whatever the register or event.
	 *
	 * The rule inventory asks a different question from the hot path: not "does
	 * this event on this register and schema match a flow" but "which flows can
	 * act on objects of this schema at all". A disabled row is returned too,
	 * because a switched-off rule is exactly what the inventory has to show.
	 *
	 * @param string $schemaSlug The schema's slug, as the trigger node names it.
	 *
	 * @return array<int, array{flow_uuid: string, event: string, register: string, enabled: bool}> The rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function findBySchema(string $schemaSlug): array;

	/**
	 * The UUIDs of enabled flows subscribed to one object event.
	 *
	 * ONE indexed read, whatever the number of flows or the number of triggers
	 * each carries. `register` and `schema` are matched exactly — a trigger node
	 * cannot express "any", so there is no unscoped row to also match.
	 *
	 * @param string $event The event id.
	 * @param string $register The register slug the event fired on.
	 * @param string $schema The schema slug the event fired on.
	 *
	 * @return array<int, string> The flow UUIDs, each at most once.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function flowUuidsFor(string $event, string $register, string $schema): array;

	/**
	 * The UUIDs of enabled flows subscribed to one event on one register and schema.
	 *
	 * @param string            $event The event id.
	 * @param RegisterSchemaRef $table The register and schema slugs the event fired on.
	 *
	 * @return array<int, string> The flow UUIDs, each at most once.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function flowUuidsSubscribedTo(string $event, RegisterSchemaRef $table): array;

	/**
	 * Replace one flow's trigger rows with the given set.
	 *
	 * Delete-then-insert rather than a diff: the set is small, and a diff would
	 * have to decide what "the same trigger" means when a node's config is
	 * edited — getting that wrong leaves a flow subscribed to an event it no
	 * longer declares, which is the one outcome worse than not being subscribed.
	 *
	 * @param FlowTriggerSet $set The flow's UUID, its derived triggers (each event/register/schema) and whether it is enabled.
	 *
	 * @return int The number of rows written.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
	 */
	public function replaceFor(FlowTriggerSet $set): int;

	/**
	 * The UUIDs of every flow represented in the index.
	 *
	 * Used to decide, in one read, which flows the column fallback still owns.
	 *
	 * @return array<int, string> The flow UUIDs.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-the-cutover-from-trigger-columns-to-trigger-nodes-must-be-proven-per-flow
	 */
	public function representedFlowUuids(): array;
}//end interface
