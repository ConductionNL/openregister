<?php

/**
 * OpenRegister CalculationWalk
 *
 * The state CalculationAnnotationValidator carries while it checks one schema:
 * the names an expression may read, the declaration being checked, the
 * errors found so far and the calculation dependency graph.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Validation state for one schema's calculations and references.
 */
final class CalculationWalk {

	/**
	 * Property and calculation names a plain `prop` may read.
	 *
	 * @var array<int, int|string>
	 */
	public array $allRefs = [];

	/**
	 * The name of the declaration being checked, for error messages.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * The errors found so far, in the order they were found.
	 *
	 * @var array<int, array{code: string, message: string}>
	 */
	public array $errors = [];

	/**
	 * Calculation name to the calculation names it reads.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $deps = [];


	/**
	 * Constructor.
	 *
	 * @param array<int, int|string> $propKeys         The schema's property names.
	 * @param array<int, int|string> $referenceKeys    Declared `@ref` names.
	 * @param array<int, int|string> $aggregateRefKeys Declared `@aggregate` names.
	 */
	public function __construct(
		public readonly array $propKeys,
		public readonly array $referenceKeys,
		public readonly array $aggregateRefKeys,
	) {
	}//end __construct()


	/**
	 * Record an error.
	 *
	 * @param string $code    The error code.
	 * @param string $message The error message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-2
	 */
	public function fail(string $code, string $message): void {
		$this->errors[] = [
			'code' => $code,
			'message' => $message,
		];
	}//end fail()


	/**
	 * Record that the calculation being checked reads another name.
	 *
	 * @param string $dependency The name it reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-2
	 */
	public function dependOn(string $dependency): void {
		$this->deps[$this->name][] = $dependency;
	}//end dependOn()
}//end class
