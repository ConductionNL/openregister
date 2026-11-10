<?php

/**
 * OpenRegister Calculation Payload Builder
 *
 * Builds the evaluation payload the calculation engine consumes: the object's
 * stored data enriched with the synthetic `@self` system-metadata block, the
 * pre-resolved `@ref.<name>` cross-object references
 * (`x-openregister-references`), and the pre-resolved `@aggregate.<name>`
 * aggregate references (`x-openregister-aggregate-refs`).
 *
 * Extracted from {@see \OCA\OpenRegister\Listener\CalculationOnSaveListener}
 * so the save-time materialisation path and the temporal re-evaluation sweep
 * ({@see \OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepService})
 * evaluate calculations against ONE payload shape — a drifted copy would make
 * the sweep recompute different values than the write path persists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Build the enriched payload materialised calculations evaluate against.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
 */
class CalculationPayloadBuilder implements CalculationPayloadBuilderInterface {
	/**
	 * Synthetic payload key listing the declared references that could not be resolved.
	 *
	 * Read through {@see self::unresolvedReferencesUsedBy()} and stripped with
	 * the other synthetic keys; it never reaches stored data.
	 */
	public const UNRESOLVED_KEY = '@unresolvedRef';

	/**
	 * Constructor.
	 *
	 * @param ReferenceResolver $references Cross-object reference pre-resolver.
	 * @param AggregateReferenceResolver $aggregates Aggregate-reference pre-resolver.
	 */
	public function __construct(
		private readonly ReferenceResolver $references,
		private readonly AggregateReferenceResolver $aggregates,
	) {

	}//end __construct()

	/**
	 * Enrich an object's data with `@self`, `@ref` and `@aggregate` blocks.
	 *
	 * Mirrors the save-time listener exactly: `@self` carries the entity's
	 * system metadata (calculations reference `@self.created` etc. via the
	 * evaluator's dotted prop path), `@ref` the pre-resolved declared
	 * cross-object references, `@aggregate` the pre-resolved aggregate
	 * references. Resolution is best-effort and never raises. Callers MUST
	 * strip the synthetic keys before persisting
	 * ({@see self::stripSyntheticKeys()}).
	 *
	 * @param ObjectEntity $object The object whose data to enrich.
	 * @param Schema $schema The object's schema (declares the reference blocks).
	 *
	 * @return array<string, mixed> The enriched evaluation payload.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
	 */
	public function build(ObjectEntity $object, Schema $schema): array {
		$data = (new CalculationPayload())->withSelf(data: ($object->getObject() ?? []), entity: $object);
		$run  = new PayloadBuildRun(object: $object, schema: $schema);
		$data = $this->withReferences(data: $data, run: $run);

		return $this->withAggregates(data: $data, run: $run);
	}//end build()

	/**
	 * Add the pre-resolved declared cross-object references (`@ref`) and the
	 * names of those that could not be resolved.
	 *
	 * @param array<string, mixed> $data The payload with `@self`.
	 * @param PayloadBuildRun      $run  The object being enriched and its schema.
	 *
	 * @return array<string, mixed> The payload, unchanged when the schema declares no references.
	 */
	private function withReferences(array $data, PayloadBuildRun $run): array {
		$references = $this->configBlock(schema: $run->schema, key: 'x-openregister-references');
		if ($references === null) {
			return $data;
		}

		$outcome = $this->references->resolveAllWithOutcome(
			payload: $data,
			references: $references,
			register: $run->object->getRegister(),
			organisation: $run->object->getOrganisation()
		);
		$data['@ref'] = $outcome['refs'];
		$data[self::UNRESOLVED_KEY] = $outcome['unresolved'];

		return $data;
	}//end withReferences()

	/**
	 * Add the pre-resolved aggregate references (`@aggregate`).
	 *
	 * @param array<string, mixed> $data The payload with `@self` and `@ref`.
	 * @param PayloadBuildRun      $run  The object being enriched and its schema.
	 *
	 * @return array<string, mixed> The payload, unchanged when the schema declares no aggregates.
	 */
	private function withAggregates(array $data, PayloadBuildRun $run): array {
		$aggregateRefs = $this->configBlock(schema: $run->schema, key: 'x-openregister-aggregate-refs');
		if ($aggregateRefs !== null) {
			$referrer = new ReferringObject(payload: $data, register: $run->object->getRegister());
			$data['@aggregate'] = $this->aggregates->resolveAll(referrer: $referrer, aggregates: $aggregateRefs);
		}

		return $data;
	}//end withAggregates()

	/**
	 * Strip the synthetic evaluation-only keys from a payload.
	 *
	 * @param array<string, mixed> $data The enriched payload.
	 *
	 * @return array<string, mixed> The payload without `@self` / `@ref` / `@aggregate` / the unresolved list.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
	 */
	public function stripSyntheticKeys(array $data): array {
		unset($data['@self'], $data['@ref'], $data['@aggregate'], $data[self::UNRESOLVED_KEY]);

		return $data;
	}//end stripSyntheticKeys()

	/**
	 * The unresolved references a calculation expression reads.
	 *
	 * A calculation that reads `@ref.<name>` for a reference that could not be
	 * resolved would evaluate against nothing and write null over a good
	 * stored value. Callers skip such a calculation and keep the stored value.
	 *
	 * @param array<string, mixed> $payload The payload {@see self::build()} returned.
	 * @param mixed $expression The calculation's JSON-AST expression.
	 *
	 * @return list<string> The unresolved reference names the expression reads, in first-read order.
	 *
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function unresolvedReferencesUsedBy(array $payload, mixed $expression): array {
		$unresolved = ($payload[self::UNRESOLVED_KEY] ?? []);
		if (is_array($unresolved) === false || $unresolved === []) {
			return [];
		}

		$used = array_values(array_unique($this->referenceNamesIn(expression: $expression)));

		return array_values(array_intersect($used, $unresolved));
	}//end unresolvedReferencesUsedBy()

	/**
	 * Collect every `@ref.<name>` an expression reads.
	 *
	 * @param mixed $expression A JSON-AST node or leaf.
	 *
	 * @return list<string> Reference names, possibly repeated.
	 */
	private function referenceNamesIn(mixed $expression): array {
		if (is_string($expression) === true) {
			return $this->referenceNameOf(token: $expression);
		}

		if (is_array($expression) === false) {
			return [];
		}

		$names = [];
		foreach ($expression as $value) {
			$names = array_merge($names, $this->referenceNamesIn(expression: $value));
		}

		return $names;
	}//end referenceNamesIn()

	/**
	 * The reference a `@ref.<name>` string token reads, if it is one.
	 *
	 * @param string $token A string leaf of the expression.
	 *
	 * @return list<string> The reference name, or empty.
	 */
	private function referenceNameOf(string $token): array {
		if (str_starts_with($token, '@ref.') === false) {
			return [];
		}

		$name = explode('.', $token)[1];
		if ($name === '') {
			return [];
		}

		return [$name];
	}//end referenceNameOf()

	/**
	 * Read a non-empty array block off the schema configuration.
	 *
	 * @param Schema $schema The schema to inspect.
	 * @param string $key The configuration key.
	 *
	 * @return array<string, mixed>|null The block, or null when absent/empty.
	 */
	private function configBlock(Schema $schema, string $key): ?array {
		$config = ($schema->getConfiguration() ?? []);
		$value = ($config[$key] ?? null);
		if (is_array($value) === true && count($value) > 0) {
			return $value;
		}

		return null;
	}//end configBlock()
}//end class
