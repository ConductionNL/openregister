<?php

/**
 * Moving an object to another register and schema without changing who it is.
 *
 * 🔴 A MOVE IS A METADATA WRITE, NEVER A COPY (D-1). The magic tables are per
 * schema, so the row goes into the target's table and comes out of the
 * source's, in that order. The uuid is the primary identity in both and every
 * side table is keyed on it: the audit trail, the versions, the files, the
 * notes, the watchers, the favourites, the presence and the timers are all
 * already pointing at the right object and none of them is touched. A copy
 * would mint a second uuid and orphan every one of them, which is exactly what
 * "close it and refile it" does today.
 *
 * 🔴 THE ORDER IS WRITE-THEN-REMOVE, AND IT IS NOT ARBITRARY. Removing first
 * and failing to write loses the object; writing first and failing to remove
 * leaves it readable at both addresses, which is visible, reversible and
 * reported. Neither is good; only one of them is recoverable.
 *
 * 🔴 THE NUMBER IS FROZEN BECAUSE IT IS DATA (D-2). `x-openregister-generated`
 * values live in the object's data and `generated-identifier` refuses to change
 * them after creation. A case keeps `2026-0042` in its new schema, and it keeps
 * it even when the target declares that property with a different sequence: a
 * number is minted once. Re-minting on a move would put a second number on a
 * letter somebody has already sent.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use Opis\JsonSchema\ValidationResult;
use Throwable;

/**
 * Validate and perform a move, keeping the object's identity.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A move spans the row, the
 *  target's validation, the pointer the old address answers from and the audit
 *  entry. Splitting it would put the order of writes in more than one file,
 *  which is the one property that has to stay readable in a single place.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */
class MoveObject implements MoveObjectInterface {

	/**
	 * What a move is called in the audit trail.
	 *
	 * @var string
	 */
	public const ACTION = 'moved';

	/**
	 * The JSON Schema keyword marking a value the platform minted.
	 *
	 * @var string
	 */
	public const GENERATED = 'x-openregister-generated';

	/**
	 * The object store, from the recording aggregate.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objects;

	/**
	 * Constructor.
	 *
	 * @param ObjectChangeRecording    $recording The object store, the audit trail and the log a move
	 *                                            reports to, and the dispatcher that asks the guards of
	 *                                            values other records hold.
	 * @param ObjectValidatorInterface $validator Validation against a schema.
	 */
	public function __construct(
		private readonly ObjectChangeRecording $recording,
		private readonly ObjectValidatorInterface $validator,
	) {
		$this->objects = $recording->objects;
	}//end __construct()

