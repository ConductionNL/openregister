<?php

/**
 * OpenRegister AppHost — Schedule Action Allow-List
 *
 * A CLOSED, server-controlled map from a manifest `action` type to the vetted
 * PHP `jobClass` that executes it. A raw FQCN supplied in manifest data is NEVER
 * used as a `jobClass` — that would be arbitrary-code execution as the app owner
 * (design D-4, ADR-005). The allow-list is seeded with exactly one entry,
 * `openconnector:synchronization`; more actions are additive server-owned data
 * later, with no schema change.
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
 * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
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
	 * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
	 */
	public function resolve(string $action): ?string {
		$relativeClass = (self::MAP[$action] ?? null);
		if ($relativeClass === null) {
			return null;
		}

		return FleetAppId::className($this->appManager, self::CONNECTOR_APP, $relativeClass);
	}//end resolve()

	/**
	 * Whether an action type is on the allow-list.
	 *
	 * @param string $action The manifest-declared action type.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
	 */
	public function isAllowed(string $action): bool {
		return isset(self::MAP[$action]);
	}//end isAllowed()
}//end class
