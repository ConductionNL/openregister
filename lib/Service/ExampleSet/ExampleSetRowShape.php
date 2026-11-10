<?php

/**
 * OpenRegister Example Set Row Shape
 *
 * What one kind of example-set row may and must carry: the kind as a
 * refusal names it, the fields it knows and the fields it needs.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The kind of a row, the fields it knows and the fields it needs, as {@see ExampleSetFieldRules} checks them.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final class ExampleSetRowShape {

	/**
	 * Constructor.
	 *
	 * @param string                $row      The kind, with its article, as a refusal names it (`a deck card`).
	 * @param array<int, string>    $fields   The fields the kind knows.
	 * @param array<string, string> $required What each needed field holds, by field (`'title' => 'a title'`).
	 */
	public function __construct(
		public readonly string $row,
		public readonly array $fields = [],
		public readonly array $required = [],
	) {
	}//end __construct()
}//end class
