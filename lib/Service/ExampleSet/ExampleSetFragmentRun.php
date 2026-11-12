<?php

/**
 * OpenRegister Example Set Fragment Run
 *
 * One fragment of one install run: the plan it belongs to, the fragment itself and the context every placeholder of the fragment may draw on.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The plan, the fragment and the base context of one fragment's write pass.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
 */
final class ExampleSetFragmentRun {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $plan     The plan.
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $base     The context every placeholder may draw on.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $plan,
		public readonly array $fragment,
		public readonly array $base,
	) {
	}//end __construct()
}//end class
