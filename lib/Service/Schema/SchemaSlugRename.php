<?php

/**
 * SchemaSlugRename — the `renameSchema` migration operation.
 *
 * Renames a schema's slug as one recorded migration run: the old slug joins the
 * schema's `formerSlugs`, and every `$ref` and `items.$ref` in other schemas
 * that named the old slug is rewritten to the new one. Objects do not change:
 * they point at their schema by id. Rolling the run back restores the slug,
 * the former slugs and the rewritten references.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunMapperInterface;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * Plans, runs and rolls back a schema slug rename.
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
 */
final class SchemaSlugRename {

	/**
	 * The op of a slug rename in a migration plan.
	 *
	 * @var string
	 */
	public const OP = 'renameSchema';

	/**
	 * The report key a slug rename run keeps what it changed under.
	 *
	 * @var string
	 */
	public const REPORT_KEY = 'renameSchema';

	/**
	 * Run persistence.
	 *
	 * @var SchemaRunMapperInterface
	 */
	private readonly SchemaRunMapperInterface $runMapper;

	/**
	 * Schema lookup and persistence.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Object counts for the preview.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param SchemaPopulationRuns $runs The run ledger, the schema lookup and the object population.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
	 */
	public function __construct(SchemaPopulationRuns $runs) {
		$this->runMapper     = $runs->runMapper;
		$this->schemaMapper  = $runs->schemaMapper;
		$this->objectService = $runs->objectService;
	}//end __construct()

	/**
	 * Whether a plan asks for a slug rename.
	 *
	 * @param array<int|string, mixed> $plan The transform chain.
	 *
	 * @return bool True when any step is a `renameSchema`.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
	 */
	public static function isSlugRename(array $plan): bool {
		foreach ($plan as $step) {
			if (is_array($step) === true && ($step['op'] ?? null) === self::OP) {
				return true;
			}
		}

		return false;
	}//end isSlugRename()

	/**
	 * The problems with a slug rename plan; empty when it is valid.
	 *
	 * A slug rename runs alone: it changes the schema, not its objects, so it
	 * does not combine with the per-object transforms.
	 *
	 * @param array<int|string, mixed> $plan The transform chain.
	 *
	 * @return array<int, string> The problems.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
	 */
	public static function problems(array $plan): array {
		if (count($plan) !== 1) {
			return ['A renameSchema transform must be the only transform in its plan.'];
		}

		$step = array_values($plan)[0];
		$from = self::slugOf(value: ($step['from'] ?? null));
		$to   = self::slugOf(value: ($step['to'] ?? null));
		if ($from === '' || $to === '') {
			return ['renameSchema transform #0 requires "from" and "to".'];
		}

		if ($from === $to) {
			return ['renameSchema transform #0 renames a slug to itself.'];
		}

		return [];
	}//end problems()

	/**
	 * What a slug rename would change, without changing it.
	 *
	 * @param MigrationRequest $request The schema, its register and the plan.
	 *
	 * @return array<string, mixed> The op, the slugs, the objects of the schema and the schemas whose references change.
	 *
	 * @throws InvalidArgumentException When the plan cannot run against this schema.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
	 */
	public function preview(MigrationRequest $request): array {
		[$schema, $from, $to] = $this->checkedRename(request: $request);

		$schemas = [];
		foreach ($this->referenceRewrites(schema: $schema, from: $from, to: $to) as $rewrite) {
			$schemas[] = [
				'id' => $rewrite['schema']->getId(),
				'slug' => $rewrite['schema']->getSlug(),
				'paths' => array_map(static fn (array $change): string => implode('.', $change['path']), $rewrite['paths']),
			];
		}

		return [
			'op' => self::OP,
			'from' => $from,
			'to' => $to,
			'objects' => $this->countObjects(request: $request),
			'schemas' => $schemas,
		];
	}//end preview()

	/**
	 * Rename the slug and rewrite the references to it, recorded as a completed run.
	 *
	 * @param MigrationRequest $request   The schema, its register and the plan.
	 * @param string|null      $startedBy The user starting the rename.
	 *
	 * @return SchemaRun The completed run.
	 *
	 * @throws InvalidArgumentException When the plan cannot run against this schema.
	 * @throws SchemaRunConcurrencyException When another run on the schema is active.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
	 */
	public function run(MigrationRequest $request, ?string $startedBy = null): SchemaRun {
		[$schema, $from, $to] = $this->checkedRename(request: $request);
		if ($this->runMapper->findActiveForSchema($request->schemaId) !== null) {
			throw new SchemaRunConcurrencyException(message: 'An active run already exists for this schema.');
		}

		$rewrites = $this->referenceRewrites(schema: $schema, from: $from, to: $to);
		$refs     = [];
		foreach ($rewrites as $rewrite) {
			$rewrite['schema']->setProperties($rewrite['properties']);
			$this->schemaMapper->update($rewrite['schema']);
			$refs[] = ['schemaId' => (int)$rewrite['schema']->getId(), 'paths' => $rewrite['paths']];
		}

		$formerBefore = $schema->getFormerSlugs();
		$schema->setSlug($to);
		$schema->setFormerSlugs(self::formerSlugsAfter(former: ($formerBefore ?? []), from: $from, to: $to));
		$this->schemaMapper->update($schema);

		return $this->runMapper->createFromArray(
			[
				'schemaId' => $request->schemaId,
				'registerId' => $request->registerId,
				'type' => SchemaRun::TYPE_MIGRATION,
				'state' => SchemaRun::STATE_COMPLETED,
				'plan' => $request->plan,
				'options' => $request->options,
				'total' => 1 + count($refs),
				'processed' => 1 + count($refs),
				'startedBy' => $startedBy,
				'report' => [
					self::REPORT_KEY => ['from' => $from, 'to' => $to, 'formerSlugs' => $formerBefore, 'references' => $refs],
				],
			]
		);
	}//end run()