	/**
	 * Whether this object fits the target, and what stands in the way.
	 *
	 * 🔑 GENERATED PROPERTIES ARE EXCLUDED FROM THE "MUST BE ABSENT ON CREATE"
	 * RULE AND NOT FROM VALIDATION. The value still has to be the right SHAPE
	 * for the target; what it does not have to do is be missing. Dropping them
	 * from validation entirely would let a move carry a number the target
	 * declares as an integer into a property it declares as a date.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema       $target The target schema.
	 *
	 * @return array{fits: bool, errors: array<int, string>} The verdict.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function fits(ObjectEntity $object, Schema $target): array {
		$data = ($object->getObject() ?? []);

		try {
			$result = $this->validator->validateObject(
				object: $data,
				schema: $target,
				notSupplied: $this->generatedProperties(schema: $target)
			);
		} catch (Throwable $e) {
			// An unrunnable validation is NOT a pass. A move that skipped
			// validation because the validator threw would put a row in a table
			// whose schema it may not satisfy, and nothing downstream re-checks.
			return [
				'fits' => false,
				'errors' => ['The target schema could not be checked, so nothing was moved: ' . $e->getMessage()],
			];
		}

		if ($result->isValid() === true) {
			return ['fits' => true, 'errors' => []];
		}

		return ['fits' => false, 'errors' => $this->errorsOf(result: $result)];
	}//end fits()

	/**
	 * The sentences of a failed validation: one per sub-error, else the summary, else a fallback.
	 *
	 * `subErrors()`, not `errors()`: opis names it that, and the top-level
	 * error is a summary of them. A caller told only the summary gets "the
	 * data must match schema" and cannot see WHICH property is missing,
	 * which is the whole content of the refusal.
	 *
	 * @param ValidationResult $result The failed validation.
	 *
	 * @return list<string> The sentences
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	private function errorsOf(ValidationResult $result): array {
		$top = $result->error();
		$errors = [];
		foreach (($top?->subErrors() ?? []) as $error) {
			$errors[] = $this->sentenceFor(error: $error);
		}

		if ($errors === [] && $top !== null) {
			$errors[] = $this->sentenceFor(error: $top);
		}

		if ($errors === []) {
			$errors[] = 'The object does not fit the target schema.';
		}

		return $errors;
	}//end errorsOf()

	/**
	 * Move an object, keeping its uuid and everything keyed on it.
	 *
	 * @param ObjectEntity $object         The object.
	 * @param Register     $sourceRegister Where it is.
	 * @param Schema       $sourceSchema   Where it is.
	 * @param Register     $targetRegister Where it is going.
	 * @param Schema       $targetSchema   Where it is going.
	 * @param string       $actor          Who asked.
	 *
	 * @return array{moved: bool, uuid: string, from: array<string, mixed>, to: array<string, mixed>, errors: array<int, string>}
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function move(
		ObjectEntity $object,
		Register $sourceRegister,
		Schema $sourceSchema,
		Register $targetRegister,
		Schema $targetSchema,
		string $actor,
	): array {
		return $this->perform(
			move: new ObjectMove(
				object: $object,
				source: new MoveAddress(register: $sourceRegister, schema: $sourceSchema),
				target: new MoveAddress(register: $targetRegister, schema: $targetSchema),
				actor: $actor
			)
		);
	}//end move()

	/**
	 * Perform a move, keeping the object's uuid and everything keyed on it.
	 *
	 * @param ObjectMove $move The move.
	 *
	 * @return array{moved: bool, uuid: string, from: array<string, mixed>, to: array<string, mixed>, errors: array<int, string>}
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function perform(ObjectMove $move): array {
		$refused = $this->preconditionRefusal(move: $move);
		if ($refused !== null) {
			return $this->refusal(move: $move, error: $refused);
		}

		$verdict = $this->fits(object: $move->object, target: $move->target->schema);
		if ($verdict['fits'] === false) {
			return $move->answer(moved: false, errors: $verdict['errors']);
		}

		$writeError = $this->writeAtTarget(move: $move);
		if ($writeError !== null) {
			return $this->refusal(
				move: $move,
				error: 'The object could not be written at its new address, so it was not moved: ' . $writeError
			);
		}

		return $this->completeMove(move: $move);
	}//end perform()

	/**
	 * Why a move may not start: already there, not at the source, or kept where it is.
	 *
	 * @param ObjectMove $move The move.
	 *
	 * @return string|null The refusal, or null when the move may start
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	private function preconditionRefusal(ObjectMove $move): ?string {
		if ($move->source->ids() === $move->target->ids()) {
			return 'This object is already there.';
		}

		if ($this->isAtSource(move: $move) === false) {
			return 'This object does not live at the address it is being moved from.';
		}

		return $this->keptReason(object: $move->object);
	}//end preconditionRefusal()

	/**
	 * Remove the source row and record the move; a row that stays behind is reported.
	 *
	 * @param ObjectMove $move The move, already written at its target.
	 *
	 * @return array The answer
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	private function completeMove(ObjectMove $move): array {
		$stranded = $this->removeFromSource(move: $move) === false;
		$this->record(move: $move, stranded: $stranded);

		$errors = [];
		if ($stranded === true) {
			$errors = ['The object moved, and its old row could not be removed. It is readable at both addresses.'];
		}

		return $move->answer(moved: true, errors: $errors);
	}//end completeMove()

	/**
	 * Why the object has to stay where it is, or null when it may leave.
	 *
	 * The source row goes without delete events, so the delete guards never see it.
	 * A caller that changes the data before the move asks with the stored object:
	 * the guards read what the row holds now.
	 *
	 * @param ObjectEntity $object The object, as stored at its source.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function keptReason(ObjectEntity $object): ?string {
		if ($object->isLocked() === true) {
			return 'This object is locked, so it was not moved.';
		}

		return ((new FileWriteGuard())->refusalFor(object: $object)?->getMessage() ?? $this->heldReason(object: $object));
	}//end keptReason()

	/**
	 * The refusal of a guard for a value other records still need here, or null.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string|null The reason.
	 */
	private function heldReason(ObjectEntity $object): ?string {
		$event = new ObjectLeavingEvent(object: $object);
		$this->recording->events->dispatchTyped($event);
		if ($event->isPropagationStopped() === false) {
			return null;
		}

		return (string)($event->getErrors()['message'] ?? 'Other records still need this object where it is, so it was not moved.');
	}//end heldReason()

