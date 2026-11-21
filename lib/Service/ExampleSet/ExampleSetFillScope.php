<?php

/**
 * OpenRegister Example Set Fill Scope
 *
 * What a placeholder fill draws on, and where it names what it could not fill.
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
 * The context placeholders draw on, and the caller's gap list, held by reference: a fill appends every placeholder
 * it could not fill, so the caller reads them in its own array.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 */
final class ExampleSetFillScope {

	/**
	 * The caller's gap list, by reference.
	 *
	 * @var array<int, string>
	 */
	public array $gaps;

	/**
	 * Constructor; binds the gap list by reference.
	 *
	 * @param array<string, mixed> $context See {@see ExampleSetValues::fill()}.
	 * @param array<int, string>   $gaps    Collects every placeholder that could not be filled.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function __construct(
		public readonly array $context,
		array &$gaps,
	) {
		$this->gaps = &$gaps;
	}//end __construct()
}//end class