	/**
	 * Undo a slug rename run: the slug, the former slugs and every rewritten reference.
	 *
	 * A reference that no longer names the new slug was changed since the rename
	 * and is left as it is, counted as a conflict; so is the schema's slug.
	 *
	 * @param SchemaRun $run The slug rename run.
	 *
	 * @return array{restored: int, conflicts: int} What was restored and what was left.
	 *
	 * @throws InvalidArgumentException When the run is not a slug rename.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
	 */
	public function rollback(SchemaRun $run): array {
		$recorded = (($run->getReport() ?? [])[self::REPORT_KEY] ?? null);
		if (is_array($recorded) === false) {
			throw new InvalidArgumentException('This run did not rename a schema.');
		}

		$from  = (string)$recorded['from'];
		$to    = (string)$recorded['to'];
		$tally = ['restored' => 0, 'conflicts' => 0];
		foreach (($recorded['references'] ?? []) as $reference) {
			$this->restoreReferences(reference: $reference, tally: $tally);
		}

		$schema = $this->schemaMapper->find(id: $run->getSchemaId(), _rbac: false, _multitenancy: false);
		if ($schema->getSlug() !== $to) {
			$tally['conflicts']++;
			return $tally;
		}

		$schema->setSlug($from);
		$schema->setFormerSlugs($recorded['formerSlugs'] ?? null);
		$this->schemaMapper->update($schema);
		$tally['restored']++;

		return $tally;
	}//end rollback()

	/**
	 * Rewrite the `$ref` and `items.$ref` of each property that names a slug.
	 *
	 * A reference names the slug bare (`melding`) or as the last segment of a
	 * path (`#/components/schemas/melding`). A reference by id or uuid does
	 * not change: the schema keeps both.
	 *
	 * @param array<int|string, mixed> $properties The schema's properties.
	 * @param string                   $from       The slug a reference names now.
	 * @param string                   $to         The slug it should name.
	 *
	 * @return array{0: array<int|string, mixed>, 1: array<int, array{path: array<int, string>, was: string, now: string}>}
	 *         The properties, and each reference rewritten with its value before and after.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
	 */
	public static function rewriteReferences(array $properties, string $from, string $to): array {
		$changes = [];
		foreach ($properties as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			foreach ([['$ref'], ['items', '$ref']] as $at) {
				$path = array_merge([(string)$name], $at);
				$was  = self::valueAt(properties: $properties, path: $path);
				$now  = self::renamedReference(reference: $was, from: $from, to: $to);
				if ($now !== null) {
					self::setAt(properties: $properties, path: $path, value: $now);
					$changes[] = ['path' => $path, 'was' => (string)$was, 'now' => $now];
				}
			}
		}

		return [$properties, $changes];
	}//end rewriteReferences()

	/**
	 * A reference to the old slug rewritten to the new one; null when it does not name the old slug.
	 *
	 * @param mixed  $reference The `$ref` value.
	 * @param string $from      The old slug.
	 * @param string $to        The new slug.
	 *
	 * @return string|null The rewritten reference.
	 */
	private static function renamedReference(mixed $reference, string $from, string $to): ?string {
		if (is_string($reference) === false) {
			return null;
		}

		if ($reference === $from) {
			return $to;
		}

		if (str_ends_with($reference, '/' . $from) === true) {
			return substr($reference, 0, -strlen($from)) . $to;
		}

		return null;
	}//end renamedReference()

	/**
	 * The former slugs once `from` is renamed to `to`.
	 *
	 * `from` is added at the end; `to` leaves the list, because a current slug
	 * is never also a former one (renaming back to an earlier slug).
	 *
	 * @param array<int, mixed> $former The former slugs before.
	 * @param string            $from   The slug being left.
	 * @param string            $to     The new slug.
	 *
	 * @return array<int, string> The former slugs after.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-record-type-can-be-renamed-without-breaking-its-links
	 */
	public static function formerSlugsAfter(array $former, string $from, string $to): array {
		$after = [];
		foreach ($former as $slug) {
			if (is_string($slug) === true && $slug !== $from && $slug !== $to) {
				$after[] = $slug;
			}
		}

		$after[] = $from;

		return $after;
	}//end formerSlugsAfter()