	/**
	 * Whether the object lives at the source address, the only table its old row is removed from.
	 *
	 * @param ObjectMove $move The move.
	 *
	 * @return bool False when the move would leave the object readable at two addresses.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	private function isAtSource(ObjectMove $move): bool {
		$source = $move->source->ids();

		return (int)$move->object->getRegister() === (int)$source['register']
			&& (int)$move->object->getSchema() === (int)$source['schema'];
	}//end isAtSource()

	/**
	 * Write the object at its target address.
	 *
	 * WRITE FIRST. See the class docblock: a failure here leaves the object
	 * exactly where it was, which is the recoverable half.
	 *
	 * @param ObjectMove $move The move.
	 *
	 * @return string|null The write error, or null when the object was written.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	private function writeAtTarget(ObjectMove $move): ?string {
		$object = $move->object;
		$before = $move->before();
		try {
			$sourceSlug = $this->assignTargetSlug(move: $move);
			$object->setRegister((string)$move->target->register->getId());
			$object->setSchema((string)$move->target->schema->getId());
			// The old entity is given: the target table has no row to read it from.
			$this->objects->update(
				entity: $object,
				register: $move->target->register,
				schema: $move->target->schema,
				oldEntity: $before
			);
		} catch (Throwable $e) {
			// Put the entity back the way it was in memory, so a caller that
			// keeps using it is not holding an object that claims to live
			// somewhere it does not.
			$this->putBack(move: $move, slug: ($sourceSlug ?? $object->getSlug()));

			return $e->getMessage();
		}//end try

		return null;
	}//end writeAtTarget()

	/**
	 * Remove the old row, hard and with no events.
	 *
	 * A soft delete would leave a tombstone the trash picks up and offers to
	 * restore INTO A TABLE THE OBJECT NO LONGER BELONGS IN, and a delete event
	 * would tell eight listening apps that an object they can still read was
	 * deleted.
	 *
	 * @param ObjectMove $move The move.
	 *
	 * @return bool False when the old row survived (logged, not fatal).
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	private function removeFromSource(ObjectMove $move): bool {
		try {
			$this->objects->deleteObjectEntity(
				entity: $move->object,
				register: $move->source->register,
				schema: $move->source->schema,
				hardDelete: true,
				dispatchEvents: false
			);
		} catch (Throwable $e) {
			// NOT fatal, and NOT silent. The object is readable at its new
			// address; the old row is a duplicate that reads the same object,
			// and somebody has to know it is there.
			$this->recording->logger->error(
				message: '[MoveObject] object ' . (string)$move->object->getUuid() . ' was written at its new address but its old row '
					. 'could not be removed, so it is readable at both: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'from' => $move->source->ids(), 'to' => $move->target->ids()]
			);
			return false;
		}//end try

		return true;
	}//end removeFromSource()

	/**
	 * Give the object the slug it may hold in the target (#4037).
	 *
	 * The slug was decided in the source; the target may already have a live
	 * holder of it, so it is checked there as a save would.
	 *
	 * @param ObjectMove $move The move: the object and where it is going.
	 *
	 * @return string|null The slug it had in the source.
	 */
	private function assignTargetSlug(ObjectMove $move): ?string {
		$object = $move->object;
		$sourceSlug = $object->getSlug();
		$object->setSlug($this->objects->slugForMove(entity: $object, register: $move->target->register, schema: $move->target->schema));

		return $sourceSlug;
	}//end assignTargetSlug()

