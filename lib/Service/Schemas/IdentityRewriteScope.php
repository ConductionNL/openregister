<?php

/**
 * OpenRegister IdentityRewriteScope
 *
 * The one way past the guard that keeps an object's identity values from
 * changing: the audited schema migration path opens this scope around its
 * writes, and nothing a request carries can open it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * A scope, entered only from PHP code, in which identity values may be rewritten.
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */
final class IdentityRewriteScope {

	/**
	 * Nesting depth of active scopes.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Run a callable inside the scope; it ends with the callable, exceptions included.
	 *
	 * @param callable $operation The audited rewrite.
	 *
	 * @return mixed Whatever the callable returns.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public static function run(callable $operation): mixed {
		self::$depth++;

		try {
			return $operation();
		} finally {
			self::$depth--;
		}
	}//end run()

	/**
	 * Whether a rewrite scope is open.
	 *
	 * @return bool True inside run().
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public static function isActive(): bool {
		return self::$depth > 0;
	}//end isActive()
}//end class
