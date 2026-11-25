<?php

/**
 * A followed invitation link: the link and the invitation it opens.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survey
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survey;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkInterface;

/**
 * The link that was followed and the invitation it opens.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */
final class SurveyAnswerVisit {

	/**
	 * Constructor.
	 *
	 * @param AccessLinkInterface $link       The live link.
	 * @param ObjectEntity        $invitation The invitation object it opens.
	 */
	public function __construct(
		public readonly AccessLinkInterface $link,
		public readonly ObjectEntity $invitation,
	) {
	}//end __construct()
}//end class
