<?php

/**
 * OpenRegister Form Submission
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
 * One request to the submit path: what to write, what was filled in, and by whom.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormSubmission {

	/**
	 * Bundle the request.
	 *
	 * @param array<int, array<string, mixed>> $writes  The writes, in order: `{ register, schema, mapping?, as? }`.
	 * @param array<string, mixed>             $payload What the submitter filled in.
	 * @param IUser|null                       $subject The signed-in subject; null for anonymous.
	 * @param string                           $scope   The form id the key and upload tokens belong to.
	 * @param string|null                      $draftId The draft saved into or left, or null.
	 * @param DraftOwner|null                  $owner   The draft's asserted owner; null takes the subject.
	 */
	public function __construct(
		public array $writes,
		public array $payload,
		public ?IUser $subject = null,
		public string $scope = '',
		public ?string $draftId = null,
		public ?DraftOwner $owner = null,
	) {
	}//end __construct()

	/**
	 * Who the submit is by, as the idempotency binding reads it.
	 *
	 * @return string The user id, a scope value, or the anonymous marker.
	 */
	public function submitter(): string {
		if ($this->subject !== null) {
			return 'user:' . $this->subject->getUID();
		}

		if ($this->owner !== null && $this->owner->isScoped() === true) {
			return 'scope:' . $this->owner->value();
		}

		return 'anonymous';
	}//end submitter()

	/**
	 * The scope an idempotency key belongs to: the form, else the first destination.
	 *
	 * @return string The scope.
	 */
	public function keyScope(): string {
		if ($this->scope !== '') {
			return $this->scope;
		}

		$first = ($this->writes[0] ?? []);

		return 'destination:' . (string)json_encode([$first['register'] ?? null, $first['schema'] ?? null]);
	}//end keyScope()
}//end class
