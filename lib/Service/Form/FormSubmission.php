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
	 * The writes, in order: `{ register, schema, mapping?, as? }`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $writes;

	/**
	 * What the submitter filled in.
	 *
	 * @var array<string, mixed>
	 */
	public array $payload;

	/**
	 * The signed-in subject; null for anonymous.
	 *
	 * @var IUser|null
	 */
	public ?IUser $subject;

	/**
	 * The form id the key and upload tokens belong to.
	 *
	 * @var string
	 */
	public string $scope;

	/**
	 * The draft saved into or left, or null.
	 *
	 * @var string|null
	 */
	public ?string $draftId;

	/**
	 * The draft's asserted owner; null takes the subject.
	 *
	 * @var DraftOwner|null
	 */
	public ?DraftOwner $owner;

	/**
	 * Bundle the request.
	 *
	 * @param array<int, array<string, mixed>> $writes    The writes, in order: `{ register, schema, mapping?, as? }`.
	 * @param array<string, mixed>             $payload   What the submitter filled in.
	 * @param FormSubmitter                    $submitter Who submits it and where it lands.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function __construct(array $writes, array $payload, FormSubmitter $submitter=new FormSubmitter()) {
		$this->writes  = $writes;
		$this->payload = $payload;
		$this->subject = $submitter->subject;
		$this->scope   = $submitter->scope;
		$this->draftId = $submitter->draftId;
		$this->owner   = $submitter->owner;
	}//end __construct()

	/**
	 * Who the submit is by, as the idempotency binding reads it.
	 *
	 * @return string The user id, a scope value, or the anonymous marker.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
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
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function keyScope(): string {
		if ($this->scope !== '') {
			return $this->scope;
		}

		$first = ($this->writes[0] ?? []);

		return 'destination:' . (string)json_encode([$first['register'] ?? null, $first['schema'] ?? null]);
	}//end keyScope()
}//end class
