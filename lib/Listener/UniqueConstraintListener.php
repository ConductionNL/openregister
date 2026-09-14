<?php

/**
 * OpenRegister UniqueConstraintListener
 *
 * Enforces a schema's named uniqueness constraints at write time, in the one
 * way each constraint asks to be enforced.
 *
 * A `refuse` constraint stops the write, and `MagicMapper` turns that into a
 * `HookStoppedException`, which the objects controller answers as 422 naming
 * the combination and the conflicting object. A `report` constraint lets the
 * write through and records the breach on the object's `validation`
 * envelope, where it is readable afterwards without a second table or a
 * migration.
 *
 * Recording rather than refusing is not a weaker check, it is a different
 * one. "Two contacts with the same e-mail" is usually a duplicate and
 * occasionally a household; refusing it stops an intake over a fact nobody
 * has decided yet. The record is what lets someone decide later.
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
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses or records a breach of a named uniqueness constraint.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 */
class UniqueConstraintListener implements IEventListener {

	/**
	 * The error code a refused write carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'unique-constraint-breached';

	/**
	 * The `validation` key a reported breach is recorded under.
	 *
	 * @var string
	 */
	public const VALIDATION_KEY = 'uniqueness';

	/**
	 * Constructor.
	 *
	 * @param UniqueConstraintEvaluator $evaluator Reads and describes the constraints.
	 * @param SchemaMapper $schemas Resolves the schema an object belongs to.
	 * @param MagicMapper $objects Finds the objects already holding a combination.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly UniqueConstraintEvaluator $evaluator,
		private readonly SchemaMapper $schemas,
		private readonly MagicMapper $objects,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Evaluate the constraints for one write.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->evaluate(event: $event, object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->evaluate(event: $event, object: $event->getNewObject());
		}

	}//end handle()

	/**
	 * Evaluate every named constraint against one object.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param ObjectEntity $object The object the write carries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
	 */
	private function evaluate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		try {
			$reference = $object->getSchema();
			if ($reference === null || $reference === '') {
				return;
			}

			$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
			$constraints = $this->evaluator->constraints(configuration: $schema->getConfiguration());
			if ($constraints === []) {
				return;
			}

			$data = $object->getObject();
			if (is_array($data) === false) {
				return;
			}

			$reports = [];
			foreach ($constraints as $constraint) {
				$filters = $this->evaluator->filtersFor(constraint: $constraint, object: $data);
				if ($filters === null) {
					continue;
				}

				$conflict = $this->conflictingUuid(
					registerRef: (string)$object->getRegister(),
					schema: $schema,
					filters: $filters,
					ownUuid: (string)$object->getUuid()
				);
				if ($conflict === null) {
					continue;
				}

				$message = $this->evaluator->describeBreach(
					constraint: $constraint,
					object: $data,
					conflictingId: $conflict
				);

				if ($constraint['action'] === UniqueConstraintEvaluator::ACTION_REFUSE) {
					$event->setErrors(
						[
							'code' => self::ERROR_CODE,
							'message' => $message,
							'constraint' => $constraint['name'],
							'properties' => $constraint['properties'],
							'conflictingObject' => $conflict,
						]
					);
					$event->stopPropagation();

					return;
				}

				$reports[] = [
					'constraint' => $constraint['name'],
					'properties' => $constraint['properties'],
					'conflictingObject' => $conflict,
					'message' => $message,
					'reportedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
				];
			}//end foreach

			$this->record(object: $object, reports: $reports);
		} catch (Throwable $failure) {
			// A constraint that cannot be evaluated must not become the reason
			// nothing can be written; the failure is named in the log.
			$this->logger->warning(
				message: '[UniqueConstraintListener] The evaluation itself failed, allowing the save: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try

	}//end evaluate()

	/**
	 * Write the reported breaches onto the object's validation envelope.
	 *
	 * The key is always written, empty list included, so a breach that has
	 * been resolved by an edit stops being reported rather than lingering as
	 * a fact about a value the object no longer holds.
	 *
	 * @param ObjectEntity $object The object being written.
	 * @param array<int,array<string,mixed>> $reports The breaches to record.
	 *
	 * @return void
	 */
	private function record(ObjectEntity $object, array $reports): void {
		$validation = $object->getValidation();
		if (is_array($validation) === false) {
			$validation = [];
		}

		if ($reports === [] && isset($validation[self::VALIDATION_KEY]) === false) {
			return;
		}

		$validation[self::VALIDATION_KEY] = $reports;
		$object->setValidation($validation);
	}//end record()

	/**
	 * The uuid of another object already holding the combination, or null.
	 *
	 * The object's own uuid is excluded, so re-saving a record does not
	 * report it as conflicting with itself, which is what would otherwise
	 * make every update of a `report` constraint's subject look like a breach.
	 *
	 * @param string $registerRef The register the object belongs to.
	 * @param Schema $schema The schema being written against.
	 * @param array<string,mixed> $filters The combination's filters.
	 * @param string $ownUuid The uuid of the object being written.
	 *
	 * @return string|null The conflicting object's uuid.
	 */
	private function conflictingUuid(string $registerRef, Schema $schema, array $filters, string $ownUuid): ?string {
		if (is_numeric($registerRef) === false) {
			// MagicMapper resolves the pair by NUMERIC id and answers the
			// empty list for anything else, which would read as "no conflict"
			// rather than as "not checked". Say nothing instead of saying no.
			return null;
		}

		$results = $this->objects->searchObjects(
			query: array_merge(
				$filters,
				[
					'@self' => ['register' => (int)$registerRef, 'schema' => (int)$schema->getId()],
					'_limit' => 5,
				]
			),
			_rbac: false,
			_multitenancy: false
		);

		if (is_array($results) === false) {
			return null;
		}

		foreach ($results as $candidate) {
			if ($candidate instanceof ObjectEntity === false) {
				continue;
			}

			$uuid = (string)$candidate->getUuid();
			if ($uuid !== '' && $uuid !== $ownUuid) {
				return $uuid;
			}
		}

		return null;
	}//end conflictingUuid()
}//end class
