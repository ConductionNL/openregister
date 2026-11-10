<?php

/**
 * A draft's owner makes it definitive (decision 216).
 *
 * Ruben, 12 October 2026: "Extend OpenRegister". Until now a draft could
 * leave status `draft` only through a stored form's submit. An entry screen
 * that is not a stored form had no way to finish its own draft. This class is
 * that way, and it is the same path the form submit takes: read the owner's
 * draft, judge it in full against its schema, allow exactly one promotion in
 * DraftStatusPolicy, save it as `active` under the same id, and announce it
 * with ObjectActivatedEvent.
 *
 * Only the owner may do it (decision 195: the owner is a Nextcloud user or a
 * scope value such as `portal:<subjectRef>`). An administrator is not the
 * owner. For a user the save runs under RBAC, so an owner who lost the right
 * to write the schema is refused too. A scope value has no user for RBAC to
 * judge, so the asserted owner is the authorisation, as it is on the form
 * path; that owner is only ever asserted by in-process code.
 *
 * Nothing here names an app or a procedure (decision 182).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DraftActivationRefusedException;
use OCA\OpenRegister\Exception\DraftAlreadyLeftException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Throwable;

/**
 * Full validation, one allowed promotion, the same id, one event.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DraftStatusPolicy's promotion allowance is request-scoped
 * state shared with the save path, and DraftFinder::dataOf() is a pure reader.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One action that reads, validates, saves and
 * announces names those collaborators; split, the "validate before the one promotion" order
 * would live in two classes.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
class DraftActivation implements DraftActivationInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Reads and saves objects.
	 * @param DraftActivationChecks     $checks  The validator, its findings and the words of a refusal.
	 * @param DraftActivationSignals    $signals The event dispatcher and the clock.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly DraftActivationChecks $checks,
		private readonly DraftActivationSignals $signals,
	) {
	}//end __construct()

	/**
	 * Make the owner's draft definitive: status `active`, same id, fully valid.
	 *
	 * @param DraftPromotion $promotion The draft, its asserted owner, and the last changes laid over it.
	 *
	 * @return ObjectEntity The object, now `active`.
	 *
	 * @throws DraftActivationRefusedException 404 unknown, 409 not a draft, 403 not the owner's, 422 not valid in full.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function activate(DraftPromotion $promotion): ObjectEntity {
		$address = $promotion->address;
		$owner   = $promotion->owner;
		$changes = $promotion->changes;
		$draft   = $this->ownDraft(address: $address, owner: $owner);
		$uuid = (string)$draft->getUuid();

		unset($changes['@self'], $changes['id']);
		// A stored draft holds null for every property nobody filled in (a
		// table has a column for each), so a stored null is "not supplied":
		// judged as null it reads as a wrong type, also for a property the
		// server fills. What the caller sends now is judged as sent.
		$stored = array_filter(DraftFinder::dataOf(object: $draft), static fn (mixed $value): bool => $value !== null);
		$object = array_merge($stored, $changes);
		$this->validateInFull(schema: $address->schema, object: $object);

		$object['@self'] = ['status' => ObjectEntity::STATUS_ACTIVE];
		DraftStatusPolicy::allowPromotion(uuid: $uuid);
		try {
			// RBAC stays on for a user. A scope-value owner has no Nextcloud
			// user to judge: ownDraft() above was the authorisation, exactly
			// as DraftFinder::own() is on the form path.
			$saved = $this->objects->saveObject(
				object: $object,
				register: $address->register,
				schema: $address->schema,
				uuid: $uuid,
				_rbac: $owner->isScoped() === false,
				_multitenancy: true,
				currentUser: $owner->user()
			);
			// Two requests on one draft can both pass the "still a draft" read.
			// The save that did not use the allowance found it already sent:
			// it announces nothing, and answers as for an object that is no draft.
			if (DraftStatusPolicy::promotionWasUsed(uuid: $uuid) === false) {
				throw $this->alreadyLeft();
			}
		} catch (Throwable $exception) {
			throw $this->refusalFor(exception: $exception);
		} finally {
			DraftStatusPolicy::resetPromotions();
		}

		$receivedAt = DateTimeImmutable::createFromMutable($this->signals->time->getDateTime());
		// Creation side effects run now, once (decision 227): the created event, then the receipt.
		(new ObjectEventGate(events: $this->signals->events))->leftDraft(object: $saved, receivedAt: $receivedAt);

		return $saved;
	}//end activate()

	/**
	 * Make a signed-in user's own draft definitive.
	 *
	 * The owner is built here, from the user the caller's session holds, so
	 * no controller ever writes an owner assertion of its own.
	 *
	 * @param UserDraftActivation $activation The draft's register, schema and uuid, the acting user, and the last
	 *                                        property values laid over the stored draft before it is judged.
	 *
	 * @return ObjectEntity The object, now `active`.
	 *
	 * @throws DraftActivationRefusedException 404 unknown, 409 not a draft, 403 not the owner's, 422 not valid in full.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function activateAsUser(UserDraftActivation $activation): ObjectEntity {
		return $this->activate(
			promotion: new DraftPromotion(
				address: $activation->address,
				owner: DraftOwner::ofUser(user: $activation->user),
				changes: $activation->changes
			)
		);
	}//end activateAsUser()

	/**
	 * The stored draft, when it is a draft and the asserted owner's.
	 *
	 * @param DraftAddress $address The draft's register, schema and uuid.
	 * @param DraftOwner   $owner   The asserted owner.
	 *
	 * @return ObjectEntity The draft.
	 *
	 * @throws DraftActivationRefusedException 404, 409 or 403.
	 */
	private function ownDraft(DraftAddress $address, DraftOwner $owner): ObjectEntity {
		$draft = $this->readDraft(address: $address);

		if ($draft === null) {
			throw new DraftActivationRefusedException(
				message: $this->checks->l10n->t('This draft does not exist.'),
				reason: DraftActivationRefusedException::NOT_FOUND
			);
		}

		if ($draft->isDraft() === false) {
			throw new DraftActivationRefusedException(
				message: $this->checks->l10n->t('This is not a draft, so there is nothing to make definitive.'),
				reason: DraftActivationRefusedException::NOT_A_DRAFT
			);
		}

		if ($owner->owns(object: $draft) === false) {
			throw new DraftActivationRefusedException(
				message: $this->checks->l10n->t('Only the person who started this draft can make it definitive.'),
				reason: DraftActivationRefusedException::NOT_THE_OWNER
			);
		}

		return $draft;
	}//end ownDraft()

	/**
	 * Read a draft by id with RBAC off; an empty id or a failed read is no draft.
	 *
	 * RBAC is off for the read only: the owner comparison in ownDraft() is
	 * stricter than any read rule, and a scope-value owner has no session to
	 * read with.
	 *
	 * @param DraftAddress $address The draft's register, schema and uuid.
	 *
	 * @return ObjectEntity|null The object read
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	private function readDraft(DraftAddress $address): ?ObjectEntity {
		if ($address->draftId === '') {
			return null;
		}

		try {
			return $this->objects->find(
				id: $address->draftId,
				register: $address->register,
				schema: $address->schema,
				_rbac: false,
				_multitenancy: false,
				_render: false,
				_audit: false
			);
		} catch (Throwable) {
			return null;
		}
	}//end readDraft()

	/**
	 * Judge the object against every rule of its schema, whatever its hard-validation flag.
	 *
	 * @param Schema               $schema The schema.
	 * @param array<string, mixed> $object The draft with the last changes laid over it.
	 *
	 * @return void
	 *
	 * @throws DraftActivationRefusedException 422 with one finding per property.
	 */
	private function validateInFull(Schema $schema, array $object): void {
		$excused = (new DraftStatusPolicy())->serverFilledExcusals(schema: $schema, object: $object);
		try {
			$result = $this->checks->validator->validateObject(object: $object, schema: $schema, notSupplied: $excused);
			$findings = $this->checks->findings->fromResult(result: $result);
		} catch (CustomValidationException $exception) {
			$findings = $this->checks->findings->fromThrowable(exception: $exception);
		}

		if ($findings !== []) {
			throw $this->invalid(findings: $findings);
		}
	}//end validateInFull()

	/**
	 * The refusal a failed save answers with; anything that is not a refusal is rethrown as it came.
	 *
	 * @param Throwable $exception The save's exception.
	 *
	 * @return DraftActivationRefusedException 422 for what the draft holds, 403 for permission.
	 *
	 * @throws Throwable When the save failed for a reason that is not the caller's to fix.
	 */
	private function refusalFor(Throwable $exception): DraftActivationRefusedException {
		if ($exception instanceof DraftAlreadyLeftException) {
			return $this->alreadyLeft();
		}

		if ($this->checks->findings->isPayloadRefusal(exception: $exception) === true) {
			return $this->invalid(findings: $this->checks->findings->fromThrowable(exception: $exception));
		}

		if ($exception instanceof NotAuthorizedException) {
			return new DraftActivationRefusedException(
				message: $this->checks->l10n->t('You may not save this object.'),
				reason: DraftActivationRefusedException::NOT_THE_OWNER
			);
		}

		throw $exception;
	}//end refusalFor()

	/**
	 * The 409 refusal for a draft a parallel request already made definitive.
	 *
	 * @return DraftActivationRefusedException The refusal.
	 */
	private function alreadyLeft(): DraftActivationRefusedException {
		return new DraftActivationRefusedException(
			message: $this->checks->l10n->t('This is not a draft, so there is nothing to make definitive.'),
			reason: DraftActivationRefusedException::NOT_A_DRAFT
		);
	}//end alreadyLeft()

	/**
	 * The 422 refusal.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return DraftActivationRefusedException The refusal.
	 */
	private function invalid(array $findings): DraftActivationRefusedException {
		return new DraftActivationRefusedException(
			message: $this->checks->l10n->t('This draft is not complete yet. Check the fields marked below.'),
			reason: DraftActivationRefusedException::INVALID,
			findings: $findings
		);
	}//end invalid()
}//end class
