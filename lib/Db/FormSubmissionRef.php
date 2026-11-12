<?php

/**
 * FormSubmissionRef: one submission of one NC Forms form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The form and submission ids of a submission-level form link.
 *
 * @spec exclude ADR-019 Tier-2 integration link key; the link contract is owned by the integration-forms capability.
 */
final class FormSubmissionRef {

	/**
	 * Constructor.
	 *
	 * @param int $formId       The NC Forms form id.
	 * @param int $submissionId The submission id.
	 */
	public function __construct(
		public readonly int $formId,
		public readonly int $submissionId,
	) {
	}//end __construct()
}//end class
