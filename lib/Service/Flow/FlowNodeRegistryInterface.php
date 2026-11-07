<?php

/**
 * FlowNodeRegistryInterface: what callers outside the Service component ask of FlowNodeRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use OCP\WorkflowEngine\IManager;

/**
 * The registered flow-step types, as the flow controllers and the node-rename
 * repair step use them. Only the methods and the constant those callers use.
 */
interface FlowNodeRegistryInterface {

	/**
	 * Node type ids that were renamed: old type id => new type id.
	 *
	 * The registry resolves through this map; the rename repair step rewrites
	 * stored definitions from the same map, so neither carries a second copy.
	 *
	 * @var array<string, string>
	 */
	public const RENAMED_TYPES = [
		// Renamed because it never looped: it splits items into fixed-size
		// batches. Sitting next to the real `openregister.iterate`, the old name
		// was a trap that re-armed for every new reader.
		'openregister.loop' => 'openregister.batch',
		// Renamed so one word carries the concept everywhere. The node ended a
		// path and called itself "Stop", the interface said "terminal" and the
		// palette badge said "ends" — three names for one idea. It is `end`
		// now, in the id, the class, the display name and the badge.
		'openregister.stop' => 'openregister.end',
	];

	/**
	 * The palette an editor renders, as plain data.
	 *
	 * @param integer $scope The scope to build the palette for.
	 *
	 * @return array<int, array<string, mixed>> One entry per available node.
	 */
	public function palette(int $scope = IManager::SCOPE_ADMIN): array;

	/**
	 * The links one run-log entry earns, from the node that wrote it.
	 *
	 * @param array<string, mixed> $entry One entry from a run's log.
	 *
	 * @return array<int, array<string, mixed>> The links.
	 */
	public function logActions(array $entry): array;

	/**
	 * Resolve a step's `type` to its node.
	 *
	 * @param string $type The step type.
	 *
	 * @return IFlowNode The node.
	 *
	 * @throws \UnexpectedValueException When no app provides that type.
	 */
	public function get(string $type): IFlowNode;
}//end interface
