<?php

/**
 * Writes the lifecycle-state projection, and says what it can answer about.
 *
 * 🔴 THE PROPERTY IS RESOLVED FROM THE SCHEMA, NOT FROM THE WRITE. The event
 * carries the action and the two states, not the field they live in, and the
 * tempting shortcut is to take the key that changed in the payload. That would
 * project whatever the pipeline attached: a key some listener added, a
 * `_`-prefixed internal, a property the schema never declared as a state. A
 * filter over an attached key answers about data the schema does not describe,
 * and the searcher cannot tell the difference. So the field comes from
 * `x-openregister-lifecycle.field` and a schema that declares none projects
 * nothing.
 *
 * 🔑 A TRANSITION IS TWO WRITES: the interval the object is leaving is closed,
 * and a new one is opened at the same moment. Both are idempotent, so a
 * replayed event costs a row rather than a lie.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\History
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\History;

use DateTime;
use OCA\OpenRegister\Db\StateHistory\TrackedProperty;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\StateHistory;
use OCA\OpenRegister\Db\StateHistoryMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the state-history projection.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
class StateHistoryProjector implements StateHistoryProjectorInterface, StateHistoryRecorderInterface {

	/**
	 * Constructor.
	 *
	 * @param StateHistoryMapperInterface $mapper       The projection.
	 * @param SchemaMapperInterface       $schemaMapper Resolves a schema's declared lifecycle field.
	 * @param LoggerInterface    $logger       Diagnostics.
	 */
	public function __construct(
		private readonly StateHistoryMapperInterface $mapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one transition.
	 *
	 * @param ObjectTransitionedEvent $move      The committed transition: its object, register and target state.
	 * @param Schema|null             $schema    The object's schema, or null when it cannot be resolved.
	 * @param DateTime                $stampedAt The moment of the move.
	 *
	 * @return bool True when an interval was written.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function record(ObjectTransitionedEvent $move, ?Schema $schema, DateTime $stampedAt): bool {
		$property = $this->declaredProperty(schema: $schema);
		if ($property === null) {
			// A schema with no declared lifecycle field has no state to
			// project. This is the ordinary case for most schemas, so it is
			// not an error and not logged at warning level.
			return false;
		}

		$objectUuid = (string)$move->getObject()->getUuid();
		$this->mapper->closeOpenInterval(tracked: new TrackedProperty(objectUuid: $objectUuid, property: $property), leftAt: $stampedAt);

		$interval = new StateHistory();
		$interval->setObjectUuid($objectUuid);
		$interval->setRegister($move->getRegister());
		$interval->setSchema((string)$schema?->getSlug());
		$interval->setProperty($property);
		$interval->setValue($move->getTo());
		$interval->setEnteredAt($stampedAt);
		$interval->setLeftAt(null);

		$this->mapper->insert($interval);

		return true;
	}//end record()

	/**
	 * The properties a history predicate can be answered about.
	 *
	 * Read from the schemas' own declarations, never from the rows present in
	 * the projection: an empty projection must still be able to say that
	 * `status` is a property with history, and a key that somehow got written
	 * must not become filterable because it is there.
	 *
	 * @return string[] The declared lifecycle fields, distinct.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function projectedProperties(): array {
		try {
			$schemas = $this->schemaMapper->findAll();
		} catch (\Throwable $e) {
			$this->logger->error(
				'[StateHistoryProjector] Could not resolve the projected properties: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);
			return [];
		}

		$properties = [];
		foreach ($schemas as $schema) {
			$property = $this->declaredProperty(schema: $schema);
			if ($property !== null) {
				$properties[$property] = true;
			}
		}

		return array_keys($properties);
	}//end projectedProperties()

	/**
	 * The lifecycle field a schema declares, or null.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return string|null The declared property name.
	 */
	private function declaredProperty(?Schema $schema): ?string {
		if ($schema === null) {
			return null;
		}

		return $this->lifecycleField(configuration: ($schema->getConfiguration() ?? []));
	}//end declaredProperty()

	/**
	 * The `field` (else `property`) of a schema configuration's `x-openregister-lifecycle` block.
	 *
	 * @param array $configuration The schema configuration.
	 *
	 * @return string|null The declared property name, or null when the block names none.
	 */
	private function lifecycleField(array $configuration): ?string {
		$annotation = ($configuration['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		$field = (string)($annotation['field'] ?? ($annotation['property'] ?? ''));
		if ($field === '') {
			return null;
		}

		return $field;
	}//end lifecycleField()
}//end class
