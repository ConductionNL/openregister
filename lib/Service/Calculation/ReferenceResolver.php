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
 * Resolution uses ObjectService READ paths (find / findAll) under the saver's
 * existing RBAC + multitenancy scope. Read paths do not dispatch
 * Creating/Updating events, so resolving a reference never recursively
 * re-triggers the resolved object's own calculations. Any failure injects
 * null and is logged; the save is never failed.
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

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves `x-openregister-references` into a `@ref` payload map.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-1
 */
class ReferenceResolver
{
    /**
     * Wire the object service used to read referenced objects and the logger.
     *
     * @param ObjectService   $objectService Read-side object service (RBAC + tenant scoped).
     * @param LoggerInterface $logger        PSR logger for unresolved-reference warnings.
     *
     * @return void
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-1
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Build the `@ref` map for an object's declared references.
     *
     * Each declared reference resolves to at most one object's data array (or
     * null). The result is intended to be injected into the evaluation payload
     * as `$payload['@ref']` so calculations can read `@ref.<name>.<field>`.
     *
     * @param array<string, mixed>     $payload    Object data WITH `@self` already injected.
     * @param array<string, mixed>     $references The `x-openregister-references` map.
     * @param Register|string|int|null $register   Saving object's register context.
     *
     * @return array<string, mixed> Map of reference name to resolved data (or null).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
     */
    public function resolveAll(array $payload, array $references, mixed $register=null): array
    {
        $refs = [];
        foreach ($references as $name => $spec) {
            if ((string) $name === '' || is_array($spec) === false) {
                continue;
            }

            $refs[$name] = $this->resolveOne(payload: $payload, spec: $spec, register: $register);
        }

        return $refs;
    }//end resolveAll()

    /**
     * Resolve a single reference spec to a data array, or null.
     *
     * @param array<string, mixed>     $payload  Object data with `@self` injected.
     * @param array<string, mixed>     $spec     Single reference declaration.
     * @param Register|string|int|null $register Saving object's register context.
     *
     * @return array<string, mixed>|null Resolved object data, or null.
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
     */
    private function resolveOne(array $payload, array $spec, mixed $register): ?array
    {
        $schema = (string) ($spec['schema'] ?? '');
        $mode   = (string) ($spec['mode'] ?? '');
        if ($schema === '' || ($mode !== 'relatedObject' && $mode !== 'lookup')) {
            return null;
        }

        // Allow the reference to override the register; default to the saving
        // object's register so the lookup stays within the same dataset.
        $refRegister = ($spec['register'] ?? $register);
        $entity      = null;

        try {
            if ($mode === 'relatedObject') {
                $entity = $this->resolveRelatedObject(
                    payload: $payload,
                    field: (string) ($spec['field'] ?? ''),
                    schema: $schema,
                    register: $refRegister
                );
            }

            if ($mode === 'lookup') {
                $entity = $this->resolveLookup(
                    payload: $payload,
                    spec: $spec,
                    schema: $schema,
                    register: $refRegister
                );
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Reference resolution failed for schema "%s": %s', $schema, $e->getMessage())
            );
            return null;
        }//end try

        if ($entity === null) {
            return null;
        }

        return $this->entityToPayload(entity: $entity);
    }//end resolveOne()

    /**
     * Resolve a `relatedObject` (FK) reference via ObjectService::find().
     *
     * @param array<string, mixed>     $payload  Object data with `@self` injected.
     * @param string                   $field    Local field holding the referenced uuid/id.
     * @param string                   $schema   Target schema reference.
     * @param Register|string|int|null $register Register context.
     *
     * @return ObjectEntity|null The referenced object, or null.
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
     */
    private function resolveRelatedObject(array $payload, string $field, string $schema, mixed $register): ?ObjectEntity
    {
        if ($field === '') {
            return null;
        }

        $id = ($payload[$field] ?? null);
        if (is_string($id) === false && is_int($id) === false) {
            return null;
        }

        if ((string) $id === '') {
            return null;
        }

        // RBAC + multitenancy stay at their default `true` — the lookup runs
        // under the saving user's scope and can never leak cross-tenant data.
        return $this->objectService->find(
            id: $id,
            register: $register,
            schema: $schema,
            _rbac: true,
            _multitenancy: true
        );
    }//end resolveRelatedObject()

