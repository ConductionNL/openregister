<?php

/**
 * GeneratedIdentifierListener: the number an object is given, and then keeps.
 *
 * Two jobs, one class, because they are two halves of one rule. On create it
 * fills an empty declared property from its counter. On update it refuses any
 * change to that property. Split across two listeners, the second is the one
 * somebody forgets to register, and a frozen identifier that is not frozen
 * fails in the quietest way available: the number in the letter stops matching
 * the number in the record, and nothing anywhere errors.
 *
 * It runs on ObjectCreatingEvent, beside LifecycleInitialStateListener and for
 * the same reason: the value has to be in the object's body before it is
 * written, not added afterwards, or the object's first version is the one
 * without a number.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\SequenceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills a declared identifier on create, and freezes it afterwards.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 */
class GeneratedIdentifierListener implements IEventListener {

	/**
	 * The error code an update refusal carries.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'generated-identifier-frozen';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Resolves the object's schema.
	 * @param SequenceService $sequences Reserves a number, atomically.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly SequenceService $sequences,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fill on create, refuse a change on update.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->fill(event: $event);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->freeze(event: $event);
		}

	}//end handle()

	/**
	 * Give every empty declared property its number.
	 *
	 * EVERY declared property, not the first: a schema may declare a second
	 * identifier beside the first, each drawing from its own counter, and a
	 * loop that stopped at one would leave the second silently empty.
	 *
	 * A failure here is NOT swallowed the way a marker's is. An object created
	 * without the number it was declared to carry is a record somebody will
	 * quote in a letter, so the create is refused and says why.
	 *
	 * @param ObjectCreatingEvent $event The create being prepared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	private function fill(ObjectCreatingEvent $event): void {
		$object = $event->getObject();
		$schema = $this->loadSchema(object: $object);
		if ($schema === null) {
			return;
		}

		$declarations = $this->declarationsOf(schema: $schema, schemaId: (int)$object->getSchema());
		if ($declarations === []) {
			return;
		}

		$data = ($object->getObject() ?? []);
		$now = new DateTimeImmutable('now');
		$changed = false;

		foreach ($declarations as $field => $declaration) {
			$supplied = (string)($data[$field] ?? '');

			try {
				if ($supplied !== '') {
					// A create or import that brought its own value keeps it,
					// and pushes the counter past it so the next create cannot
					// collide with what was just imported.
					$this->advancePast(declaration: $declaration, value: $supplied);
					continue;
				}

				$data[$field] = $declaration->render(
					sequenceValue: $this->sequences->reserveNext(
						registerId: 0,
						schemaId: 0,
						scopeKey: $this->scopeKey(declaration: $declaration, period: $declaration->periodAt(moment: $now))
					),
					moment: $now
				);
				$changed = true;
			} catch (Throwable $failure) {
				$this->logger->error(
					message: '[GeneratedIdentifierListener] could not issue '.$field.': '.$failure->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $failure]
				);

				$event->setErrors(
					[
						'code' => 'generated-identifier-unavailable',
						'message' => sprintf(
							'The identifier for "%s" could not be issued, so the object was not created.',
							$field
						),
						'property' => $field,
						'sequence' => $declaration->sequence(),
					]
				);
				$event->stopPropagation();

				return;
			}//end try
		}//end foreach

		if ($changed === true) {
			$object->setObject($data);
		}

	}//end fill()

	/**
	 * Refuse an update that changes a generated identifier.
	 *
	 * An update that OMITS the property is not a change: a partial write that
	 * never mentioned the number is not an attempt to renumber the record, and
	 * treating it as one would refuse most ordinary edits.
	 *
	 * @param ObjectUpdatingEvent $event The update being prepared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	private function freeze(ObjectUpdatingEvent $event): void {
		$new = $event->getNewObject();
		$old = $event->getOldObject();
		if ($old === null) {
			return;
		}

		$schema = $this->loadSchema(object: $new);
		if ($schema === null) {
			return;
		}

		$declarations = $this->declarationsOf(schema: $schema, schemaId: (int)$new->getSchema());
		if ($declarations === []) {
			return;
		}

		$after = ($new->getObject() ?? []);
		$before = ($old->getObject() ?? []);

		// Only the property NAMES matter here: the freeze compares the value before
		// with the value after, and never renders anything.
		foreach (array_keys($declarations) as $field) {
			$was = (string)($before[$field] ?? '');
			if ($was === '' || array_key_exists($field, $after) === false) {
				continue;
			}

			if ((string)$after[$field] === $was) {
				continue;
			}

			$event->setErrors(
				[
					'code' => self::ERROR_CODE,
					'message' => sprintf(
						'"%s" is a generated identifier and cannot be changed. It was issued as "%s".',
						$field,
						$was
					),
					'property' => $field,
					'issued' => $was,
				]
			);
			$event->stopPropagation();

			return;
		}//end foreach

	}//end freeze()

	/**
	 * Push a counter past a value somebody else supplied.
	 *
	 * A value that does not parse against the format is left alone rather than
	 * refused. An instance that numbered its cases by hand before the
	 * annotation existed still has to be importable, and its old numbers are
	 * not this counter's to reason about.
	 *
	 * @param GeneratedIdentifierDeclaration $declaration The property's declaration.
	 * @param string $value The value that was supplied.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	private function advancePast(GeneratedIdentifierDeclaration $declaration, string $value): void {
		$parsed = $declaration->parse(value: $value);
		if ($parsed === null) {
			return;
		}

		$this->sequences->advanceTo(
			registerId: 0,
			schemaId: 0,
			scopeKey: $this->scopeKey(declaration: $declaration, period: $parsed['period']),
			reserved: $parsed['sequence']
		);

	}//end advancePast()

	/**
	 * The scope key one named counter draws from.
	 *
	 * A named sequence is stored at register 0 and schema 0, a pair no register
	 * and no schema can carry, so a NAMED counter can never collide with one
	 * the `sequence` calculation node scopes to a real (register, schema). That
	 * sentinel is also what lets two schemas share a counter by naming it,
	 * which a per-schema scope cannot express.
	 *
	 * @param GeneratedIdentifierDeclaration $declaration The property's declaration.
	 * @param string $period The period keying the counter.
	 *
	 * @return string The scope key.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-two-schemas-may-share-one-sequence
	 */
	private function scopeKey(GeneratedIdentifierDeclaration $declaration, string $period): string {
		return 'gen:'.$declaration->sequence().'|'.$period;

	}//end scopeKey()

