<?php

/**
 * Where and when a declared action is offered, and what it asks for.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * The surfaces, inputs and precondition of one declared action.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final class ObjectActionOffer {

	/**
	 * Constructor.
	 *
	 * @param list<string>              $surfaces    Where a host offers it.
	 * @param array<string, mixed>|null $inputs      The input JSON Schema, or null for none.
	 * @param mixed                     $when        The JSONLogic precondition, or null.
	 * @param mixed                     $whenMessage The reason given when `when` does not hold.
	 */
	public function __construct(
		public readonly array $surfaces,
		public readonly ?array $inputs,
		public readonly mixed $when,
		public readonly mixed $whenMessage,
	) {
	}//end __construct()
}//end class