    /**
     * Resolve a `lookup` (criteria) reference via ObjectService::findAll().
     *
     * Builds the filter map by resolving `@self`/literal/AST tokens in each
     * criterion against the payload. When an `effectiveDate` selector is
     * present, rows are constrained to those valid as-of the object's date and
     * the most-recent row is taken.
     *
     * @param array<string, mixed>     $payload  Object data with `@self` injected.
     * @param array<string, mixed>     $spec     Reference declaration.
     * @param string                   $schema   Target schema reference.
     * @param Register|string|int|null $register Register context.
     *
     * @return ObjectEntity|null The most-relevant matching object, or null.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
     */
    private function resolveLookup(array $payload, array $spec, string $schema, mixed $register): ?ObjectEntity
    {
        $criteria = ($spec['filters'] ?? []);
        if (is_array($criteria) === false) {
            return null;
        }

        $filters = [
            'schema' => $schema,
        ];
        if ($register !== null) {
            $filters['register'] = $register;
        }

        foreach ($criteria as $target => $criterion) {
            $filters[(string) $target] = $this->resolveToken(payload: $payload, token: $criterion);
        }

        $sort = [];

        // Effective-dating: keep only rows whose effective field is <= (or per
        // op) the object's date, newest first.
        $effective = ($spec['effectiveDate'] ?? null);
        if (is_array($effective) === true && isset($effective['field']) === true) {
            $effField        = (string) $effective['field'];
            $sort[$effField] = 'DESC';
            // The op/value pair is advisory; the DESC sort + first-row pick
            // selects the most-recent applicable rate even without a range op,
            // and avoids depending on findAll range-filter dialect support.
        }

        $config = [
            'filters' => $filters,
            'limit'   => 50,
        ];
        if (count($sort) > 0) {
            $config['sort'] = $sort;
        }

        $results = $this->objectService->findAll($config, _rbac: true, _multitenancy: true);
        if (count($results) === 0) {
            return null;
        }

        $first = $results[array_key_first($results)];
        if ($first instanceof ObjectEntity) {
            return $first;
        }

        return null;
    }//end resolveLookup()

    /**
     * Resolve a criterion token to a concrete value.
     *
     * Supported tokens (kept deliberately small to avoid coupling to the pure
     * CalculationEvaluator):
     * - A literal scalar (passed through unchanged).
     * - A `@self.<field>` / `@ref`-prefixed dotted-path string, resolved against the payload.
     * - A single-key AST node `{ "year": <token> }` — only `year` is supported
     *   here, mirroring the lookup criteria used by rate tables.
     *
     * @param array<string, mixed> $payload Object data with `@self` injected.
     * @param mixed                $token   The criterion token.
     *
     * @return mixed The resolved value.
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
     */
    private function resolveToken(array $payload, mixed $token): mixed
    {
        if (is_string($token) === true) {
            if (str_starts_with($token, '@self.') === true || str_starts_with($token, '@ref.') === true) {
                return $this->readPath(payload: $payload, path: $token);
            }

            return $token;
        }

        if (is_array($token) === true && count($token) === 1) {
            $op = (string) array_key_first($token);
            if ($op === 'year') {
                return $this->yearToken(payload: $payload, arg: $token[$op]);
            }
        }

        return $token;
    }//end resolveToken()

    /**
     * Resolve a `{ "year": <token> }` criterion to a four-digit year integer.
     *
     * @param array<string, mixed> $payload Object data with `@self` injected.
     * @param mixed                $arg     The inner token (typically a `@self.<date>` reference).
     *
     * @return int|null The year, or null when the inner token is not a parseable date.
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
     */
    private function yearToken(array $payload, mixed $arg): ?int
    {
        $raw = $this->resolveToken(payload: $payload, token: $arg);
        if (is_string($raw) === false || $raw === '') {
            return null;
        }

        try {
            return (int) (new DateTimeImmutable($raw))->format('Y');
        } catch (Throwable) {
            return null;
        }
    }//end yearToken()

    /**
     * Read a dotted path (e.g. `@self.journeyDate`) from the payload.
     *
     * @param array<string, mixed> $payload Object data.
     * @param string               $path    Dotted path.
     *
     * @return mixed The resolved value, or null when the path is missing.
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
     */
    private function readPath(array $payload, string $path): mixed
    {
        $parts = explode('.', $path);

        // `@self.<field>` may address either the injected `@self` system
        // metadata (id/created/…) OR — the common case for lookup criteria —
        // one of the object's OWN data fields (e.g. `@self.journeyDate`). The
        // listener injects user data at the top level of the payload, not under
        // `@self`, so when `@self.<field>` is not a system field fall back to
        // the top-level field. This makes the lookup parameterisable by the
        // saving object exactly as the schema author expects.
        if ($parts[0] === '@self' && count($parts) === 2) {
            $field = $parts[1];
            $self  = ($payload['@self'] ?? []);
            if (is_array($self) === true && array_key_exists($field, $self) === true) {
                return $self[$field];
            }

            return ($payload[$field] ?? null);
        }

        $current = $payload;
        foreach ($parts as $part) {
            if (is_array($current) === false || array_key_exists($part, $current) === false) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }//end readPath()

    /**
     * Flatten a resolved entity into a data array (with `@self` sub-key).
     *
     * @param ObjectEntity $entity The resolved object.
     *
     * @return array<string, mixed> The entity's data, including `@self` metadata.
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
     */
    private function entityToPayload(ObjectEntity $entity): array
    {
        $data = $entity->getObject();

        $data['@self'] = [
            'id'       => $entity->getUuid(),
            'uuid'     => $entity->getUuid(),
            'register' => $entity->getRegister(),
            'schema'   => $entity->getSchema(),
            'owner'    => $entity->getOwner(),
        ];

        return $data;
    }//end entityToPayload()
}//end class
