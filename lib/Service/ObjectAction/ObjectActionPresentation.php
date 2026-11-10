<?php

/**
 * What a declared action shows and when it is offered.
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
 * What a declared action shows and when it is offered.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final readonly class ObjectActionPresentation {

	/**
	 * Describe it.
	 *
	 * @param ObjectActionIdentity $identity The key, label, description and icon.
	 * @param ObjectActionOffer    $offer    The surfaces, inputs and precondition.
	 */
	public function __construct(
		public ObjectActionIdentity $identity,
		public ObjectActionOffer $offer,
	) {
	}//end __construct()
}//end class
