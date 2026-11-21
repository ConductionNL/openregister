<?php

/**
 * OpenRegister AppHost: the closed map from a schedule's action to its flow node.
 *
 * Nothing in manifest data names a class or a node type: that would be arbitrary
 * code running under the schedule's identity (ADR-005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

/**
 * Answers which flow node performs a schedule's action.
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */
final class ScheduleActionNodes {
	/**
	 * Action type to the flow node performing it, and the manifest argument
	 * that becomes the node's config key.
	 *
	 * @var array<string, array{type: string, argument: string, config: string}>
	 */
	private const NODES = [
		'openconnector:synchronization' => [
			'type' => 'openconnector.synchronization-run',
			'argument' => 'synchronizationId',
			'config' => 'synchronization',
		],
	];

	/**
	 * The flow node a schedule compiles to, or null when it may not run.
	 *
	 * @param ScheduleDescriptor $schedule The schedule.
	 *
	 * @return array{type: string, config: array<string, string>}|null Null for an
	 *         action off the map or a missing argument.
	 *
	 * @spec openspec/specs/apphost-scheduling/spec.md
	 */
	public function flowNode(ScheduleDescriptor $schedule): ?array {
		$node = (self::NODES[$schedule->action] ?? null);
		if ($node === null) {
			return null;
		}

		$value = ($schedule->arguments[$node['argument']] ?? null);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return ['type' => $node['type'], 'config' => [$node['config'] => $value]];
	}//end flowNode()
}//end class
