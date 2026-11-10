<?php

/**
 * A form submit creates its destination object in one request.
 *
 * Decision 179 (Ruben, 10 October 2026): "We dont intake to an intake, we
 * intake into a case, or ticket or something else." ADR-117 decisions 3 and
 * 4. Nothing sits between the submit and the object: the payload is checked
 * by the destination schema's full validator, whatever its hard-validation
 * flag, the object is created under the subject's RBAC, and the answer names
 * the object a person will work on, with every property its schema marks
 * `x-openregister.confirmation: true`, read after every create listener ran.
 *
 * Several writes (a journey step's `writes[]`) commit all or none: every
 * write is validated before the first is made, and when a later write is
 * still refused (a uniqueness rule only the store can check, an outage),
 * the earlier writes of that submit are deleted before the answer returns.
 * OpenRegister has no cross-object transaction, so compensation is the
 * mechanism; this service makes it a property of the submit, not of every
 * caller. Upload tokens are claimed only after the last write succeeded, so
 * a refused submit leaves the resident's files ready for the retry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DraftAlreadyLeftException;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\DraftFinder;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use Throwable;

/**
 * Validate all, write in order, compensate on a late refusal, answer with the real record.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The submit IS the meeting point of
 * mapping, validation, save, delete, uploads and idempotency; each collaborator is one
 * of those steps and none is reached any other way.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is the submit contract:
 * map, validate all, write in order, compensate, answer. Split, the all-or-none rule would
 * live in two files that must agree on what a plan is.
 * @SuppressWarnings(PHPMD.TooManyMethods) One private method per step of the submit (map, validate,
 * write, compensate, answer, idempotency, uploads); the steps are the contract, not incidental helpers.
 * @SuppressWarnings(PHPMD.StaticAccess) FormDestinationValidator::marker() and
 * markedProperties() are pure reads of a schema property's `x-openregister` block;
 * DraftStatusPolicy's promotion allowance is request-scoped state shared with the save path.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
class FormSubmitService implements FormSubmitServiceInterface {

	/**
	 * Stands in for an earlier write's id while every write is validated before the first.
	 *
	 * @var string
	 */
	public const PENDING_ID = '00000000-0000-4000-8000-000000000000';

	/**
	 * Validation mode: every rule.
	 *
	 * @var string
	 */
	private const MODE_FULL = 'full';

	/**
	 * Validation mode for a draft: every rule except `required` (decision 180).
	 *
	 * @var string
	 */
	private const MODE_DRAFT = 'draft';

	/**
	 * Finds an owner's drafts.
	 *
	 * @var DraftFinder
	 */
	private readonly DraftFinder $drafts;

	/**
	 * Maps a payload onto the object a write stores.
	 *
	 * @var FormPayloadMapper
	 */
	private readonly FormPayloadMapper $payloads;

	/**
	 * Resolves references from one write to an earlier one.
	 *
	 * @var FormWriteReferences
	 */
	private readonly FormWriteReferences $references;

	/**
	 * Undoes earlier writes of a refused submit.
	 *
	 * @var FormWriteCompensation
	 */
	private readonly FormWriteCompensation $compensation;

	/**
	 * Builds the refusals a submit answers with.
	 *
	 * @var FormSubmitRefusals
	 */
	private readonly FormSubmitRefusals $refusals;

	/**
	 * Constructor.
	 *
	 * @param FormSubmitDestinations $destinations The register and schema lookups, the validator and the object write path.
	 * @param FormSubmitKeeping $keeping The refusal findings, the idempotency keys and the upload tokens.
	 * @param FormSubmitPlatform $platform Translations, the log, the receipt event and the clock.
	 */
	public function __construct(
		private readonly FormSubmitDestinations $destinations,
		private readonly FormSubmitKeeping $keeping,
		private readonly FormSubmitPlatform $platform,
	) {
		$this->drafts       = new DraftFinder(objects: $destinations->objects);
		$this->payloads     = new FormPayloadMapper(uploads: $keeping->uploads);
		$this->references   = new FormWriteReferences(l10n: $platform->l10n);
		$this->compensation = new FormWriteCompensation(objects: $destinations->objects, logger: $platform->logger);
		$this->refusals     = new FormSubmitRefusals(findings: $keeping->findings, l10n: $platform->l10n, logger: $platform->logger);

	}//end __construct()

	/**
	 * Submit one payload into one or several destinations, all or none.
	 *
	 * Each write is `{ register, schema, mapping?, as? }`. A fixed value
	 * `{ "$write": "<as>" }` is the id of an earlier write of this submit.
	 * The answer's `reference`, `id`, `receivedAt` and `confirmation` are
	 * the first write's; `objects` lists every write.
	 *
	 * @param FormSubmission $submission     The writes, the payload and who submits (scope, draft, owner).
	 * @param string|null    $idempotencyKey The Idempotency-Key, when sent.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation, objects.
	 *
	 * @throws FormSubmitRefusedException 422 refused payload, 403 not allowed, 404 no destination, 409 key taken or in use, 503 try again later.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-writing-several-objects-must-commit-all-or-none
	 */
	public function submit(FormSubmission $submission, ?string $idempotencyKey = null): array {
		return $this->submitKeyed(submission: $submission, key: trim((string)$idempotencyKey));
	}//end submit()

	/**
	 * Save the subject's draft of a destination: the destination object itself, in status `draft`.
	 *
	 * The owner first, then plan, draft validation, the write and the upload claims.
	 *
	 * @param FormSubmission $submission The one write, what was filled in so far, and the submitter's draft and owner.
	 *
	 * @return array{id: string, status: string} The draft.
	 *
	 * @throws FormSubmitRefusedException 401 anonymous, 404 unknown draft, 422 type-invalid, 503.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
	 */
	public function saveDraft(FormSubmission $submission): array {
		$owner = DraftOwner::resolve(subject: $submission->subject, owner: $submission->owner);
		if ($owner === null) {
			throw new FormSubmitRefusedException(message: $this->platform->l10n->t('Please sign in to save this form and carry on later.'), status: 401);
		}

		$plans = $this->plan(submission: $submission);
		if ($submission->draftId !== null) {
			$plans = $this->withDraftData(plans: $plans, draft: new OwnedDraft(owner: $owner, draftId: $submission->draftId));
		}

		$this->validateAll(plans: $plans, mode: self::MODE_DRAFT);
		$context = new FormWriteContext(subject: $owner->user(), status: ObjectEntity::STATUS_DRAFT, uuid: $submission->draftId, owner: $owner);
		$uuid = (string)$this->writeAll(plans: $plans, context: $context)[0]['object']->getUuid();

		// The files now sit on the draft: the tokens are spent, as after a submit,
		// so they cannot be redeemed again for another object or a later save.
		$this->keeping->uploads->claimPlanned(plans: $plans, draftId: $uuid);

		return ['id' => $uuid, 'status' => ObjectEntity::STATUS_DRAFT];
	}//end saveDraft()

	/**
	 * Submit under an Idempotency-Key: a remembered answer is repeated, a fresh one remembered.
	 *
	 * The key is reserved for the length of the submit: a parallel retry with
	 * the same key is refused (409) instead of writing a second object.
	 *
	 * @param FormSubmission $submission The submit.
	 * @param string         $key        The trimmed key; empty submits without idempotency handling.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @throws FormSubmitRefusedException 422, 403, 404, 409 or 503.
	 */
	private function submitKeyed(FormSubmission $submission, string $key): array {
		if ($key === '') {
			return $this->submitOnce(submission: $submission);
		}

		$keyScope = $submission->keyScope();
		$this->keeping->keys->reserve(scope: $keyScope, key: $key);
		try {
			return $this->rememberedOrSubmitted(submission: $submission, key: $key);
		} finally {
			$this->keeping->keys->release(scope: $keyScope, key: $key);
		}
	}//end submitKeyed()

	/**
	 * The answer remembered under the key, or the answer of a fresh submit, then remembered.
	 *
	 * The key belongs to the submission's key scope; the binding (who sent what)
	 * keeps the answer from being handed to somebody else.
	 *
	 * @param FormSubmission $submission The submit.
	 * @param string         $key        The key.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @throws FormSubmitRefusedException 422, 403, 404, 409 or 503.
	 */
	private function rememberedOrSubmitted(FormSubmission $submission, string $key): array {
		$keyScope = $submission->keyScope();
		$binding = $this->keeping->keys->bindingFor(subject: $submission->submitter(), payload: $submission->payload);
		$remembered = $this->keeping->keys->answerFor(request: new IdempotentRequest(scope: $keyScope, key: $key, binding: $binding));
		if ($remembered !== null) {
			/*
			 * @var array<string, mixed> $remembered
			 */
			return $remembered;
		}

		$answer = $this->submitOnce(submission: $submission);
		$this->keeping->keys->keep(request: new IdempotentRequest(scope: $keyScope, key: $key, binding: $binding), response: $answer);

		return $answer;
	}//end rememberedOrSubmitted()

	/**
	 * Write one submit and answer it, without any idempotency handling.
	 *
	 * @param FormSubmission $submission The submit.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @throws FormSubmitRefusedException 422, 403, 404 or 503.
	 */
	private function submitOnce(FormSubmission $submission): array {
		$plans = $this->plan(submission: $submission);
		[$created, $receivedAt] = $this->writeOrPromote(plans: $plans, submission: $submission);

		$this->keeping->uploads->claimPlanned(plans: $plans, draftId: $submission->draftId);

		return $this->answer(created: $created, receivedAt: $receivedAt);
	}//end submitOnce()

	/**
	 * Validate and write a fresh submit, or promote a draft.
	 *
	 * @param array<int, array<string, mixed>> $plans      The plans.
	 * @param FormSubmission                   $submission The submit.
	 *
	 * @return array{0: array<int, array{plan: array<string, mixed>, object: ObjectEntity}>, 1: DateTimeImmutable|null} Writes, receipt.
	 *
	 * @throws FormSubmitRefusedException 422, 403, 404 or 503.
	 */
	private function writeOrPromote(array $plans, FormSubmission $submission): array {
		if ($submission->draftId !== null) {
			$owner = DraftOwner::resolve(subject: $submission->subject, owner: $submission->owner);

			return $this->promoteDraft(plans: $plans, draft: new OwnedDraft(owner: $owner, draftId: $submission->draftId));
		}

		$this->validateAll(plans: $plans);

		return [$this->writeAll(plans: $plans, context: new FormWriteContext(subject: $submission->subject)), null];
	}//end writeOrPromote()

	/**
	 * Leave draft: full validation of the draft plus the payload, then one allowed promotion, then receipt.
	 *
	 * @param array<int, array<string, mixed>> $plans The plans (exactly one).
	 * @param OwnedDraft                       $draft The draft and its owner; a null owner finds no draft.
	 *
	 * @return array{0: array<int, array{plan: array<string, mixed>, object: ObjectEntity}>, 1: DateTimeImmutable} The write and the moment of receipt.
	 *
	 * @throws FormSubmitRefusedException 404 unknown draft, 422 incomplete or several writes, 403, 503.
	 */
	private function promoteDraft(array $plans, OwnedDraft $draft): array {
		if (count($plans) !== 1) {
			$message = $this->platform->l10n->t('A draft is one object; a form that writes several cannot leave draft in one step.');
			throw new FormSubmitRefusedException(message: $message, status: 422);
		}

		$plans = $this->withDraftData(plans: $plans, draft: $draft);
		$this->validateAll(plans: $plans);
		$created = $this->writePromotion(plans: $plans, draft: $draft);

		$receivedAt = DateTimeImmutable::createFromMutable($this->platform->time->getDateTime());
		// Creation side effects run now, once (decision 227): the created event, then the receipt.
		(new ObjectEventGate(events: $this->platform->events))->leftDraft(object: $created[0]['object'], receivedAt: $receivedAt);

		return [$created, $receivedAt];
	}//end promoteDraft()

	/**
	 * Write the one allowed promotion of a draft to active.
	 *
	 * Two requests on one draft can both pass the "still a draft" read. The
	 * save that did not use the allowance found it already sent: it answers
	 * as for a draft that is gone and announces nothing.
	 *
	 * @param array<int, array<string, mixed>> $plans The plans (exactly one).
	 * @param OwnedDraft                       $draft The draft and its owner.
	 *
	 * @return array<int, array{plan: array<string, mixed>, object: ObjectEntity}> The write.
	 *
	 * @throws FormSubmitRefusedException 404 when the promotion was not used, 422, 403 or 503.
	 */
	private function writePromotion(array $plans, OwnedDraft $draft): array {
		$owner   = $draft->owner;
		$context = new FormWriteContext(subject: $owner?->user(), status: ObjectEntity::STATUS_ACTIVE, uuid: $draft->draftId, owner: $owner);
		DraftStatusPolicy::allowPromotion(uuid: $draft->draftId);
		try {
			$created = $this->writeAll(plans: $plans, context: $context);
			if (DraftStatusPolicy::promotionWasUsed(uuid: $draft->draftId) === false) {
				throw $this->refusals->draftGone();
			}
		} finally {
			DraftStatusPolicy::resetPromotions();
		}

		return $created;
	}//end writePromotion()

	/**
	 * The plans with the stored data of the owner's draft under the payload's own values.
	 *
	 * @param array<int, array<string, mixed>> $plans The plans; the first is the draft's.
	 * @param OwnedDraft                       $draft The draft; only its owner's own draft is found.
	 *
	 * @return array<int, array<string, mixed>> The plans.
	 *
	 * @throws FormSubmitRefusedException 404.
	 */
	private function withDraftData(array $plans, OwnedDraft $draft): array {
		$plans[0]['object'] = array_merge($this->draftData(plan: $plans[0], draft: $draft), $plans[0]['object']);

		return $plans;
	}//end withDraftData()

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
	public function isOwnerOfDraft(array $destination, FormSubmitter $submitter): bool {
		$pair = $this->destinationOrNull(destination: $destination);
		if ($pair === null || $submitter->draftId === null) {
			return false;
		}

		$owner = DraftOwner::resolve(subject: $submitter->subject, owner: $submitter->owner);

		return $this->drafts->own(register: $pair[0], schema: $pair[1], draftId: $submitter->draftId, owner: $owner) !== null;
	}//end isOwnerOfDraft()

	/**
	 * The stored data of the subject's draft, or 404 when it is not theirs or not a draft.
	 *
	 * Only the subject's own draft is found (decision 180); anything else
	 * answers the same 404, so a draft id is not an oracle.
	 *
	 * @param array<string, mixed> $plan  The write.
	 * @param OwnedDraft           $draft The draft; only its owner's own draft is found.
	 *
	 * @return array<string, mixed> The draft's data, without its metadata.
	 *
	 * @throws FormSubmitRefusedException 404.
	 */
	private function draftData(array $plan, OwnedDraft $draft): array {
		$stored = $this->drafts->own(register: $plan['register'], schema: $plan['schema'], draftId: $draft->draftId, owner: $draft->owner);
		if ($stored === null) {
			throw $this->refusals->draftGone();
		}

		return DraftFinder::dataOf(object: $stored);
	}//end draftData()

	/**
	 * Read the owner's draft by id: its data, for resuming a form.
	 *
	 * Anything but the owner's own draft answers the same 404 an unknown id
	 * gets (decisions 180 and 195), so a draft id is not an oracle.
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination The destination pair.
	 * @param FormSubmitter                           $submitter   The draft's uuid, the signed-in subject, the asserted owner.
	 *
	 * @return array{id: string, status: string, data: array<string, mixed>, updatedAt: string} The draft.
	 *
	 * @throws FormSubmitRefusedException 404 no draft id, unknown or not the owner's draft, 404 unknown destination.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function readDraft(array $destination, FormSubmitter $submitter): array {
		$register = $this->register(reference: $destination['register'] ?? null);
		$schema = $this->schema(reference: $destination['schema'] ?? null);
		$owner = DraftOwner::resolve(subject: $submitter->subject, owner: $submitter->owner);
		$draft = $this->drafts->own(register: $register, schema: $schema, draftId: (string)$submitter->draftId, owner: $owner);
		if ($draft === null) {
			throw $this->refusals->draftGone();
		}

		return DraftFinder::answer(draft: $draft);
	}//end readDraft()

	/**
	 * Find the owner's newest draft in a destination, optionally one whose data holds given values.
	 *
	 * For resuming by account: the caller knows who and which form, not the
	 * draft id. `$match` narrows to drafts whose data holds each given value
	 * exactly, such as the fixed values a form writes (a case type).
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination The destination pair.
	 * @param FormSubmitter                           $submitter   The signed-in subject and the asserted owner (null takes the subject).
	 * @param array<string, mixed>                    $match       Values the draft's data must hold.
	 *
	 * @return array{id: string, status: string, data: array<string, mixed>, updatedAt: string}|null The draft, or null.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
	 */
	public function findDraft(array $destination, FormSubmitter $submitter, array $match = []): ?array {
		$owner = DraftOwner::resolve(subject: $submitter->subject, owner: $submitter->owner);
		$pair  = $this->destinationOrNull(destination: $destination);
		if ($owner === null || $pair === null) {
			return null;
		}

		return $this->answerOf(draft: $this->drafts->newest(register: $pair[0], schema: $pair[1], owner: $owner, match: $match));
	}//end findDraft()

	/**
	 * A draft's answer, or null for no draft.
	 *
	 * @param ObjectEntity|null $draft The draft.
	 *
	 * @return array{id: string, status: string, data: array<string, mixed>, updatedAt: string}|null The answer.
	 */
	private function answerOf(?ObjectEntity $draft): ?array {
		if ($draft === null) {
			return null;
		}

		return DraftFinder::answer(draft: $draft);
	}//end answerOf()

	/**
	 * A destination's register and schema, or null when either does not exist.
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination The destination pair.
	 *
	 * @return array{0: Register, 1: Schema}|null The pair.
	 */
	private function destinationOrNull(array $destination): ?array {
		try {
			return [$this->register(reference: $destination['register'] ?? null), $this->schema(reference: $destination['schema'] ?? null)];
		} catch (FormSubmitRefusedException) {
			return null;
		}
	}//end destinationOrNull()

	/**
	 * Resolve each write's destination and map the payload onto it.
	 *
	 * @param FormSubmission $submission The submit: its writes, payload, form id and draft.
	 *
	 * @return array<int, array<string, mixed>> The plans: as, named, register, schema, mapping, object, files, tokens.
	 *
	 * @throws FormSubmitRefusedException 404 when a destination does not exist; 422 for a bad upload token.
	 */
	private function plan(FormSubmission $submission): array {
		$plans = [];
		foreach (array_values($submission->writes) as $index => $write) {
			$name = [
				'as' => $this->writeNameOf(write: $write, index: $index),
				'named' => trim((string)($write['as'] ?? '')) !== '' && count($submission->writes) > 1,
			];
			$plans[] = array_merge($name, $this->planOne(write: $write, submission: $submission));
		}

		if ($plans === []) {
			throw new FormSubmitRefusedException(message: $this->platform->l10n->t('This form has no destination.'), status: 404);
		}

		return $plans;
	}//end plan()

	/**
	 * The plan of one write, apart from its name (`as`, `named`), which plan() sets from its position.
	 *
	 * @param array<string, mixed> $write      The write: `{ register, schema, mapping?, as? }`.
	 * @param FormSubmission       $submission The submit.
	 *
	 * @return array<string, mixed> The plan.
	 *
	 * @throws FormSubmitRefusedException 404 when the destination does not exist; 422 for a bad upload token.
	 */
	private function planOne(array $write, FormSubmission $submission): array {
		$mapping = $this->mappingOf(write: $write);
		[$object, $files, $tokens] = $this->payloads->mapPayload(mapping: $mapping, submission: $submission);
		$schema = $this->schema(reference: $write['schema'] ?? null);

		return [
			'register' => $this->register(reference: $write['register'] ?? null),
			'schema' => $schema,
			'mapping' => $mapping,
			'object' => (new NestedIdentityGuard())->strip(object: $object, schema: $schema),
			'files' => $files,
			'tokens' => $tokens,
		];
	}//end planOne()

	/**
	 * A write's name: its `as`, else `write<index>`.
	 *
	 * @param array<string, mixed> $write The write.
	 * @param int                  $index Its position.
	 *
	 * @return string The name.
	 */
	private function writeNameOf(array $write, int $index): string {
		$name = trim((string)($write['as'] ?? ''));
		if ($name === '') {
			return 'write' . $index;
		}

		return $name;
	}//end writeNameOf()

	/**
	 * A write's mapping, or null when it has none.
	 *
	 * @param array<string, mixed> $write The write.
	 *
	 * @return array<string, mixed>|null The mapping.
	 */
	private function mappingOf(array $write): ?array {
		if (is_array($write['mapping'] ?? null) === true) {
			return $write['mapping'];
		}

		return null;
	}//end mappingOf()

	/**
	 * Validate every write with its destination's full validator before the first write.
	 *
	 * @param array<int, array<string, mixed>> $plans The plans.
	 * @param string                           $mode  MODE_FULL, or MODE_DRAFT: every `required` is excused, nothing else.
	 *
	 * @return void
	 *
	 * @throws FormSubmitRefusedException 422 with every finding of every write.
	 */
	private function validateAll(array $plans, string $mode = self::MODE_FULL): void {
		$findings = [];
		foreach ($plans as $plan) {
			array_push($findings, ...$this->findingsOf(plan: $plan, mode: $mode));
		}

		if ($findings !== []) {
			throw $this->refusals->payloadRefused(findings: $findings);
		}
	}//end validateAll()

	/**
	 * The validation findings of one write.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param string               $mode MODE_FULL or MODE_DRAFT.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 */
	private function findingsOf(array $plan, string $mode): array {
		$write = $this->refusals->writeLabelOf(plan: $plan);
		$object = $this->references->withPlaceholders(object: $plan['object']);
		$excused = $this->excusalsOf(plan: array_merge($plan, ['object' => $object]), mode: $mode);
		try {
			$result = $this->destinations->validator->validateObject(object: $object, schema: $plan['schema'], notSupplied: $excused);

			return $this->keeping->findings->fromResult(result: $result, write: $write);
		} catch (CustomValidationException $exception) {
			return $this->keeping->findings->fromThrowable(exception: $exception, write: $write);
		}
	}//end findingsOf()

	/**
	 * The properties a write's validation excuses as not supplied.
	 *
	 * An upload token moved its file out of the object (mapPayload), so the
	 * property is absent from the validated object although it was supplied.
	 *
	 * @param array<string, mixed> $plan The plan, its object as validated.
	 * @param string               $mode MODE_FULL or MODE_DRAFT.
	 *
	 * @return array<string, string> Property => why it is excused.
	 */
	private function excusalsOf(array $plan, string $mode): array {
		$policy = new DraftStatusPolicy();
		$excused = $policy->serverFilledExcusals(schema: $plan['schema'], object: $plan['object']);
		if ($mode === self::MODE_DRAFT) {
			$excused = array_merge($policy->requiredExcusals(schema: $plan['schema']), $excused);
		}

		foreach (array_keys($plan['files']) as $uploaded) {
			$excused[(string)$uploaded] = 'uploaded';
		}

		return $excused;
	}//end excusalsOf()

	/**
	 * Write in order; on a refusal, delete what this submit already wrote, then refuse.
	 *
	 * @param array<int, array<string, mixed>> $plans   The plans.
	 * @param FormWriteContext                 $context Under whose authority, with which status and uuid.
	 *
	 * @return array<int, array{plan: array<string, mixed>, object: ObjectEntity}> The created objects, in order.
	 *
	 * @throws FormSubmitRefusedException 422, 403 or 503 after compensation.
	 */
	private function writeAll(array $plans, FormWriteContext $context): array {
		$created = [];
		$ids = [];
		foreach ($plans as $plan) {
			// Earlier writes' ids filled in; 422 when a reference names no earlier write.
			$object = $this->references->withEarlierIds(object: $plan['object'], ids: $ids);
			$entity = $this->saveOrCompensate(plan: array_merge($plan, ['object' => $object]), context: $context, created: $created);
			$created[] = ['plan' => $plan, 'object' => $entity];
			$ids[$plan['as']] = (string)$entity->getUuid();
		}

		return $created;
	}//end writeAll()

	/**
	 * The object with the lifecycle status to store set, or unchanged without one.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string|null          $status The lifecycle status to store, or none.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function withStatus(array $object, ?string $status): array {
		if ($status !== null) {
			$object['@self'] = ['status' => $status];
		}

		return $object;
	}//end withStatus()

	/**
	 * Save one write; on a refusal, delete the earlier writes and answer with the refusal.
	 *
	 * @param array<string, mixed>                                                $plan    The plan, its object with earlier writes' ids filled in.
	 * @param FormWriteContext                                                    $context The authority.
	 * @param array<int, array{plan: array<string, mixed>, object: ObjectEntity}> $created The writes made so far.
	 *
	 * @return ObjectEntity The stored object.
	 *
	 * @throws FormSubmitRefusedException 422, 403 or 503 after compensation.
	 */
	private function saveOrCompensate(array $plan, FormWriteContext $context, array $created): ObjectEntity {
		try {
			return $this->save(plan: $plan, context: $context);
		} catch (Throwable $exception) {
			$this->compensation->compensate(created: $created, failure: new FormSubmitFailure(failedAt: $plan['as'], reason: $exception));
			throw $this->refusals->refusalFor(exception: $exception, plan: $plan);
		}
	}//end saveOrCompensate()

	/**
	 * Save one write under the context's authority, with the context's lifecycle status set.
	 *
	 * @param array<string, mixed> $plan    The plan, its object with earlier writes' ids filled in.
	 * @param FormWriteContext     $context The authority.
	 *
	 * @return ObjectEntity The stored object.
	 */
	private function save(array $plan, FormWriteContext $context): ObjectEntity {
		return $this->destinations->objects->saveObject(
			object: $this->withStatus(object: $plan['object'], status: $context->status),
			register: $plan['register'],
			schema: $plan['schema'],
			uuid: $context->uuid,
			_rbac: $context->rbac(),
			_multitenancy: true,
			uploadedFiles: $this->filesOf(plan: $plan),
			currentUser: $context->subject,
			_unowned: $context->unowned(),
			_owner: $context->explicitOwner()
		);
	}//end save()

	/**
	 * The uploaded files of a write, or null when it has none.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<string, array<string, mixed>>|null The files.
	 */
	private function filesOf(array $plan): ?array {
		if ($plan['files'] !== []) {
			return $plan['files'];
		}

		return null;
	}//end filesOf()

	/**
	 * The answer: the first write's reference, id, received moment and confirmation; every write listed.
	 *
	 * @param array<int, array{plan: array<string, mixed>, object: ObjectEntity}> $created    The writes.
	 * @param DateTimeImmutable|null                                             $receivedAt When a draft left draft; null uses the created moment.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation, objects.
	 */
	private function answer(array $created, ?DateTimeImmutable $receivedAt = null): array {
		$written = array_map(fn (array $done): array => $this->written(done: $done), $created);
		$first = $written[0];

		return [
			'reference' => $first['entry']['reference'],
			'id' => $first['entry']['id'],
			'receivedAt' => $this->receivedAtOf(entity: $first['entity'], receivedAt: $receivedAt),
			'confirmation' => $this->confirmationOf(schema: $first['schema'], data: $first['entity']->getObject()),
			'objects' => array_column($written, 'entry'),
		];
	}//end answer()

	/**
	 * One write as stored: re-read, with its answer entry.
	 *
	 * Re-read: a creating listener has run by now, and a created listener
	 * that saves again is visible only on the stored row.
	 *
	 * @param array{plan: array<string, mixed>, object: ObjectEntity} $done The write.
	 *
	 * @return array{entry: array<string, string>, entity: ObjectEntity, schema: Schema} The write as stored.
	 */
	private function written(array $done): array {
		$entity = ($this->destinations->objects->find(
			id: (string)$done['object']->getUuid(),
			register: $done['plan']['register'],
			schema: $done['plan']['schema'],
			_rbac: false,
			_multitenancy: false,
			_audit: false
		) ?? $done['object']);

		return ['entry' => $this->entryOf(plan: $done['plan'], entity: $entity), 'entity' => $entity, 'schema' => $done['plan']['schema']];
	}//end written()

	/**
	 * The answer's entry for one write.
	 *
	 * @param array<string, mixed> $plan   The write's plan.
	 * @param ObjectEntity         $entity The stored object.
	 *
	 * @return array<string, string> The entry: as, id, reference, register, schema.
	 */
	private function entryOf(array $plan, ObjectEntity $entity): array {
		return [
			'as' => $plan['as'],
			'id' => (string)$entity->getUuid(),
			'reference' => $this->referenceOf(schema: $plan['schema'], entity: $entity),
			'register' => $plan['register']->getSlug(),
			'schema' => $plan['schema']->getSlug(),
		];
	}//end entryOf()

	/**
	 * The properties of the stored object its schema marks for the confirmation.
	 *
	 * @param Schema               $schema The schema.
	 * @param array<string, mixed> $data   The stored object.
	 *
	 * @return array<string, mixed> Property => value.
	 */
	private function confirmationOf(Schema $schema, array $data): array {
		$confirmation = [];
		foreach (FormDestinationValidator::markedProperties(schema: $schema, name: FormDestinationValidator::MARKER_CONFIRMATION) as $property) {
			if (array_key_exists($property, $data) === true) {
				$confirmation[$property] = $data[$property];
			}
		}

		return $confirmation;
	}//end confirmationOf()

	/**
	 * The moment of receipt: when a draft left draft, else when the object was created.
	 *
	 * @param ObjectEntity           $entity     The stored object.
	 * @param DateTimeImmutable|null $receivedAt When a draft left draft, or null.
	 *
	 * @return string|null The moment, as ATOM.
	 */
	private function receivedAtOf(ObjectEntity $entity, ?DateTimeImmutable $receivedAt): ?string {
		if ($receivedAt !== null) {
			return $receivedAt->format(DateTimeInterface::ATOM);
		}

		$created = $entity->getCreated();
		if ($created instanceof DateTimeInterface) {
			return $created->format(DateTimeInterface::ATOM);
		}

		return null;
	}//end receivedAtOf()

	/**
	 * The human-readable reference: the property marked `reference`, else the uuid.
	 *
	 * @param Schema       $schema The schema.
	 * @param ObjectEntity $entity The entity; its stored object holds the reference.
	 *
	 * @return string The reference.
	 */
	private function referenceOf(Schema $schema, ObjectEntity $entity): string {
		$data = $entity->getObject();
		foreach (FormDestinationValidator::markedProperties(schema: $schema, name: FormDestinationValidator::MARKER_REFERENCE) as $property) {
			if (is_scalar($data[$property] ?? null) === true && (string)$data[$property] !== '') {
				return (string)$data[$property];
			}
		}

		return (string)$entity->getUuid();
	}//end referenceOf()


	/**
	 * A destination's register.
	 *
	 * @param mixed $reference Id, uuid or slug.
	 *
	 * @return Register The register.
	 *
	 * @throws FormSubmitRefusedException 404 when it does not exist.
	 */
	private function register(mixed $reference): Register {
		if ($reference instanceof Register) {
			return $reference;
		}

		try {
			// Multitenancy and RBAC off: this resolves WHERE the form writes,
			// fixed by its author. Whether the subject may write there is the
			// save's question, asked under RBAC.
			return $this->destinations->registers->find((string)$reference, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			throw new FormSubmitRefusedException(message: $this->platform->l10n->t('The place this form sends to does not exist.'), status: 404);
		}
	}//end register()

	/**
	 * A destination's schema.
	 *
	 * @param mixed $reference Id, uuid or slug.
	 *
	 * @return Schema The schema.
	 *
	 * @throws FormSubmitRefusedException 404 when it does not exist.
	 */
	private function schema(mixed $reference): Schema {
		if ($reference instanceof Schema) {
			return $reference;
		}

		try {
			return $this->destinations->schemas->find((string)$reference, _multitenancy: false);
		} catch (Throwable) {
			throw new FormSubmitRefusedException(message: $this->platform->l10n->t('The place this form sends to does not exist.'), status: 404);
		}
	}//end schema()
}//end class
