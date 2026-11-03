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
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IL10N;
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
	 * @param SchemaMapper                 $schemaMapper The schema lookup.
	 * @param AdministeredValidations      $validations  The declared checks.
	 * @param NamedConditionLibrary        $conditions   The named-condition vocabulary.
	 * @param AdministeredValidationRunLog $runLog       Records what a validation decided.
	 * @param IL10N                        $l10n         The caller's language.
	 * @param LoggerInterface              $logger       The logger.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly AdministeredValidations $validations,
		private readonly NamedConditionLibrary $conditions,
		private readonly AdministeredValidationRunLog $runLog,
		private readonly IL10N $l10n,
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
	public function refusalFor(
		ObjectEntity $newObject,
		?ObjectEntity $oldObject,
	): ?array {
		$schema = $this->loadSchema(object: $newObject);
		if ($schema === null) {
			return null;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$declared = ($configuration[AdministeredValidations::ANNOTATION] ?? null);
		if (is_array($declared) === false || $declared === []) {
			// No validations declared: every schema saved before this change
			// takes this exit, and pays one array lookup for it.
			return null;
		}

		// The document carries BOTH sides of the write, so an administered
		// validation can say "this may not change once it is set" with the same
		// `$before`/`$after` vocabulary a rule uses. Composition, rather than a
		// second document shape for validations only.
		$before = null;
		if ($oldObject !== null) {
			$before = ($oldObject->getObject() ?? []);
		}

		$document = (new TransitionDocument())->build(
			after: ($newObject->getObject() ?? []),
			before: $before
		);

		$outcome = $this->validations->evaluate(
			annotation: $declared,
			document: $document,
			library: $this->conditions->libraryFrom(
				annotation: ($configuration[NamedConditionLibrary::ANNOTATION] ?? null)
			),
			language: $this->l10n->getLanguageCode()
		);

		foreach ($outcome['warnings'] as $warning) {
			// 🔑 RECORDED, NOT RETURNED — and that is a gap, not a decision.
			// The spec says a warning saves AND returns its message, and the
			// save events carry `setErrors()` and nothing else: there is no
			// warnings channel on a save response to put it in. Writing it to
			// the run log keeps the evaluation honest and visible while the
			// channel is missing, and `tasks.md` names the missing half rather
			// than letting a silent drop look like a feature.
			$this->runLog->recordWarning(object: $newObject, schema: $schema, entry: $warning);
		}

		if ($outcome['refusals'] === []) {
			return null;
		}

		$first = $outcome['refusals'][0];
		$this->runLog->recordRefusal(object: $newObject, schema: $schema, entry: $first);

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
	}//end refusalFor()

	/**
	 * The schema an object refers to, or null when it cannot be resolved.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null The schema.
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$schemaRef = $object->getSchema();
		if ($schemaRef === null || $schemaRef === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find($schemaRef);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Administered validations skipped; schema "%s" could not be resolved: %s', $schemaRef, $e->getMessage())
			);
			return null;
		}
	}//end loadSchema()
}//end class
