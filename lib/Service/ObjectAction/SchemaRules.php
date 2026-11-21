<?php

/**
 * The schema rules a declared action is checked against.
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
 * The schema lifecycle a `transition` must name and the authorization block an
 * `elevate` must be granted by, as a declaration's shape check reads them.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final readonly class SchemaRules {

	/**
	 * Describe the rules.
	 *
	 * @param array<string, mixed>      $lifecycle     The schema lifecycle; [] skips the transition check.
	 * @param array<string, mixed>|null $authorization The authorization block; null skips the elevate grant check.
	 */
	public function __construct(
		public array $lifecycle,
		public ?array $authorization,
	) {
	}//end __construct()
}//end class
