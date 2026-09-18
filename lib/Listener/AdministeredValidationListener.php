<?php

/**
 * The administrator's own checks, at the pipeline's one evaluation point.
 *
 * 🔑 IT IS A LISTENER ON THE SAME TWO EVENTS AS `StateFieldRuleListener`, and
 * that is the whole of REQ-RCT-005. `rules-engine-operability` established that
 * every write — the object API, an import, a flow node write, a bulk job —
 * funnels through `insertObjectEntity`/`updateObjectEntity`, and that both
 * dispatch these events. Subscribing here means a validation cannot be skipped
 * by a path somebody adds next year, and `RuleEvaluationPointTest` fails and
 * NAMES that path if one tries.
 *
 * 🔴 A REFUSAL CARRIES THE ADMINISTRATOR'S SENTENCE, UNEDITED. Not prefixed,
 * not summarised, not replaced by "Validation failed". The sentence is the
 * point of the row: a generic message teaches people to click past it.
 *
 * 🔴 AND AN UNEVALUABLE CHECK REFUSES. A validation whose condition cannot be
 * resolved has not been satisfied, it has not been ASKED, and treating that as
 * a pass would let one broken named condition switch off every check that used
 * it.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Service\Rules\TransitionDocument;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evaluates a schema's declared validations before the write lands.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
class AdministeredValidationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper            $schemaMapper The schema lookup.
	 * @param AdministeredValidations $validations  The declared checks.
	 * @param NamedConditionLibrary   $conditions   The named-condition vocabulary.
	 * @param RuleRunRecorder         $ruleRuns     The run log.
	 * @param IL10N                   $l10n         The caller's language.
	 * @param LoggerInterface         $logger       The logger.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly AdministeredValidations $validations,
		private readonly NamedConditionLibrary $conditions,
		private readonly RuleRunRecorder $ruleRuns,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Evaluate the declared validations for a create or an update.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->enforce(event: $event, newObject: $event->getObject(), oldObject: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->enforce(event: $event, newObject: $event->getNewObject(), oldObject: $event->getOldObject());
		}
	}//end handle()

	/**
	 * Run the schema's validations and stop the event on a refusal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event     The event.
	 * @param ObjectEntity                            $newObject The object as it would be saved.
	 * @param ObjectEntity|null                       $oldObject The object as stored, null on a create.
	 *
	 * @return void
	 */
	private function enforce(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		ObjectEntity $newObject,
		?ObjectEntity $oldObject,
	): void {
		$schema = $this->loadSchema(object: $newObject);
		if ($schema === null) {
			return;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$declared = ($configuration[AdministeredValidations::ANNOTATION] ?? null);
		if (is_array($declared) === false || $declared === []) {
			// No validations declared: every schema saved before this change
			// takes this exit, and pays one array lookup for it.
			return;
		}

		// The document carries BOTH sides of the write, so an administered
		// validation can say "this may not change once it is set" with the same
		// `$before`/`$after` vocabulary a rule uses. Composition, rather than a
		// second document shape for validations only.
		$document = (new TransitionDocument())->build(
			after: ($newObject->getObject() ?? []),
			before: ($oldObject === null ? null : ($oldObject->getObject() ?? []))
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
			$this->record(object: $newObject, schema: $schema, entry: $warning, verdict: RuleVocabulary::VERDICT_FIRED);
		}

		if ($outcome['refusals'] === []) {
			return;
		}

		$first = $outcome['refusals'][0];
		$this->record(object: $newObject, schema: $schema, entry: $first, verdict: RuleVocabulary::VERDICT_REFUSED);

		$event->setErrors(
			[
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
			]
		);
		$event->stopPropagation();
	}//end enforce()

	/**
	 * Record one validation outcome on the rule run log.
	 *
	 * @param ObjectEntity         $object  The object.
	 * @param Schema               $schema  Its schema.
	 * @param array<string, mixed> $entry   The outcome.
	 * @param string               $verdict The verdict to record.
	 *
	 * @return void
	 */
	private function record(ObjectEntity $object, Schema $schema, array $entry, string $verdict): void {
		$slug = (string)($schema->getSlug() ?? '');
		$name = (string)($entry['validation'] ?? '');
		if ($slug === '' || $name === '') {
			return;
		}

		try {
			$this->ruleRuns->record(
				ruleId: RuleDescriptor::idFor(
					kind: RuleVocabulary::KIND_ADMINISTERED_VALIDATION,
					schemaSlug: $slug,
					key: $name
				),
				schemaSlug: $slug,
				trace: new RuleTrace(
					verdict: (($entry['unevaluable'] ?? false) === true ? RuleVocabulary::VERDICT_ERROR : $verdict),
					operand: implode(', ', ($entry['properties'] ?? [])),
					message: (string)($entry['message'] ?? '')
				),
				objectUuid: ($object->getUuid() ?? null),
				registerSlug: ($object->getRegister() ?? null)
			);
		} catch (Throwable $e) {
			// The run log is a courtesy on a decision already taken. Losing the
			// row must never turn a refusal into a 500.
			$this->logger->warning(
				sprintf('Administered validation run could not be recorded: %s', $e->getMessage())
			);
		}
	}//end record()

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
