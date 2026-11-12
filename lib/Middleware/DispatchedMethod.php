<?php

/**
 * DispatchedMethod: the controller method a middleware hook runs around.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

/**
 * The controller and the method name the framework hands every middleware hook.
 *
 * Built on the first line of a hook, so the helpers behind it take one value
 * instead of the pair.
 *
 * @spec openspec/architecture/adr-011-parameter-objects-for-unit-interfacing.md
 */
final class DispatchedMethod {
	/**
	 * Constructor.
	 *
	 * @param mixed  $controller The controller being dispatched (an instance or a class name).
	 * @param string $methodName The controller method being dispatched.
	 */
	public function __construct(
		public readonly mixed $controller,
		public readonly string $methodName,
	) {
	}//end __construct()
}//end class
