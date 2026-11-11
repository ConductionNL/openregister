<?php

/**
 * How a declared action may be run: confirmation, elevation, reversal.
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * The confirmation text, scoped elevations and reversibility of one declared action.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
final class ObjectActionRunPolicy {

	/**
	 * Constructor.
	 *
	 * @param mixed                                                        $confirm    The confirmation text, or null.
	 * @param list<array{register: string, schema: string, verbs: list<string>}> $elevate    The scoped elevations.
	 * @param bool                                                         $reversible Whether a bulk run may be reversed.
	 */
	public function __construct(
		public readonly mixed $confirm,
		public readonly array $elevate,
		public readonly bool $reversible,
	) {
	}//end __construct()

}//end class
