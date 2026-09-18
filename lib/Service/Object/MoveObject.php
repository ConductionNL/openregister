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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;
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
class MoveObject {

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
	 * Constructor.
	 *
	 * @param MagicMapper      $objects  The object store.
	 * @param ValidateObject   $validator Validation against a schema.
	 * @param AuditTrailMapper $audit    The audit trail.
	 * @param LoggerInterface  $logger   The logger.
	 */
	public function __construct(
		private readonly MagicMapper $objects,
		private readonly ValidateObject $validator,
		private readonly AuditTrailMapper $audit,
		private readonly LoggerInterface $logger,
	) {
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

		// `subErrors()`, not `errors()`: opis names it that, and the top-level
		// error is a summary of them. A caller told only the summary gets "the
		// data must match schema" and cannot see WHICH property is missing,
		// which is the whole content of the refusal.
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

		return ['fits' => false, 'errors' => $errors];
	}//end fits()

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
		$uuid = (string)$object->getUuid();
		$from = ['register' => $sourceRegister->getId(), 'schema' => $sourceSchema->getId()];
		$to = ['register' => $targetRegister->getId(), 'schema' => $targetSchema->getId()];

		if ($from === $to) {
			return $this->refusal(uuid: $uuid, from: $from, to: $to, error: 'This object is already there.');
		}

		$verdict = $this->fits(object: $object, target: $targetSchema);
		if ($verdict['fits'] === false) {
			return [
				'moved' => false,
				'uuid' => $uuid,
				'from' => $from,
				'to' => $to,
				'errors' => $verdict['errors'],
			];
		}

		// WRITE FIRST. See the class docblock: a failure here leaves the object
		// exactly where it was, which is the recoverable half.
		try {
			$object->setRegister($targetRegister->getId());
			$object->setSchema($targetSchema->getId());
			$this->objects->updateObjectEntity(
				entity: $object,
				register: $targetRegister,
				schema: $targetSchema
			);
		} catch (Throwable $e) {
			// Put the entity back the way it was in memory, so a caller that
			// keeps using it is not holding an object that claims to live
			// somewhere it does not.
			$object->setRegister($sourceRegister->getId());
			$object->setSchema($sourceSchema->getId());

			return $this->refusal(
				uuid: $uuid,
				from: $from,
				to: $to,
				error: 'The object could not be written at its new address, so it was not moved: ' . $e->getMessage()
			);
		}//end try

		// THEN REMOVE, hard and with no events. A soft delete would leave a
		// tombstone the trash picks up and offers to restore INTO A TABLE THE
		// OBJECT NO LONGER BELONGS IN, and a delete event would tell eight
		// listening apps that an object they can still read was deleted.
		$stranded = false;
		try {
			$this->objects->deleteObjectEntity(
				entity: $object,
				register: $sourceRegister,
				schema: $sourceSchema,
				hardDelete: true,
				dispatchEvents: false
			);
		} catch (Throwable $e) {
			// NOT fatal, and NOT silent. The object is readable at its new
			// address; the old row is a duplicate that reads the same object,
			// and somebody has to know it is there.
			$stranded = true;
			$this->logger->error(
				message: '[MoveObject] object ' . $uuid . ' was written at its new address but its old row '
					. 'could not be removed, so it is readable at both: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'from' => $from, 'to' => $to]
			);
		}//end try

		$this->record(object: $object, from: $from, to: $to, actor: $actor, stranded: $stranded);

		return [
			'moved' => true,
			'uuid' => $uuid,
			'from' => $from,
			'to' => $to,
			'errors' => (($stranded === true)
				? ['The object moved, and its old row could not be removed. It is readable at both addresses.']
				: []),
		];
	}//end move()

	/**
	 * One validation error, as a sentence naming the property where it can.
	 *
	 * @param object $error The opis error.
	 *
	 * @return string The sentence.
	 */
	private function sentenceFor(object $error): string {
		$message = '';
		if (method_exists($error, 'message') === true) {
			$message = (string)$error->message();
		}

		$path = '';
		if (method_exists($error, 'data') === true) {
			$info = $error->data();
			if (is_object($info) === true && method_exists($info, 'path') === true) {
				$path = implode('/', (array)$info->path());
			}
		}

		if ($path !== '' && $message !== '') {
			return $path . ': ' . $message;
		}

		return (($message !== '') ? $message : 'invalid');
	}//end sentenceFor()

	/**
	 * The properties the target declares as platform-minted.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function generatedProperties(Schema $schema): array {
		$generated = [];
		foreach ($schema->getProperties() as $name => $config) {
			if (is_array($config) === true && ($config[self::GENERATED] ?? null) !== null) {
				$generated[] = (string)$name;
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
	 * @param ObjectEntity         $object   The object.
	 * @param array<string, mixed> $from     Where it was.
	 * @param array<string, mixed> $to       Where it is.
	 * @param string               $actor    Who moved it.
	 * @param boolean              $stranded Whether the old row survived.
	 *
	 * @return void
	 */
	private function record(ObjectEntity $object, array $from, array $to, string $actor, bool $stranded): void {
		try {
			$this->audit->createAuditTrailEntry(
				object: $object,
				action: self::ACTION,
				context: [
					'from' => $from,
					'to' => $to,
					'strandedSourceRow' => $stranded,
				],
				actorId: (($actor !== '') ? $actor : null)
			);
		} catch (Throwable $e) {
			// The move happened. Failing it now would leave the object moved
			// and the caller told it was not, which is the one state nobody
			// can act on.
			$this->logger->warning(
				message: '[MoveObject] the move of ' . (string)$object->getUuid()
					. ' could not be recorded: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end record()

	/**
	 * A refusal shaped like every other answer.
	 *
	 * @param string               $uuid  The object.
	 * @param array<string, mixed> $from  Where it is.
	 * @param array<string, mixed> $to    Where it was asked to go.
	 * @param string               $error Why not.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function refusal(string $uuid, array $from, array $to, string $error): array {
		return ['moved' => false, 'uuid' => $uuid, 'from' => $from, 'to' => $to, 'errors' => [$error]];
	}//end refusal()
}//end class
