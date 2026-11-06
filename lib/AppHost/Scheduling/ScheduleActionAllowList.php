<?php

/**
 * OpenRegister AppHost — Schedule Action Allow-List
 *
 * A CLOSED, server-controlled map from a manifest `action` type to the flow node
 * that performs it. Nothing in manifest data names a class or a node type:
 * that would be arbitrary code running under the schedule's identity (ADR-005).
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

use OCA\OpenRegister\Support\FleetAppId;
use OCP\App\IAppManager;

/**
 * Closed allow-list mapping action types to vetted job classes.
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */
class ScheduleActionAllowList {
	/**
	 * The closed action-type → vetted job class map.
	 *
	 * The KEY is manifest data: leaf apps write `action: 'openconnector:…'`
	 * into their own `src/manifest.json`, so it is a published contract and
	 * stays spelled as it is — renaming it would silently un-allow-list every
	 * schedule already declared against it.
	 *
	 * The VALUE is a class path RELATIVE to the connector's namespace, because
	 * that half did move: the class is `OCA\Integriq\Action\…` on
	 * development and `OCA\OpenConnector\Action\…` on beta/main. It stays a
	 * plain string so referencing this map never autoloads the cross-app class
	 * (it is resolved lazily by the connector's JobService container at
	 * execution time, not by OpenRegister) — which is why the namespace is
	 * picked from the INSTALLED APP ID rather than by probing `class_exists`.
	 *
	 * @var array<string, string>
	 */
	private const MAP = [
		'openconnector:synchronization' => 'Action\\SynchronizationAction',
	];

	/**
	 * Canonical (new) id of the connector app, for {@see FleetAppId}.
	 *
	 * @var string
	 */
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

	private const CONNECTOR_APP = 'integriq';


	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Resolves which connector id is installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Resolve an action type to its vetted `jobClass`, or null when not allow-listed.
	 *
	 * @param string $action The manifest-declared action type.
	 *
	 * @return string|null The server-vetted job class, or null when the action is not allow-listed.
	 *
	 * @spec openspec/specs/apphost-scheduling/spec.md
	 */
	public function resolve(string $action): ?string {
		$relativeClass = (self::MAP[$action] ?? null);
		if ($relativeClass === null) {
			return null;
		}

		return FleetAppId::className($this->appManager, self::CONNECTOR_APP, $relativeClass);
	}//end resolve()

	/**
	 * The flow node a schedule compiles to, or null when it may not run.
	 *
	 * @param ScheduleDescriptor $schedule The schedule.
	 *
	 * @return array{type: string, config: array<string, string>}|null Null for an
	 *         action off the map or a missing argument.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
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

	/**
	 * Whether an action type is on the allow-list.
	 *
	 * @param string $action The manifest-declared action type.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/apphost-scheduling/spec.md
	 */
	public function isAllowed(string $action): bool {
		return isset(self::MAP[$action]);
	}//end isAllowed()
}//end class
