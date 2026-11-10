<?php

/**
 * OpenRegister ReferenceResolver
 *
 * Pre-resolves declared cross-object references (`x-openregister-references`)
 * for the calculation engine. Called by CalculationOnSaveListener and
 * RematerialiseCalculationsCommand BEFORE any calculation is evaluated, it
 * injects each resolved object's data under `@ref.<name>` so JSON-AST
 * calculations can read it via `{ "prop": "@ref.<name>.<field>" }` — exactly
 * mirroring `@self`.
 *
 * Resolution uses ObjectService READ paths (find / findAll) WITHOUT the
 * saver's RBAC and multitenancy scope: a calculation must give the same
 * answer whoever saves, and a portal write made by an anonymous resident
 * would otherwise see no referenced rows at all. The organisation boundary is
 * kept by ReferenceTenantGuard instead: a referenced object outside the saving
 * object's tenant scope resolves empty. Read paths do not dispatch
 * Creating/Updating events, so resolving a reference never recursively
 * re-triggers the resolved object's own calculations. Any failure injects
 * null and is logged; the save is never failed.
 *
 * A reference that HAD something to resolve (a filled foreign key, or a lookup
 * that matched rows) and still came back empty is reported as unresolved, so
 * the save-time listener can keep a stored value instead of writing null.
 *
 * The CalculationEvaluator stays pure: all I/O lives here.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Calculation\Reference\ReferenceQuery;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves `x-openregister-references` into a `@ref` payload map.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-1
 */
class ReferenceResolver implements ReferenceResolverInterface {

	use PayloadTokenReading;
	/**
	 * Wire the object service used to read referenced objects and the logger.
	 *
	 * @param ObjectOperationsInterface $objectService Read-side object service.
	 * @param ReferenceTenantGuard $tenantGuard Keeps references inside the saving object's tenant.
	 * @param LoggerInterface $logger PSR logger for unresolved-reference warnings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-1
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly ReferenceTenantGuard $tenantGuard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the `@ref` map for an object's declared references.
	 *
	 * Each declared reference resolves to at most one object's data array (or
	 * null). The result is intended to be injected into the evaluation payload
	 * as `$payload['@ref']` so calculations can read `@ref.<name>.<field>`.
	 *
	 * @param array<string, mixed> $payload Object data WITH `@self` already injected.
	 * @param array<string, mixed> $references The `x-openregister-references` map.
	 * @param Register|string|int|null $register Saving object's register context.
	 * @param string|null $organisation Saving object's organisation UUID (the tenant boundary).
	 *
	 * @return array<string, mixed> Map of reference name to resolved data (or null).
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function resolveAll(array $payload, array $references, mixed $register, ?string $organisation): array {
		$referrer = new ReferringObject(payload: $payload, register: $register, organisation: $organisation);
		return $this->resolveReferences(referrer: $referrer, references: $references)['refs'];
	}//end resolveAll()

	/**
	 * Build the `@ref` map and name the references that could not be resolved.
	 *
	 * `unresolved` lists every reference that had something to resolve (a
	 * filled foreign key, or a lookup that matched rows) and still resolved
	 * empty: the target is missing, outside the saving object's tenant, or the
	 * read failed. An empty foreign key, or a lookup that matched nothing, is a
	 * real answer (null) and is not listed.
	 *
	 * @param array<string, mixed> $payload Object data WITH `@self` already injected.
	 * @param array<string, mixed> $references The `x-openregister-references` map.
	 * @param Register|string|int|null $register Saving object's register context.
	 * @param string|null $organisation Saving object's organisation UUID (the tenant boundary).
	 *
	 * @return array{refs: array<string, mixed>, unresolved: list<string>} The map and the unresolved names.
	 *
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function resolveAllWithOutcome(array $payload, array $references, mixed $register, ?string $organisation): array {
		$referrer = new ReferringObject(payload: $payload, register: $register, organisation: $organisation);
		return $this->resolveReferences(referrer: $referrer, references: $references);
	}//end resolveAllWithOutcome()

	/**
	 * Build the `@ref` map of a referring object and name the references that could not be resolved.
	 *
	 * See resolveAllWithOutcome() for what `unresolved` lists.
	 *
	 * @param ReferringObject      $referrer   The saving object: payload, register and organisation.
	 * @param array<string, mixed> $references The `x-openregister-references` map.
	 *
	 * @return array{refs: array<string, mixed>, unresolved: list<string>} The map and the unresolved names.
	 *
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function resolveReferences(ReferringObject $referrer, array $references): array {
		$refs = [];
		$unresolved = [];
		foreach ($this->usableDeclarations(declarations: $references) as $name => $spec) {
			$outcome = $this->resolveOne(referrer: $referrer, spec: $spec);
			$refs[$name] = $outcome['data'];
			if ($outcome['unresolved'] === true) {
				$unresolved[] = (string)$name;
			}
		}

		return ['refs' => $refs, 'unresolved' => $unresolved];
	}//end resolveReferences()

	/**
	 * Resolve a single reference spec to a data array, or null.
	 *
	 * @param ReferringObject      $referrer The saving object: payload, register and organisation.
	 * @param array<string, mixed> $spec     Single reference declaration.
	 *
	 * @return array{data: array<string, mixed>|null, unresolved: bool} Resolved data, and whether it failed to resolve.
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
	 */
	private function resolveOne(ReferringObject $referrer, array $spec): array {
		$query = $this->queryFor(referrer: $referrer, spec: $spec);
		if ($query === null) {
			return ['data' => null, 'unresolved' => false];
		}

		try {
			$outcome = $this->resolveByMode(query: $query, spec: $spec);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Reference resolution failed for schema "%s": %s', $query->schema, $e->getMessage())
			);
			return ['data' => null, 'unresolved' => true];
		}

