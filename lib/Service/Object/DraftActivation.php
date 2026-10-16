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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DraftActivationRefusedException;
use OCA\OpenRegister\Exception\DraftAlreadyLeftException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IUser;
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
class DraftActivation {

	/**
	 * Constructor.
	 *
	 * @param ObjectService       $objects   Reads and saves objects.
	 * @param ValidateObject      $validator The schema's full validator.
	 * @param FormPayloadFindings $findings  Turns a refusal into per-property findings.
	 * @param IEventDispatcher    $events    Announces the object that left draft.
	 * @param ITimeFactory        $time      The clock, for the moment of receipt.
	 * @param IL10N               $l10n      Translations.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly ValidateObject $validator,
		private readonly FormPayloadFindings $findings,
		private readonly IEventDispatcher $events,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Make the owner's draft definitive: status `active`, same id, fully valid.
	 *
	 * @param Register             $register The draft's register.
	 * @param Schema               $schema   The draft's schema.
	 * @param string               $draftId  The draft's uuid.
	 * @param DraftOwner           $owner    The asserted owner (decision 195).
	 * @param array<string, mixed> $changes  Last property values, laid over the stored draft before it is judged.
	 *
	 * @return ObjectEntity The object, now `active`.
	 *
	 * @throws DraftActivationRefusedException 404 unknown, 409 not a draft, 403 not the owner's, 422 not valid in full.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function activate(Register $register, Schema $schema, string $draftId, DraftOwner $owner, array $changes = []): ObjectEntity {
		$draft = $this->ownDraft(register: $register, schema: $schema, draftId: $draftId, owner: $owner);
		$uuid = (string)$draft->getUuid();

		unset($changes['@self'], $changes['id']);
		// A stored draft holds null for every property nobody filled in (a
		// table has a column for each), so a stored null is "not supplied":
		// judged as null it reads as a wrong type, also for a property the
		// server fills. What the caller sends now is judged as sent.
		$stored = array_filter(DraftFinder::dataOf(object: $draft), static fn (mixed $value): bool => $value !== null);
		$object = array_merge($stored, $changes);
		$this->validateInFull(schema: $schema, object: $object);

		$object['@self'] = ['status' => ObjectEntity::STATUS_ACTIVE];
		DraftStatusPolicy::allowPromotion(uuid: $uuid);
		try {
			// RBAC stays on for a user. A scope-value owner has no Nextcloud
			// user to judge: ownDraft() above was the authorisation, exactly
			// as DraftFinder::own() is on the form path.
			$saved = $this->objects->saveObject(
				object: $object,
				register: $register,
				schema: $schema,
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

		$receivedAt = DateTimeImmutable::createFromMutable($this->time->getDateTime());
		// Creation side effects run now, once (decision 227): the created event, then the receipt.
		(new ObjectEventGate(events: $this->events))->leftDraft(object: $saved, receivedAt: $receivedAt);

		return $saved;
	}//end activate()

	/**
	 * Make a signed-in user's own draft definitive.
	 *
	 * The owner is built here, from the user the caller's session holds, so
	 * no controller ever writes an owner assertion of its own.
	 *
	 * @param Register             $register The draft's register.
	 * @param Schema               $schema   The draft's schema.
	 * @param string               $draftId  The draft's uuid.
	 * @param IUser                $user     The acting user.
	 * @param array<string, mixed> $changes  Last property values, laid over the stored draft before it is judged.
	 *
	 * @return ObjectEntity The object, now `active`.
	 *
	 * @throws DraftActivationRefusedException 404 unknown, 409 not a draft, 403 not the owner's, 422 not valid in full.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function activateAsUser(Register $register, Schema $schema, string $draftId, IUser $user, array $changes = []): ObjectEntity {
		return $this->activate(register: $register, schema: $schema, draftId: $draftId, owner: DraftOwner::ofUser(user: $user), changes: $changes);
	}//end activateAsUser()

	/**
	 * The stored draft, when it is a draft and the asserted owner's.
	 *
	 * @param Register   $register The register.
	 * @param Schema     $schema   The schema.
	 * @param string     $draftId  The draft's uuid.
	 * @param DraftOwner $owner    The asserted owner.
	 *
	 * @return ObjectEntity The draft.
	 *
	 * @throws DraftActivationRefusedException 404, 409 or 403.
	 */
	private function ownDraft(Register $register, Schema $schema, string $draftId, DraftOwner $owner): ObjectEntity {
		$draft = null;
		if ($draftId !== '') {
			try {
				// RBAC off for the read only: the owner comparison below is
				// stricter than any read rule, and a scope-value owner has
				// no session to read with.
				$draft = $this->objects->find(
					id: $draftId,
					register: $register,
					schema: $schema,
					_rbac: false,
					_multitenancy: false,
					_render: false,
					_audit: false
				);
			} catch (Throwable) {
				$draft = null;
			}
		}

		if ($draft === null) {
			throw new DraftActivationRefusedException(
				message: $this->l10n->t('This draft does not exist.'),
				reason: DraftActivationRefusedException::NOT_FOUND
			);
		}

		if ($draft->isDraft() === false) {
			throw new DraftActivationRefusedException(
				message: $this->l10n->t('This is not a draft, so there is nothing to make definitive.'),
				reason: DraftActivationRefusedException::NOT_A_DRAFT
			);
		}

		if ($owner->owns(object: $draft) === false) {
			throw new DraftActivationRefusedException(
				message: $this->l10n->t('Only the person who started this draft can make it definitive.'),
				reason: DraftActivationRefusedException::NOT_THE_OWNER
			);
		}

		return $draft;
	}//end ownDraft()

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
			$result = $this->validator->validateObject(object: $object, schema: $schema, notSupplied: $excused);
			$findings = $this->findings->fromResult(result: $result);
		} catch (CustomValidationException $exception) {
			$findings = $this->findings->fromThrowable(exception: $exception);
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

		if ($this->findings->isPayloadRefusal(exception: $exception) === true) {
			return $this->invalid(findings: $this->findings->fromThrowable(exception: $exception));
		}

		if ($exception instanceof NotAuthorizedException) {
			return new DraftActivationRefusedException(
				message: $this->l10n->t('You may not save this object.'),
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
			message: $this->l10n->t('This is not a draft, so there is nothing to make definitive.'),
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
			message: $this->l10n->t('This draft is not complete yet. Check the fields marked below.'),
			reason: DraftActivationRefusedException::INVALID,
			findings: $findings
		);
	}//end invalid()
}//end class
