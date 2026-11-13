<?php

/**
 * Reset one part of one schema to what its app shipped, as an administrator's act.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The one caller of the guard's reset: the occ command and the admin route both go through here.
 *
 * One part, named. The guard cannot tell a rule the instance never received
 * from one a municipality removed on purpose (learniq D13), so nothing heals by
 * itself and nothing resets "everything": an administrator names the schema and
 * the part, sees the change, and applies it.
 *
 * Only that part is written. The guard compares normalised parts (scalar
 * lists sorted, keys sorted); writing its whole reset definition back would
 * reorder every enum and every other local list. So the stored definition is
 * kept as it is and only the leaf at the path is replaced or removed.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
class ShippedBaselineResetService implements ShippedBaselineResetServiceInterface {

	/**
	 * The schema parts the guard keeps a baseline of.
	 *
	 * @var array<int, string>
	 */
	public const GUARDED_KEYS = ['properties', 'required', 'authorization', GuardedAnnotations::PART];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface              $schemas Where the schema lives.
	 * @param ShippedConfigurationGuard $guard   The baseline and the reset rules.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly ShippedConfigurationGuard $guard,
	) {
	}//end __construct()

	/**
	 * What resetting one part would change. Writes nothing.
	 *
	 * @param string|int $schema The schema id, uuid or slug.
	 * @param string     $path   The part, as a dotted path (`authorization.read`).
	 *
	 * @return array{applicable: bool, reason: string, schema: string, schemaId: int|null, path: string, from: mixed, to: mixed}
	 *         The preview.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function preview(string|int $schema, string $path): array {
		$path = trim($path);
		$entity = $this->schemas->find(id: $schema, _rbac: false, _multitenancy: false);
		$slug = (string)($entity->getSlug() ?? '');

		$refusal = $this->refusal(slug: $slug, path: $path);
		if ($refusal !== null) {
			return $this->previewShape(entity: $entity, preview: ['applicable' => false, 'reason' => $refusal, 'path' => $path]);
		}

		$preview = $this->guard->previewPartReset(request: new ResetRequest(slug: $slug, live: $this->live(schema: $entity), path: $path));

		return $this->previewShape(entity: $entity, preview: (['path' => $path] + $preview));
	}//end preview()

	/**
	 * Reset one part to the shipped value, write it, and put it on the trail.
	 *
	 * The actor is whoever is signed in; the guard refuses without one. The
	 * audit row is written only once the schema write succeeded.
	 *
	 * @param string|int $schema The schema id, uuid or slug.
	 * @param string     $path   The part, as a dotted path (`authorization.read`).
	 *
	 * @return array{applied: bool, reason: string, schema: string, schemaId: int|null, path: string, from: mixed, to: mixed}
	 *         The outcome.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function reset(string|int $schema, string $path): array {
		$path = trim($path);
		$entity = $this->schemas->find(id: $schema, _rbac: false, _multitenancy: false);
		$outcome = self::openOutcome(schema: $entity, path: $path);

		$refusal = $this->refusal(slug: $outcome['schema'], path: $path);
		if ($refusal !== null) {
			$outcome['reason'] = $refusal;
			return $outcome;
		}

		return self::settled(outcome: $outcome, result: $this->resetPart(schema: $entity, path: $path));
	}//end reset()

	/**
	 * The outcome of a reset that has not happened (yet).
	 *
	 * @param Schema $schema The schema.
	 * @param string $path   The part, trimmed.
	 *
	 * @return array{applied: bool, reason: string, schema: string, schemaId: int|null, path: string, from: mixed, to: mixed}
	 */
	private static function openOutcome(Schema $schema, string $path): array {
		return [
			'applied' => false,
			'reason' => '',
			'schema' => (string)($schema->getSlug() ?? ''),
			'schemaId' => $schema->getId(),
			'path' => $path,
			'from' => null,
			'to' => null,
		];
	}//end openOutcome()

	/**
	 * Ask the guard to reset the part and, when it applies, write the schema and put it on the trail.
	 *
	 * @param Schema $schema The schema, written in place.
	 * @param string $path   The part, trimmed.
	 *
	 * @return array{applied: bool, reason: string, from: mixed, to: mixed} The guard's answer.
	 */
	private function resetPart(Schema $schema, string $path): array {
		$slug = (string)($schema->getSlug() ?? '');
		$result = $this->guard->resetToBaseline(slug: $slug, live: $this->live(schema: $schema), path: $path, record: false);
		if ($result['applied'] === true) {
			$this->writePart(schema: $schema, leaf: new LeafReset(segments: explode(DescriptorParts::SEPARATOR, $path), to: $result['to']));
			$this->schemas->update($schema);

			$this->guard->recordPartReset(slug: $slug, reset: new PartReset(path: $path, from: $result['from'], to: $result['to']));
		}

		return $result;
	}//end resetPart()

	/**
	 * The outcome once the guard answered: its from and to, and applied or its reason.
	 *
	 * @param array<string, mixed> $outcome The open outcome.
	 * @param array<string, mixed> $result  The guard's answer.
	 *
	 * @return array{applied: bool, reason: string, schema: string, schemaId: int|null, path: string, from: mixed, to: mixed}
	 */
	private static function settled(array $outcome, array $result): array {
		$outcome['from'] = $result['from'];
		$outcome['to'] = $result['to'];
		if ($result['applied'] === false) {
			$outcome['reason'] = $result['reason'];
			return $outcome;
		}

		$outcome['applied'] = true;
		return $outcome;
	}//end settled()

	/**
	 * Why a reset cannot even be asked for, or null.
	 *
	 * @param string $slug The schema slug.
	 * @param string $path The part.
	 *
	 * @return string|null The reason, or null.
	 */
	private function refusal(string $slug, string $path): ?string {
		if ($path === '') {
			return 'name one part to reset (for example authorization.read); there is no reset of everything';
		}

		if ($slug === '') {
			return 'the schema has no slug, so no shipped baseline can be recorded for it';
		}

		$top = explode(DescriptorParts::SEPARATOR, $path)[0];
		if (in_array($top, self::GUARDED_KEYS, true) === false) {
			return sprintf(
				'"%s" is not a part the shipped baseline keeps; it starts with one of: %s',
				$path,
				implode(', ', self::GUARDED_KEYS)
			);
		}

		return null;
	}//end refusal()

	/**
	 * The parts of the schema the guard compares, as stored.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, mixed> The live definition.
	 */
	private function live(Schema $schema): array {
		return [
			'properties' => $schema->getProperties(),
			'required' => $schema->getRequired(),
			'authorization' => ($schema->getAuthorization() ?? []),
			GuardedAnnotations::PART => (new GuardedAnnotations())->ofStored(configuration: $schema->getConfiguration()),
		];
	}//end live()

	/**
	 * Replace or remove one leaf, leaving every sibling as it was stored.
	 *
	 * @param mixed     $tree The stored part the leaf's segments start in.
	 * @param LeafReset $leaf The segments below the tree and the shipped value.
	 *
	 * @return mixed The part with the leaf replaced, or removed when it was never shipped.
	 */
	private function withLeaf(mixed $tree, LeafReset $leaf): mixed {
		if ($leaf->segments === [] && $leaf->removes() === true) {
			return [];
		}

		if ($leaf->segments === []) {
			return $leaf->to;
		}

		if (is_array($tree) === false) {
			$tree = [];
		}

		$key = $leaf->segments[0];
		if (count($leaf->segments) === 1 && $leaf->removes() === true) {
			unset($tree[$key]);
			return $tree;
		}

		$tree[$key] = $this->withLeaf(tree: ($tree[$key] ?? []), leaf: $leaf->below());
		return $tree;
	}//end withLeaf()

	/**
	 * Set the top-level part a leaf reset falls in on the schema, with the leaf reset.
	 *
	 * @param Schema    $schema The schema, as stored.
	 * @param LeafReset $leaf   The full path, top-level part first, and the shipped value.
	 *
	 * @return void
	 */
	private function writePart(Schema $schema, LeafReset $leaf): void {
		$top = $leaf->segments[0];
		$value = $this->withLeaf(tree: $this->live(schema: $schema)[$top], leaf: $leaf->below());
		if (is_array($value) === false) {
			$value = [];
		}

		match ($top) {
			'properties' => $schema->setProperties($value),
			'required' => $schema->setRequired($value),
			GuardedAnnotations::PART => $schema->setConfiguration(
				(new GuardedAnnotations())->intoStored(configuration: $schema->getConfiguration(), guarded: $value)
			),
			default => $schema->setAuthorization($value),
		};
	}//end writePart()

	/**
	 * The preview's shape.
	 *
	 * @param Schema               $entity  The schema.
	 * @param array<string, mixed> $preview The verdict: `applicable`, `reason`, `path`, and `from` / `to` when known.
	 *
	 * @return array{applicable: bool, reason: string, schema: string, schemaId: int|null, path: string, from: mixed, to: mixed}
	 */
	private function previewShape(Schema $entity, array $preview): array {
		return [
			'applicable' => $preview['applicable'],
			'reason' => $preview['reason'],
			'schema' => (string)($entity->getSlug() ?? ''),
			'schemaId' => $entity->getId(),
			'path' => $preview['path'],
			'from' => ($preview['from'] ?? null),
			'to' => ($preview['to'] ?? null),
		];
	}//end previewShape()
}//end class
