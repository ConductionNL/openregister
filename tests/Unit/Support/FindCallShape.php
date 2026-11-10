<?php

/**
 * OpenRegister FindCallShape
 *
 * Spreads the arguments a find() or findSilent() double receives into the
 * flat parameter list the recordings of the characterisation tests hold, so
 * a recording keeps describing the read, not the call shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * Flat views of a find() / findSilent() call.
 */
final class FindCallShape {

	/**
	 * The find() arguments as id, extend, files, register, schema, rbac, multitenancy, render, audit.
	 *
	 * @param array $args The arguments the double received.
	 *
	 * @return array The flat list.
	 */
	public static function find(array $args): array {
		$options = self::options(args: $args);

		return [
			$args[0],
			$options->extend,
			$options->files,
			$options->register,
			$options->schema,
			($args[2] ?? true),
			($args[3] ?? true),
			$options->render,
			$options->audit,
		];
	}//end find()

	/**
	 * The findSilent() arguments as id, extend, files, register, schema, rbac, multitenancy.
	 *
	 * @param array $args The arguments the double received.
	 *
	 * @return array The flat list.
	 */
	public static function findSilent(array $args): array {
		return array_slice(self::find(args: $args), 0, 7);
	}//end findSilent()

	/**
	 * The options of the call, the defaults when it passed none.
	 *
	 * @param array $args The arguments the double received.
	 *
	 * @return ObjectFindOptions The options.
	 */
	private static function options(array $args): ObjectFindOptions {
		return ($args[1] ?? null) ?? new ObjectFindOptions();
	}//end options()
}//end class
