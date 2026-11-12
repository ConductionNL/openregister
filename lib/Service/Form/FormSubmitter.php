<?php

/**
 * OpenRegister Form Submitter
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IUser;

/**
 * Who a submit is by and where it lands: the subject, the form scope, the draft and its owner.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormSubmitter {

	/**
	 * Bundle the submitter.
	 *
	 * @param IUser|null      $subject The signed-in subject; null for anonymous.
	 * @param string          $scope   The form id the key and upload tokens belong to.
	 * @param string|null     $draftId The draft saved into or left, or null.
	 * @param DraftOwner|null $owner   The draft's asserted owner; null takes the subject.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function __construct(
		public ?IUser $subject = null,
		public string $scope = '',
		public ?string $draftId = null,
		public ?DraftOwner $owner = null,
	) {
	}//end __construct()
}//end class
