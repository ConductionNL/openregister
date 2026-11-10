<?php

/**
 * OpenRegister CalculationApplyRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Listener\Support\CalculationSaveRun;

/**
 * One declared calculation being applied within one save's calculation pass.
 *
 * Built by {@see CalculationOnSaveListener} per declared calculation; it
 * never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class CalculationApplyRun {

	/**
	 * The save's calculation pass (object, schema slug, sequence context, payload).
	 *
	 * @var CalculationSaveRun|null
	 */
	public ?CalculationSaveRun $pass = null;

	/**
	 * The calculation's name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * The calculation's expression.
	 *
	 * @var mixed
	 */
	public mixed $expression = null;
}//end class