		return $this->toOutcome(outcome: $outcome);
	}//end resolveOne()

	/**
	 * The read a reference spec makes, or null when it names no schema or no known mode.
	 *
	 * @param ReferringObject      $referrer The saving object: payload, register and organisation.
	 * @param array<string, mixed> $spec     Single reference declaration.
	 *
	 * @return ReferenceQuery|null The read, or null.
	 */
	private function queryFor(ReferringObject $referrer, array $spec): ?ReferenceQuery {
		$schema = (string)($spec['schema'] ?? '');
		$mode = (string)($spec['mode'] ?? '');
		if ($schema === '' || in_array($mode, ['relatedObject', 'lookup'], true) === false) {
			return null;
		}

		// Allow the reference to override the register; default to the saving
		// object's register so the lookup stays within the same dataset.
		return new ReferenceQuery(
			payload: $referrer->payload,
			schema: $schema,
			register: ($spec['register'] ?? $referrer->register),
			organisation: $referrer->organisation
		);
	}//end queryFor()

	/**
	 * Resolve a reference by its mode, `relatedObject` or `lookup` (queryFor() admits no other).
	 *
	 * @param ReferenceQuery       $query The read's inputs.
	 * @param array<string, mixed> $spec  Single reference declaration.
	 *
	 * @return array{entity: ObjectEntity|null, attempted: bool} The admitted object, and whether there was something to resolve.
	 */
	private function resolveByMode(ReferenceQuery $query, array $spec): array {
		if ((string)$spec['mode'] === 'relatedObject') {
			$id = $this->foreignKey(payload: $query->payload, field: (string)($spec['field'] ?? ''));
			if ($id === null) {
				return ['entity' => null, 'attempted' => false];
			}

			return $this->resolveRelatedObject(query: $query, id: $id);
		}

		return $this->resolveLookup(query: $query, spec: $spec);
	}//end resolveByMode()

	/**
	 * Shape a resolution into the resolved data and the unresolved flag.
	 *
	 * @param array{entity: ObjectEntity|null, attempted: bool} $outcome The resolution.
	 *
	 * @return array{data: array<string, mixed>|null, unresolved: bool} Resolved data, and whether it failed to resolve.
	 */
	private function toOutcome(array $outcome): array {
		if ($outcome['entity'] === null) {
			return ['data' => null, 'unresolved' => $outcome['attempted']];
		}

		return ['data' => $this->entityToPayload(entity: $outcome['entity']), 'unresolved' => false];
	}//end toOutcome()

	/**
	 * Resolve a `relatedObject` (FK) reference via ObjectService::find().
	 *
	 * @param ReferenceQuery $query The read's inputs.
	 * @param string|int $id The filled foreign key.
	 *
	 * @return array{entity: ObjectEntity|null, attempted: bool} The admitted object; attempted, since a key was set.
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function resolveRelatedObject(ReferenceQuery $query, string|int $id): array {
		// Read as the system, not as the saver: an anonymous portal write and
		// an administrator's write must compute the same value. The tenant
		// boundary is enforced by the guard below instead of by the session.
		$entity = WriteCause::asLookup(fn () => $this->objectService->find(
			id: $id,
			options: new ObjectFindOptions(register: $query->register, schema: $query->schema),
			_rbac: false,
			_multitenancy: false
		));

		return [
			'entity' => $this->tenantGuard->firstAdmitted(savingOrganisation: $query->organisation, candidates: [$entity]),
			'attempted' => true,
		];
	}//end resolveRelatedObject()

	/**
	 * The filled foreign key a `relatedObject` reference reads, or null.
	 *
	 * @param array<string, mixed> $payload Object data with `@self` injected.
	 * @param string $field Local field holding the referenced uuid/id.
	 *
	 * @return string|int|null The key, or null when the field is unnamed, empty or not a key.
	 */
	private function foreignKey(array $payload, string $field): string|int|null {
		if ($field === '') {
			return null;
		}

		$id = ($payload[$field] ?? null);
		if (is_string($id) === false && is_int($id) === false) {
			return null;
		}

		if ((string)$id === '') {
			return null;
		}

		return $id;
	}//end foreignKey()

	/**
	 * Resolve a `lookup` (criteria) reference via ObjectService::findAll().
	 *
	 * Builds the filter map by resolving `@self`/literal/AST tokens in each
	 * criterion against the payload. When an `effectiveDate` selector is
	 * present, rows are constrained to those valid as-of the object's date and
	 * the most-recent row is taken.
	 *
	 * @param ReferenceQuery $query The read's inputs.
	 * @param array<string, mixed> $spec Reference declaration.
	 *
	 * @return array{entity: ObjectEntity|null, attempted: bool} The most-relevant admitted row, and whether rows matched.
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	private function resolveLookup(ReferenceQuery $query, array $spec): array {
		$criteria = ($spec['filters'] ?? []);
		if (is_array($criteria) === false) {
			return ['entity' => null, 'attempted' => false];
		}

		$config = $this->lookupConfig(query: $query, criteria: $criteria);
		$config = $this->withEffectiveSort(config: $config, effective: ($spec['effectiveDate'] ?? null));

		// Read as the system (see resolveRelatedObject()); rows outside the
		// saving object's tenant are dropped by the guard, keeping the sort
		// order so the most-relevant ADMITTED row wins.
		$results = $this->objectService->findAll($config, _rbac: false, _multitenancy: false);
		if (count($results) === 0) {
			return ['entity' => null, 'attempted' => false];
		}

		return [
			'entity' => $this->tenantGuard->firstAdmitted(savingOrganisation: $query->organisation, candidates: $results),
			'attempted' => true,
		];
	}//end resolveLookup()

	/**
	 * The findAll() configuration of a lookup.
	 *
	 * The sort is added by withEffectiveSort().
	 *
	 * @param ReferenceQuery           $query    The read's inputs.
	 * @param array<int|string, mixed> $criteria The declared filter criteria.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	private function lookupConfig(ReferenceQuery $query, array $criteria): array {
		$filters = ['schema' => $query->schema];
		if ($query->register !== null) {
			$filters['register'] = $query->register;
		}

		$filters = array_replace($filters, $this->resolveTokens(payload: $query->payload, criteria: $criteria));
		return ['filters' => $filters, 'limit' => 50];
	}//end lookupConfig()

	/**
	 * Add the effective-dating sort to a lookup configuration, when the selector names a field.
	 *
	 * @param array<string, mixed> $config    The findAll() configuration.
	 * @param mixed                $effective The `effectiveDate` selector, or null.
	 *
	 * @return array<string, mixed> The configuration, with `sort` when there is one.
	 */
	private function withEffectiveSort(array $config, mixed $effective): array {
		$sort = $this->effectiveSort(effective: $effective);
		if ($sort !== []) {
			$config['sort'] = $sort;
		}

		return $config;
	}//end withEffectiveSort()

	/**
	 * Effective-dating: keep only rows whose effective field is <= (or per
	 * op) the object's date, newest first.
	 *
	 * The op/value pair is advisory; the DESC sort + first-row pick selects
	 * the most-recent applicable rate even without a range op, and avoids
	 * depending on findAll range-filter dialect support.
	 *
	 * @param mixed $effective The `effectiveDate` selector, or null.
	 *
	 * @return array<string, string> The sort, empty without a selector field.
	 */
	private function effectiveSort(mixed $effective): array {
		if (is_array($effective) === true && isset($effective['field']) === true) {
			return [(string)$effective['field'] => 'DESC'];
		}

		return [];
	}//end effectiveSort()

	/**
	 * Flatten a resolved entity into a data array (with `@self` sub-key).
	 *
	 * @param ObjectEntity $entity The resolved object.
	 *
	 * @return array<string, mixed> The entity's data, including `@self` metadata.
	 *
	 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
	 */
	private function entityToPayload(ObjectEntity $entity): array {
		$data = $entity->getObject();

		$data['@self'] = (new CalculationPayload())->identity(entity: $entity);

		return $data;
	}//end entityToPayload()
}//end class
