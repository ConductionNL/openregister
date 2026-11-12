<?php

/**
 * OpenRegister Example Set Fill Run
 *
 * One call of ExampleSetValues::fill(): the context its placeholders draw
 * on, and the placeholders it could not fill so far. Passed between the
 * steps of the fill as one value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The context and the gaps of one fill.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 */
class ExampleSetFillRun {
	/**
	 * Every placeholder that could not be filled, in the order met.
	 *
	 * @var array<int, string>
	 */
	public array $gaps = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $context See ExampleSetValues::fill().
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $context,
	) {
	}//end __construct()
}//end class
