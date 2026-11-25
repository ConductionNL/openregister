<?php

/**
 * Who ran an anonymisation and under which decision.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The actor or pass that ran an anonymisation, and the decision that authorised it.
 *
 * The trail entry recording the act names both (REQ-AAO-006): an auditor asks
 * who removed the values and on whose say-so, and "the system" answers neither.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
 */
final class AnonymisationAuthority {

	/**
	 * Hold the authority.
	 *
	 * @param string $actorId    The user id, or the pass's id (e.g. `retention-pass`).
	 * @param string $actorName  The display name of the actor or pass.
	 * @param string $decision   The decision that authorised it, e.g. a destruction list and its answer.
	 */
	public function __construct(
		public readonly string $actorId,
		public readonly string $actorName,
		public readonly string $decision,
	) {
	}//end __construct()
}//end class