	/**
	 * Put an object whose move failed back at its source address, in memory.
	 *
	 * @param ObjectMove  $move The move: the object and its source address.
	 * @param string|null $slug Its source slug.
	 *
	 * @return void
	 */
	private function putBack(ObjectMove $move, ?string $slug): void {
		$move->object->setRegister((string)$move->source->register->getId());
		$move->object->setSchema((string)$move->source->schema->getId());
		$move->object->setSlug($slug);
	}//end putBack()

	/**
	 * One validation error, as a sentence naming the property where it can.
	 *
	 * @param object $error The opis error.
	 *
	 * @return string The sentence.
	 */
	private function sentenceFor(object $error): string {
		$message = self::messageOf(error: $error);
		$path = self::pathOf(error: $error);

		if ($path !== '' && $message !== '') {
			return $path . ': ' . $message;
		}

		if ($message !== '') {
			return $message;
		}

		return 'invalid';
	}//end sentenceFor()

	/**
	 * The message of a validation error, or '' when it has none.
	 *
	 * @param object $error The error.
	 *
	 * @return string The message
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	private static function messageOf(object $error): string {
		if (method_exists($error, 'message') === true) {
			return (string)$error->message();
		}

		return '';
	}//end messageOf()

	/**
	 * The data path of a validation error joined by '/', or '' when it has none.
	 *
	 * @param object $error The error.
	 *
	 * @return string The path
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
	 */
	private static function pathOf(object $error): string {
		if (method_exists($error, 'data') === false) {
			return '';
		}

		$info = $error->data();
		if (is_object($info) === true && method_exists($info, 'path') === true) {
			return implode('/', (array)$info->path());
		}

		return '';
	}//end pathOf()

	/**
	 * The properties the target declares as platform-minted.
	 *
	 * 🔴 KEYED BY NAME, WITH A REASON. `ValidateObject::validateObject()` hands
	 * this straight to `NotSuppliedHandler::excuse()`, which reads
	 * `array_keys()`. A LIST therefore excuses the properties named `0`, `1`
	 * and `2`, which is to say nothing at all: the move would report a target
	 * as not fitting because a value the platform mints on save is absent.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, string> The property names, mapped to the reason.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function generatedProperties(Schema $schema): array {
		$generated = [];
		foreach ($schema->getProperties() as $name => $config) {
			if (is_array($config) === true && ($config[self::GENERATED] ?? null) !== null) {
				$generated[(string)$name] = self::GENERATED;
			}
		}

		return $generated;
	}//end generatedProperties()

	/**
	 * Write the one `moved` entry naming both addresses.
	 *
	 * Both, because "this object moved" with only one address on it sends the
	 * next reader looking through every register for where it came from.
	 *
	 * @param ObjectMove $move     The move; its actor is who moved it.
	 * @param boolean    $stranded Whether the old row survived.
	 *
	 * @return void
	 */
	private function record(ObjectMove $move, bool $stranded): void {
		$object  = $move->object;
		$actorId = null;
		if ($move->actor !== '') {
			$actorId = $move->actor;
		}

		try {
			$this->recording->auditTrail->createAuditTrailEntry(
				object: $object,
				action: self::ACTION,
				context: [
					'from' => $move->source->ids(),
					'to' => $move->target->ids(),
					'strandedSourceRow' => $stranded,
				],
				actorId: $actorId
			);
		} catch (Throwable $e) {
			// The move happened. Failing it now would leave the object moved
			// and the caller told it was not, which is the one state nobody
			// can act on.
			$this->recording->logger->warning(
				message: '[MoveObject] the move of ' . (string)$object->getUuid()
					. ' could not be recorded: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end record()

	/**
	 * A refusal shaped like every other answer.
	 *
	 * @param ObjectMove $move  The move that was asked for.
	 * @param string     $error Why not.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function refusal(ObjectMove $move, string $error): array {
		return $move->answer(moved: false, errors: [$error]);
	}//end refusal()
}//end class
