<?php

/**
 * OpenRegister Example Set Kept Object
 *
 * An object an earlier run wrote and this run keeps: its declaration, the row as stored and the context its placeholders draw on now.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * A kept object whose references may be refreshed.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
 */
final class ExampleSetKeptObject {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object  The declared object.
	 * @param array<string, mixed> $row     The object as stored.
	 * @param array<string, mixed> $context The context its placeholders draw on.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $object,
		public readonly array $row,
		public readonly array $context,
	) {
	}//end __construct()
}//end class
