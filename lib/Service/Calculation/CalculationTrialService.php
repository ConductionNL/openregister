<?php

/**
 * OpenRegister CalculationTrialService
 *
 * Evaluates a calculation declaration that has not been saved, against a
 * sample payload or a named object, and returns the value or the error. No
 * schema is written and no object is written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
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

namespace OCA\OpenRegister\Service\Calculation;

use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Try an expression before you save it.
 *
 * An expression that can only be tested by saving the schema and then saving
 * an object is an expression nobody experiments with. This runs the same
 * validator and the same evaluator the save path runs, against a payload the
 * author supplies or an object they name, and writes nothing either way.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The trial composes the validator, the
 *   evaluator, the shared payload builder and the three mappers needed to resolve a named
 *   object; each is a distinct collaborator on the one dry-run path.
 */
final class CalculationTrialService {

	/**
	 * The synthetic name a trial declaration is validated under.
	 */
	private const TRIAL_NAME = '__trial';

	/**
	 * Wire the validator, the evaluator and the lookups a named object needs.
	 *
	 * @param CalculationAnnotationValidator $validator Declaration validator.
	 * @param CalculationEvaluator $evaluator Pure expression evaluator.
	 * @param CalculationPayloadBuilder $payloadBuilder Shared @self/@ref/@aggregate payload prep.
	 * @param RegisterMapper $registerMapper Register lookup for a named object.
	 * @param SchemaMapper $schemaMapper Schema lookup for a named object.
	 * @param MagicMapper $objectMapper Object lookup in the register+schema table.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalculationAnnotationValidator $validator,
		private readonly CalculationEvaluator $evaluator,
		private readonly CalculationPayloadBuilder $payloadBuilder,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $objectMapper,
	) {
	}//end __construct()

	/**
	 * Evaluate an unsaved declaration against a sample payload.
	 *
	 * @param array<string, mixed> $declaration The `{type, expression}` declaration.
	 * @param array<string, mixed> $sample The sample object payload to evaluate against.
	 *
	 * @return array{ok: bool, value?: mixed, dependencies: array<int, string>, error?: array{code: string, message: string}} The trial result.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function trySample(array $declaration, array $sample): array {
		$errors = $this->validateAgainst(declaration: $declaration, availableProperties: array_keys($sample));
		if ($errors !== []) {
			return $this->failure(error: $errors[0], declaration: $declaration);
		}

		return $this->run(declaration: $declaration, payload: $sample);
	}//end trySample()

	/**
	 * Evaluate an unsaved declaration against an object that already exists.
	 *
	 * The object is read, never written: the value comes back in the response
	 * and nothing is persisted.
	 *
	 * @param array<string, mixed> $declaration The `{type, expression}` declaration.
	 * @param string $register Register id, uuid or slug.
	 * @param string $schema Schema id, uuid or slug.
	 * @param string $objectId Object id, uuid, slug or uri.
	 *
	 * @return array{ok: bool, value?: mixed, dependencies: array<int, string>, error?: array{code: string, message: string}} The trial result.
	 *
	 * @spec openspec/changes/computed-values-by-json-ast/specs/computed-fields/spec.md
	 */
	public function tryObject(array $declaration, string $register, string $schema, string $objectId): array {
		try {
			$registerEntity = $this->registerMapper->find($register);
			$schemaEntity = $this->schemaMapper->find($schema);
			$object = $this->objectMapper->findInRegisterSchemaTable(
				identifier: $objectId,
				register: $registerEntity,
				schema: $schemaEntity
			);
		} catch (Throwable $e) {
			return $this->failure(
				error: [
					'code' => 'calculation-trial-object-not-found',
					'message' => sprintf('Could not resolve object "%s": %s', $objectId, $e->getMessage()),
				],
				declaration: $declaration
			);
		}

		$properties = ($schemaEntity->getProperties() ?? []);
		$errors = $this->validateAgainst(declaration: $declaration, availableProperties: array_keys($properties));
		if ($errors !== []) {
			return $this->failure(error: $errors[0], declaration: $declaration);
		}

		$payload = $this->payloadBuilder->build(object: $object, schema: $schemaEntity);

		return $this->run(declaration: $declaration, payload: $payload);
	}//end tryObject()

	/**
	 * Validate the declaration the way a schema save validates it.
	 *
	 * @param array<string, mixed> $declaration The declaration under trial.
	 * @param array<int, string|int> $availableProperties The property names a `prop` node may read.
	 *
	 * @return array<int, array{code: string, message: string}> The validation errors, empty when valid.
	 */
	private function validateAgainst(array $declaration, array $availableProperties): array {
		$properties = [];
		foreach ($availableProperties as $name) {
			$properties[(string)$name] = ['type' => 'string'];
		}

		return $this->validator->validate(
			[
				'properties' => $properties,
				'x-openregister-calculations' => [self::TRIAL_NAME => $declaration],
			]
		);
	}//end validateAgainst()

	/**
	 * Evaluate the declaration and shape the answer.
	 *
	 * The evaluator runs with no sequence context, so a `sequence` node yields
	 * null in a trial rather than burning a running number on an experiment.
	 *
	 * @param array<string, mixed> $declaration The declaration under trial.
	 * @param array<string, mixed> $payload The payload to evaluate against.
	 *
	 * @return array{ok: bool, value?: mixed, dependencies: array<int, string>, error?: array{code: string, message: string}} The trial result.
	 */
	private function run(array $declaration, array $payload): array {
		$expression = ($declaration['expression'] ?? null);

		try {
			$value = $this->evaluator->evaluate($payload, $expression);
		} catch (EvaluationException $e) {
			return $this->failure(
				error: ['code' => 'calculation-trial-failed', 'message' => $e->getMessage()],
				declaration: $declaration
			);
		}

		if ($value instanceof DateTimeInterface) {
			$value = $value->format(DATE_ATOM);
		}

		return [
			'ok' => true,
			'value' => $value,
			'dependencies' => $this->evaluator->referencedProperties($expression),
		];
	}//end run()

	/**
	 * Shape a refusal, carrying the derived dependency list so the author
	 * still sees what the expression reads.
	 *
	 * @param array{code: string, message: string} $error The error to report.
	 * @param array<string, mixed> $declaration The declaration under trial.
	 *
	 * @return array{ok: bool, dependencies: array<int, string>, error: array{code: string, message: string}} The trial result.
	 */
	private function failure(array $error, array $declaration): array {
		return [
			'ok' => false,
			'error' => $error,
			'dependencies' => $this->evaluator->referencedProperties(($declaration['expression'] ?? null)),
		];
	}//end failure()
}//end class
