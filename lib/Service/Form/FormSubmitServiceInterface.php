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
	 * @param FormSubmitter                           $submitter   The draft's uuid, the subject, the asserted owner (decision 195).
	 *
	 * @return bool True when it is the subject's own draft; false without a draft id.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function isOwnerOfDraft(array $destination, FormSubmitter $submitter): bool;

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
	 * @param FormSubmission $submission The one write `{ register, schema, mapping }`, what the subject filled in so far,
	 *                                   and the submitter: subject, form id as scope, the draft to update (null starts one), owner.
	 *
	 * @return array{id: string, status: string} The draft.
	 *
	 * @throws FormSubmitRefusedException 401 anonymous, 404 unknown draft, 422 type-invalid, 503.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function saveDraft(FormSubmission $submission): array;

	/**
	 * Submit one payload into one or several destinations, all or none.
	 *
	 * Each write is `{ register, schema, mapping?, as? }`; a mapping of null
	 * takes the payload as the object. A fixed value `{ "$write": "<as>" }` is
	 * the id of an earlier write of this submit. The answer's `reference`,
	 * `id`, `receivedAt` and `confirmation` are the first write's; `objects`
	 * lists every write. The submitter carries the signed-in subject (null for
	 * anonymous), the form id the key and upload tokens belong to, the
	 * subject's draft to leave draft (one write only) and the draft's asserted
	 * owner (null takes the subject, decision 195).
	 *
	 * @param FormSubmission $submission     The writes, the payload and the submitter.
	 * @param string|null    $idempotencyKey The Idempotency-Key, when the caller sent one.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation, objects.
	 *
	 * @throws FormSubmitRefusedException 422 refused payload, 403 not allowed, 404 no destination, 409 key taken or in use, 503 try again later.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-writing-several-objects-must-commit-all-or-none
	 */
	public function submit(FormSubmission $submission, ?string $idempotencyKey = null): array;
	/**
	 * Read the owner's draft by id: its data, for resuming.
	 *
	 * Anything but the owner's own draft answers the same 404 an unknown id
	 * gets, so a draft id is not an oracle.
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination The destination pair.
	 * @param FormSubmitter                           $submitter   The draft's uuid, the signed-in subject, the asserted owner.
	 *
	 * @return array{id: string, status: string, data: array<string, mixed>, updatedAt: string} The draft.
	 *
	 * @throws FormSubmitRefusedException 404 no draft id, unknown or not the owner's draft, 404 unknown destination.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	public function readDraft(array $destination, FormSubmitter $submitter): array;

	/**
	 * Find the owner's newest draft in a destination, optionally one whose data holds given values.
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination The destination pair.
	 * @param FormSubmitter                           $submitter   The signed-in subject and the asserted owner (null takes the subject).
	 * @param array<string, mixed>                    $match       Values the draft's data must hold.
	 *
	 * @return array{id: string, status: string, data: array<string, mixed>, updatedAt: string}|null The draft, or null.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	public function findDraft(array $destination, FormSubmitter $submitter, array $match = []): ?array;
}//end interface
