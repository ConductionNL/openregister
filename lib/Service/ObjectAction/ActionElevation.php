<?php

/**
 * The elevation an action run opens.
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
 * The action that elevates, the person running it and the entries it may
 * write as the system, register and schema resolved to ids.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
final readonly class ActionElevation {

	/**
	 * Describe the elevation.
	 *
	 * @param string                                                             $action  The action key.
	 * @param string|null                                                        $person  The person running it.
	 * @param list<array{register: string, schema: string, verbs: list<string>}> $entries Register and schema resolved to ids.
	 */
	public function __construct(
		public string $action,
		public ?string $person,
		public array $entries,
	) {
	}//end __construct()
}//end class
