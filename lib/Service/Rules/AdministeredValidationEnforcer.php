<?php

/**
 * Running a schema's administered validations over one write.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rules\Support\AdministeredValidationChecks;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a schema's declared validations refuse a write.
 *
 * Split out of `AdministeredValidationListener`, which is now only the
 * adapter that takes the answer and puts it on the event. A listener is a
 * wiring detail of Nextcloud's event bus; whether a write is refused is not,
 * and the two were only in one class because the listener grew into it.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
class AdministeredValidationEnforcer {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper The schema lookup.
	 * @param AdministeredValidationChecks $checks The declared checks, the named-condition vocabulary and the caller's language.
	 * @param AdministeredValidationRunLog $runLog Records what a validation decided.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly AdministeredValidationChecks $checks,
		private readonly AdministeredValidationRunLog $runLog,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the schema's validations over a write, and say whether it is refused.
	 *
	 * 🔑 IT ANSWERS, IT DOES NOT STOP ANYTHING. Returning the refusal rather
	 * than reaching into the event is what lets this be driven from a test
	 * without dispatching one, and it keeps the decision in a class that
	 * knows nothing about Nextcloud's event bus.
	 *
	 * @param ObjectEntity      $newObject The object as it would be saved.
	 * @param ObjectEntity|null $oldObject The object as stored, null on a create.
	 *
	 * @return array<string, mixed>|null The refusal to put on the event, or null when the write may proceed.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function refusalFor(ObjectEntity $newObject, ?ObjectEntity $oldObject): ?array {
		$schema = $this->schemaOf(object: $newObject);
		$declared = $this->declaredOn(schema: $schema);
		if ($schema === null || $declared === null) {
			// No validations declared: every schema saved before this change
			// takes this exit, and pays one array lookup for it.
			return null;
		}

		$document = $this->documentOf(newObject: $newObject, oldObject: $oldObject);
		$outcome = $this->evaluate(schema: $schema, declared: $declared, document: $document);
		$this->recordOutcome(outcome: $outcome, object: $newObject, schema: $schema);
		if ($outcome['refusals'] === []) {
			return null;
		}

		return $this->refusal(outcome: $outcome);
	}//end refusalFor()

	/**
	 * The validations a schema declares, or null when it declares none.
	 *
	 * @param Schema|null $schema The schema, or null when it could not be resolved.
	 *
	 * @return array<string, mixed>|null The declared validations; null when absent, empty or not a map.
	 */
	private function declaredOn(?Schema $schema): ?array {
		$declared = (($schema?->getConfiguration() ?? [])[AdministeredValidations::ANNOTATION] ?? null);
		if (is_array($declared) === false || $declared === []) {
			return null;
		}

		return $declared;
	}//end declaredOn()

	/**
	 * The document a validation reads: BOTH sides of the write.
	 *
	 * So an administered validation can say "this may not change once it is
	 * set" with the same `$before`/`$after` vocabulary a rule uses.
	 * Composition, rather than a second document shape for validations only.
	 *
	 * @param ObjectEntity      $newObject The object as it would be saved.
	 * @param ObjectEntity|null $oldObject The object as stored, null on a create.
	 *
	 * @return array<string, mixed> The document.
	 */
	private function documentOf(ObjectEntity $newObject, ?ObjectEntity $oldObject): array {
		$before = null;
		if ($oldObject !== null) {
			$before = ($oldObject->getObject() ?? []);
		}

		return (new TransitionDocument())->build(after: ($newObject->getObject() ?? []), before: $before);
	}//end documentOf()

	/**
	 * Evaluate the declared validations over the document, in the caller's language.
	 *
	 * @param Schema               $schema   The schema, whose condition library the validations may use.
	 * @param array<string, mixed> $declared The declared validations.
	 * @param array<string, mixed> $document The document to evaluate.
	 *
	 * @return array{refusals: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>} The outcome.
	 */
	private function evaluate(Schema $schema, array $declared, array $document): array {
		$library = $this->checks->conditions->libraryFrom(
			annotation: (($schema->getConfiguration() ?? [])[NamedConditionLibrary::ANNOTATION] ?? null)
		);

		return $this->checks->validations->evaluate(
			annotation: $declared,
			document: $document,
			library: $library,
			language: $this->checks->l10n->getLanguageCode()
		);
	}//end evaluate()

	/**
	 * Put every warning and the first refusal on the run log.
	 *
	 * @param array{refusals: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>} $outcome The outcome.
	 * @param ObjectEntity $object The object being saved.
	 * @param Schema       $schema Its schema.
	 *
	 * @return void
	 */
	private function recordOutcome(array $outcome, ObjectEntity $object, Schema $schema): void {
		foreach ($outcome['warnings'] as $warning) {
			// 🔑 RECORDED, NOT RETURNED — and that is a gap, not a decision.
			// The spec says a warning saves AND returns its message, and the
			// save events carry `setErrors()` and nothing else: there is no
			// warnings channel on a save response to put it in. Writing it to
			// the run log keeps the evaluation honest and visible while the
			// channel is missing, and `tasks.md` names the missing half rather
			// than letting a silent drop look like a feature.
			$this->runLog->recordWarning(object: $object, schema: $schema, entry: $warning);
		}

		if ($outcome['refusals'] !== []) {
			$this->runLog->recordRefusal(object: $object, schema: $schema, entry: $outcome['refusals'][0]);
		}
	}//end recordOutcome()

	/**
	 * The refusal to put on the event, led by the first refusing validation.
	 *
	 * @param array{refusals: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>} $outcome The outcome,
	 *   with at least one refusal.
	 *
	 * @return array<string, mixed> The refusal.
	 */
	private function refusal(array $outcome): array {
		$first = $outcome['refusals'][0];

		return [
			'code' => 'administered-validation-refused',
			'validation' => $first['validation'],
			// Verbatim. The whole row is that a handler reads the sentence
			// somebody wrote.
			'message' => $first['message'],
			'properties' => $first['properties'],
			// Every refusal, not only the first, because a form that can
			// show three problems at once should not make somebody save
			// three times to find them.
			'refusals' => $outcome['refusals'],
			'warnings' => $outcome['warnings'],
		];
	}//end refusal()

	/**
	 * The schema an object refers to, or null when it cannot be resolved.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaOf(ObjectEntity $object): ?Schema {
		$schemaRef = $object->getSchema();
		if ($schemaRef === null || $schemaRef === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find($schemaRef);
		} catch (Throwable $e) {
			$reason = sprintf('Administered validations skipped; schema "%s" could not be resolved: %s', $schemaRef, $e->getMessage());
			$this->logger->warning($reason);
		}

		return null;
	}//end schemaOf()
}//end class
