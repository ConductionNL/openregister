<?php

/**
 * FormSubmitServiceInterface: what callers outside the component use of FormSubmitService.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IUser;

/**
 * Submit forms into their destinations and keep the subject's drafts.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FormSubmitServiceInterface {

	/**
	 * Whether a draft of this destination exists and belongs to the subject.
	 *
	 * The owner check is explicit, on top of the read RBAC that already keeps
	 * drafts to their owner: an administrator's session bypasses RBAC, and an
	 * administrator still may not write into somebody else's draft by id.
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination The destination pair.
	 * @param string                                  $draftId     The draft's uuid.
	 * @param IUser|null                              $subject     The subject.
	 * @param DraftOwner|null                         $owner       The asserted owner; null takes the subject (decision 195).
	 *
	 * @return bool True when it is the subject's own draft.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function isOwnerOfDraft(array $destination, string $draftId, ?IUser $subject, ?DraftOwner $owner = null): bool;

	/**
	 * Save the subject's draft of a destination: the destination object itself, in status `draft`.
	 *
	 * Decision 180. The draft may miss required properties; every other rule
	 * holds, so a wrong type, format or enum value is refused. A draft is the
	 * signed-in subject's own; an anonymous visitor gets 401 (resuming an
	 * anonymous draft needs a resume token, question Q-openregister-F2).
	 * A caller may assert a scope-value owner instead, such as
	 * `portal:<subjectRef>` for a portal resident without an account
	 * (decision 195); only that owner then reads, saves or sends it.
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination The resolved destination pair.
	 * @param array<string, mixed>|null               $mapping     The form's mapping.
	 * @param array<string, mixed>                    $payload     What the subject filled in so far.
	 * @param IUser|null                              $subject     The signed-in subject.
	 * @param string|null                             $draftId     The draft to update; null starts one.
	 * @param string                                  $scope       The form id.
	 * @param DraftOwner|null                         $owner       The asserted owner; null takes the subject (decision 195).
	 *
	 * @return array{id: string, status: string} The draft.
	 *
	 * @throws FormSubmitRefusedException 401 anonymous, 404 unknown draft, 422 type-invalid, 503.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function saveDraft(
		array $destination,
		?array $mapping,
		array $payload,
		?IUser $subject,
		?string $draftId = null,
		string $scope = '',
		?DraftOwner $owner = null,
	): array;

	/**
	 * Submit one form into one destination.
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination    The resolved destination pair.
	 * @param array<string, mixed>|null               $mapping        The form's mapping; null takes the payload as the object.
	 * @param array<string, mixed>                    $payload        What the submitter filled in.
	 * @param IUser|null                              $subject        The signed-in subject; null for an anonymous submit.
	 * @param string|null                             $idempotencyKey The Idempotency-Key, when the caller sent one.
	 * @param string                                  $scope          The form id the key and upload tokens belong to.
	 * @param string|null                             $draftId        The subject's draft of this destination, to leave draft.
	 * @param DraftOwner|null                         $owner          The draft's asserted owner; null takes the subject (decision 195).
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation, objects.
	 *
	 * @throws FormSubmitRefusedException 422 refused payload, 403 not allowed, 404 no destination, 409 key taken or in use, 503 try again later.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function submit(
		array $destination,
		?array $mapping,
		array $payload,
		?IUser $subject = null,
		?string $idempotencyKey = null,
		string $scope = '',
		?string $draftId = null,
		?DraftOwner $owner = null,
	): array;

	/**
	 * Submit one payload into several destinations, all or none.
	 *
	 * Each write is `{ register, schema, mapping?, as? }`. A fixed value
	 * `{ "$write": "<as>" }` is the id of an earlier write of this submit.
	 * The answer's `reference`, `id`, `receivedAt` and `confirmation` are
	 * the first write's; `objects` lists every write.
	 *
	 * @param array<int, array<string, mixed>> $writes         The writes, in order.
	 * @param array<string, mixed>             $payload        What the submitter filled in.
	 * @param IUser|null                       $subject        The signed-in subject; null for anonymous.
	 * @param string|null                      $idempotencyKey The Idempotency-Key, when sent.
	 * @param string                           $scope          The form id the key and tokens belong to.
	 * @param string|null                      $draftId        The subject's draft to leave draft (one write only).
	 * @param DraftOwner|null                  $owner          The draft's asserted owner; null takes the subject (decision 195).
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation, objects.
	 *
	 * @throws FormSubmitRefusedException 422 refused payload, 403 not allowed, 404 no destination, 409 key taken or in use, 503 try again later.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-writing-several-objects-must-commit-all-or-none
	 */
	public function submitAll(
		array $writes,
		array $payload,
		?IUser $subject = null,
		?string $idempotencyKey = null,
		string $scope = '',
		?string $draftId = null,
		?DraftOwner $owner = null,
	): array;
}//end interface
