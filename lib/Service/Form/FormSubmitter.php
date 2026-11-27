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

	/**
	 * This submitter for another scope and draft.
	 *
	 * With an owner reference the draft belongs to `<ownerScope>:<ownerReference>`; without one it keeps
	 * this submitter's owner, or none when $keepOwner is false (the subject then owns it).
	 *
	 * @param string      $scope          The form or journey id the key and upload tokens belong to.
	 * @param string|null $draftId        The draft saved into or left, or null.
	 * @param string      $ownerScope     The scope of an asserted owner.
	 * @param string|null $ownerReference The asserted owner's reference; null asserts none.
	 * @param bool        $keepOwner      Keep this submitter's owner when no reference is given.
	 *
	 * @return self The rescoped submitter.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	public function rescoped(
		string $scope,
		?string $draftId,
		string $ownerScope='',
		?string $ownerReference=null,
		bool $keepOwner=true
	): self {
		$owner = null;
		if ($keepOwner === true) {
			$owner = $this->owner;
		}

		if ($ownerReference !== null) {
			$owner = DraftOwner::scoped(scope: $ownerScope, reference: $ownerReference);
		}

		return new self(subject: $this->subject, scope: $scope, draftId: $draftId, owner: $owner);
	}//end rescoped()

	/**
	 * The owner value a scoped owner `<scope>:<reference>` is stored under.
	 *
	 * @param string $scope     The owner scope.
	 * @param string $reference The owner reference.
	 *
	 * @return string The stored owner value.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	public static function scopedOwnerValue(string $scope, string $reference): string {
		return DraftOwner::scoped(scope: $scope, reference: $reference)->value();
	}//end scopedOwnerValue()
}//end class
