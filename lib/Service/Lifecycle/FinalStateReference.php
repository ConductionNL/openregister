<?php

/**
 * FinalStateReference: one reference-form `final` lookup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Which row says whether a lifecycle value is an end, and which property on it says so.
 *
 * Built by {@see LifecycleFinalStateResolver} for one lookup and never leaves it.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class FinalStateReference {

	/**
	 * Constructor.
	 *
	 * @param string $from  The schema the referenced row belongs to.
	 * @param string $field The property on that row that says whether it ends.
	 * @param string $state The lifecycle value, which identifies the row.
	 */
	public function __construct(
		public readonly string $from,
		public readonly string $field,
		public readonly string $state,
	) {
	}//end __construct()
}//end class
