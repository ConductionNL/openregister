<?php

/**
 * OpenRegister QualityScoreOnSaveListener
 *
 * Subscribes to ObjectCreatingEvent + ObjectUpdatingEvent. When a schema
 * declares an `x-openregister-quality` annotation, computes a per-object
 * data-quality score (0-1) and patches it (plus an optional status label)
 * into the object payload before persistence. Mirrors the materialise-on-save
 * contract of {@see CalculationOnSaveListener}: runs before the write, is
 * fail-soft (logs a warning and continues on any error), and never aborts
 * the save.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Quality\QualityScorerInterface;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Materialises a declared data-quality score into the object payload on save.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
 */
class QualityScoreOnSaveListener implements IEventListener {
	/**
	 * Default field the score is written to when the annotation omits `field`.
	 *
	 * @var string
	 */
	private const DEFAULT_FIELD = 'qualityScore';

	/**
	 * Wire collaborators used to look up and score the schema's quality rules.
	 *
	 * @param QualityScorerInterface $scorer       Pure data-quality scorer.
	 * @param EventSchemaLookup $lookup Resolves the object's schema and logs a failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	public function __construct(
		private readonly QualityScorerInterface $scorer,
		private readonly EventSchemaLookup $lookup,
	) {
	}//end __construct()

	/**
	 * Run quality scoring before the object is persisted.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->process(object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->process(object: $event->getNewObject());
			return;
		}
	}//end handle()

	/**
	 * Compute and patch the quality score onto the object data.
	 *
	 * @param ObjectEntity $object Object being created or updated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function process(ObjectEntity $object): void {
		$schema = $this->loadSchema(object: $object);
		$quality = ($this->getQuality(schema: $schema) ?? []);
		$rules = ($quality['rules'] ?? null);
		if (is_array($rules) === false || count($rules) === 0) {
			return;
		}

		$score = $this->score(object: $object, rules: $rules);
		if ($score !== null) {
			$status = $this->scorer->status(score: $score, thresholds: $this->thresholdsOf(quality: $quality));
			$assessment = ['score' => $score, 'status' => $status];
			$this->writeAssessment(object: $object, assessment: $assessment);
			$candidates = $this->candidateFields(quality: $quality, assessment: $assessment);
			$this->writeBodyFields(object: $object, fields: $this->bodyFields(schema: $schema, candidates: $candidates));
		}
	}//end process()

	/**
	 * Score the data, or log and answer null when the scorer fails.
	 *
	 * @param ObjectEntity             $object The object being saved; its data is scored.
	 * @param array<int|string, mixed> $rules  The quality rules.
	 *
	 * @return float|null The score, or null on failure.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function score(ObjectEntity $object, array $rules): ?float {
		try {
			return $this->scorer->score(object: ($object->getObject() ?? []), rules: $rules, now: new DateTimeImmutable());
		} catch (Throwable $e) {
			$this->lookup->logger->warning(
				sprintf('Quality scoring failed on %s: %s', (string)$object->getUuid(), $e->getMessage())
			);
			return null;
		}
	}//end score()

	/**
	 * The configured thresholds, or the empty list when they are not a list.
	 *
	 * @param array<string, mixed> $quality The quality configuration.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function thresholdsOf(array $quality): array {
		$thresholds = ($quality['thresholds'] ?? []);
		if (is_array($thresholds) === false) {
			return [];
		}

		return $thresholds;
	}//end thresholdsOf()

	/**
	 * Put the assessment in the `@self` envelope, always.
	 *
	 * It describes the object's data rather than the thing the object
	 * describes, and putting it there is what lets a schema be scored WITHOUT
	 * declaring `qualityScore` as an ordinary property — which used to put a
	 * number field the platform overwrites on save in front of whoever was
	 * filling the form.
	 *
	 * @param ObjectEntity                        $object     The object being saved.
	 * @param array{score: float, status: string} $assessment The score and the status it reaches.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function writeAssessment(ObjectEntity $object, array $assessment): void {
		$object->setQuality(
			[
				'score' => $assessment['score'],
				'status' => $assessment['status'],
				'scoredAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
	}//end writeAssessment()

	/**
	 * The body properties the score and status go to, ONLY where the schema declares them.
	 *
	 * Dropping the write outright would freeze the stored value of every
	 * schema that has one, and a score that silently stops updating reads
	 * exactly like a score that is simply good. A schema that has migrated to
	 * `@self.quality` declares neither, so this answers nothing and the object
	 * body stays clean.
	 *
	 * @param Schema                                  $schema     The object's schema.
	 * @param list<array{0: string, 1: float|string}> $candidates The configured fields and their values.
	 *
	 * @return list<array{0: string, 1: float|string}> Property and value, score first.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function bodyFields(Schema $schema, array $candidates): array {
		$properties = ($schema->getProperties() ?? []);
		$fields = [];
		foreach ($candidates as $pair) {
			if ($pair[0] !== '' && array_key_exists($pair[0], $properties) === true) {
				$fields[] = $pair;
			}
		}

		return $fields;
	}//end bodyFields()

	/**
	 * The configured score and status fields with their values, score first.
	 *
	 * @param array<string, mixed>                $quality    The quality configuration.
	 * @param array{score: float, status: string} $assessment The score and status.
	 *
	 * @return list<array{0: string, 1: float|string}> Property (empty when not configured) and value.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function candidateFields(array $quality, array $assessment): array {
		return [
			[$this->scoreField(quality: $quality), $assessment['score']],
			[(string)($quality['statusField'] ?? ''), $assessment['status']],
		];
	}//end candidateFields()

	/**
	 * Write values into the object body, replacing the data only when one differs.
	 *
	 * @param ObjectEntity                            $object The object being saved.
	 * @param list<array{0: string, 1: float|string}> $fields Property and value pairs, in order.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function writeBodyFields(ObjectEntity $object, array $fields): void {
		$data = ($object->getObject() ?? []);
		$changed = false;
		foreach ($fields as [$field, $value]) {
			if (($data[$field] ?? null) !== $value) {
				$data[$field] = $value;
				$changed = true;
			}
		}

		if ($changed === true) {
			$object->setObject($data);
		}
	}//end writeBodyFields()

	/**
	 * The body property the score is written to.
	 *
	 * @param array<string, mixed> $quality The quality configuration.
	 *
	 * @return string The field, the default when none or an empty one is configured.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function scoreField(array $quality): string {
		$field = (string)($quality['field'] ?? self::DEFAULT_FIELD);
		if ($field === '') {
			return self::DEFAULT_FIELD;
		}

		return $field;
	}//end scoreField()

	/**
	 * Look up the schema referenced by an object instance.
	 *
	 * @param ObjectEntity $object Object whose schema reference to resolve.
	 *
	 * @return Schema|null Resolved schema, or null on lookup failure.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$ref = $object->getSchema();
		if ($ref === null || $ref === '') {
			return null;
		}

		try {
			return $this->lookup->catalog->schemas()->find($ref, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}
	}//end loadSchema()

	/**
	 * Read the `x-openregister-quality` configuration block.
	 *
	 * @param Schema|null $schema Schema to inspect, or null when none was found.
	 *
	 * @return array<string, mixed>|null Quality config, or null when absent.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function getQuality(?Schema $schema): ?array {
		if ($schema === null) {
			return null;
		}

		return $this->nonEmptyArray(value: (($schema->getConfiguration() ?? [])['x-openregister-quality'] ?? null));
	}//end getQuality()

	/**
	 * The value when it is a non-empty array, else null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
	 */
	private function nonEmptyArray(mixed $value): ?array {
		if (is_array($value) === false || $value === []) {
			return null;
		}

		return $value;
	}//end nonEmptyArray()
}//end class
