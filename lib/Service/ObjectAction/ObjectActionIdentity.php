<?php

/**
 * What a declared action is called: its key and how a host names it.
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
 * The key, label, description and icon of one declared action.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final class ObjectActionIdentity {

	/**
	 * Constructor.
	 *
	 * @param string                      $key         The action key, the word authorization grants.
	 * @param string|array<string,string> $label       The label, a string or a locale map.
	 * @param string|null                 $description What the action does.
	 * @param string|null                 $icon        The icon name.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string|array $label,
		public readonly ?string $description,
		public readonly ?string $icon,
	) {
	}//end __construct()
}//end class