	/**
	 * The schema and the two slugs of a rename that can run, or the refusal.
	 *
	 * @param MigrationRequest $request The request.
	 *
	 * @return array{0: Schema, 1: string, 2: string} The schema, the old slug and the new slug.
	 *
	 * @throws InvalidArgumentException When the plan is invalid, `from` is not the current slug or `to` is taken.
	 */
	private function checkedRename(MigrationRequest $request): array {
		$problems = self::problems(plan: $request->plan);
		if ($problems !== []) {
			throw new InvalidArgumentException(implode(' ', $problems));
		}

		$step   = array_values($request->plan)[0];
		$from   = self::slugOf(value: $step['from']);
		$to     = self::slugOf(value: $step['to']);
		$schema = $this->schemaMapper->find($request->schemaId);
		if ($schema->getSlug() !== $from) {
			throw new InvalidArgumentException(sprintf('renameSchema: "%s" is not the current slug of this schema.', $from));
		}

		if ($this->schemaMapper->countBySlug($to) > 0) {
			throw new InvalidArgumentException(sprintf('renameSchema: the slug "%s" is already taken.', $to));
		}

		return [$schema, $from, $to];
	}//end checkedRename()

	/**
	 * The other schemas whose references name the old slug, with their rewritten properties.
	 *
	 * Every schema is read, across organisations: a reference that is not
	 * rewritten breaks, whoever owns the schema that holds it.
	 *
	 * @param Schema $schema The schema being renamed.
	 * @param string $from   The old slug.
	 * @param string $to     The new slug.
	 *
	 * @return array<int, array{schema: Schema, properties: array<int|string, mixed>,
	 *     paths: array<int, array{path: array<int, string>, was: string, now: string}>}>
	 */
	private function referenceRewrites(Schema $schema, string $from, string $to): array {
		$rewrites = [];
		foreach ($this->schemaMapper->findAll(_rbac: false, _multitenancy: false) as $candidate) {
			if ((int)$candidate->getId() === (int)$schema->getId()) {
				continue;
			}

			[$properties, $paths] = self::rewriteReferences(properties: $candidate->getProperties(), from: $from, to: $to);
			if ($paths !== []) {
				$rewrites[] = ['schema' => $candidate, 'properties' => $properties, 'paths' => $paths];
			}
		}

		return $rewrites;
	}//end referenceRewrites()

	/**
	 * Put the old reference back on each recorded path that still holds the rewritten one.
	 *
	 * @param mixed                                $reference The recorded schema id and changes.
	 * @param array{restored: int, conflicts: int} $tally     The counts so far (updated in place).
	 *
	 * @return void
	 */
	private function restoreReferences(mixed $reference, array &$tally): void {
		if (is_array($reference) === false) {
			return;
		}

		try {
			$schema = $this->schemaMapper->find(id: (int)($reference['schemaId'] ?? 0), _rbac: false, _multitenancy: false);
		} catch (\Throwable $gone) {
			// A schema deleted since the rename has no reference left to restore.
			unset($gone);
			$tally['conflicts']++;
			return;
		}

		$properties = $schema->getProperties();
		$changed    = false;
		foreach (($reference['paths'] ?? []) as $change) {
			$path = ($change['path'] ?? null);
			if (is_array($path) === true && self::valueAt(properties: $properties, path: $path) === ($change['now'] ?? null)) {
				self::setAt(properties: $properties, path: $path, value: (string)($change['was'] ?? ''));
				$changed = true;
				$tally['restored']++;
				continue;
			}

			$tally['conflicts']++;
		}

		if ($changed === true) {
			$schema->setProperties($properties);
			$this->schemaMapper->update($schema);
		}
	}//end restoreReferences()

	/**
	 * The value at a path in the properties, or null when the path is not there.
	 *
	 * @param array<int|string, mixed> $properties The properties.
	 * @param array<int, mixed>        $path       The keys.
	 *
	 * @return mixed The value.
	 */
	private static function valueAt(array $properties, array $path): mixed {
		$value = $properties;
		foreach ($path as $key) {
			if (is_array($value) === false || array_key_exists((string)$key, $value) === false) {
				return null;
			}

			$value = $value[(string)$key];
		}

		return $value;
	}//end valueAt()

	/**
	 * Set the value at a path that exists in the properties.
	 *
	 * @param array<int|string, mixed> $properties The properties (updated in place).
	 * @param array<int, mixed>        $path       The keys.
	 * @param string                   $value      The value.
	 *
	 * @return void
	 */
	private static function setAt(array &$properties, array $path, string $value): void {
		$node = &$properties;
		foreach ($path as $key) {
			$node = &$node[(string)$key];
		}

		$node = $value;
	}//end setAt()

	/**
	 * A slug from a plan value; '' when it is not a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The slug.
	 */
	private static function slugOf(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end slugOf()

	/**
	 * How many objects the renamed schema holds in its register; 0 when they cannot be counted.
	 *
	 * @param MigrationRequest $request The request.
	 *
	 * @return int The count.
	 */
	private function countObjects(MigrationRequest $request): int {
		try {
			return $this->objectService->count(config: ['filters' => ['register' => $request->registerId, 'schema' => $request->schemaId]]);
		} catch (\Throwable $e) {
			unset($e);
			return 0;
		}
	}//end countObjects()
}//end class