	/**
	 * Every property of a schema that declares a generated identifier.
	 *
	 * A malformed declaration is skipped here rather than thrown: the schema
	 * save already refused it, so one that reaches this path came from a
	 * schema written before the validation existed, and refusing every create
	 * on that schema would be a worse answer than issuing no number.
	 *
	 * The schema id is passed IN rather than read off the entity: `getId()` on a
	 * Nextcloud Entity is answered by `__call`, so a test double cannot be told
	 * what to return for it, and a log line is not worth an untestable branch.
	 *
	 * @param Schema $schema The object's schema.
	 * @param int $schemaId The schema's id, as the object carries it.
	 *
	 * @return array<string, GeneratedIdentifierDeclaration> Property name to its declaration.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `fromProperty()` is a named constructor.
	 *                                       Injecting a factory to build a value object
	 *                                       from an array it already has would add a
	 *                                       collaborator that answers one question and
	 *                                       holds no state.
	 */
	private function declarationsOf(Schema $schema, int $schemaId): array {
		$declarations = [];
		$properties = ($schema->getProperties() ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		foreach ($properties as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			try {
				$declaration = GeneratedIdentifierDeclaration::fromProperty(
					property: $property,
					path: (string)$name
				);
			} catch (Throwable $failure) {
				$this->logger->warning(
					message: '[GeneratedIdentifierListener] schema '.(string)$schemaId
						.' property '.(string)$name.' has an unusable declaration: '.$failure->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				continue;
			}

			if ($declaration !== null) {
				$declarations[(string)$name] = $declaration;
			}
		}//end foreach

		return $declarations;

	}//end declarationsOf()

	/**
	 * Resolve the object's schema, or null when it cannot be read.
	 *
	 * @param ObjectEntity $object The object being written.
	 *
	 * @return Schema|null The schema, or null.
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$schemaId = (int)$object->getSchema();
		if ($schemaId === 0) {
			return null;
		}

		try {
			return $this->schemaMapper->find($schemaId);
		} catch (Throwable $failure) {
			$this->logger->debug(
				sprintf('[GeneratedIdentifierListener] schema %d not readable: %s', $schemaId, $failure->getMessage())
			);
			return null;
		}

	}//end loadSchema()
}//end class
