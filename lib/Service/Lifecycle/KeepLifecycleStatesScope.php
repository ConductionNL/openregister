<?php

/**
 * KeepLifecycleStatesScope
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * A scope in which a create keeps the lifecycle state it carries.
 *
 * An administrator's historic import runs inside it, so imported rows keep
 * their own state instead of being refused for not starting in the initial
 * one. It exempts that one check and nothing else: permissions, guards and
 * validation still apply to the caller, so it is not an elevation (ADR-099).
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-an-administrator-can-import-historic-data-in-its-own-states
 */
final class KeepLifecycleStatesScope {

	/**
	 * Nesting depth of active scopes.
	 *
	 * @var integer
	 */
	private static int $depth = 0;

	/**
	 * A static scope holder; not instantiated.
	 */
	private function __construct() {
	}//end __construct()

	/**
	 * Run a callable in which creates keep their lifecycle state.
	 *
	 * @param callable $operation The work to run.
	 *
	 * @return mixed Whatever the callable returns.
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-an-administrator-can-import-historic-data-in-its-own-states
	 */
	public static function run(callable $operation): mixed {
		self::$depth++;
		try {
			$result = $operation();
		} finally {
			self::$depth--;
		}

		return $result;
	}//end run()

	/**
	 * Whether a create now keeps its lifecycle state.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-an-administrator-can-import-historic-data-in-its-own-states
	 */
	public static function isActive(): bool {
		return self::$depth !== 0;
	}//end isActive()
}//end class
