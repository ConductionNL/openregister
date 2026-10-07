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
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;

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
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */
class ShippedBaselineResetService {

	/**
	 * The schema parts the guard keeps a baseline of.
	 *
	 * @var array<int, string>
	 */
	public const GUARDED_KEYS = ['properties', 'required', 'authorization'];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper              $schemas Where the schema lives.
	 * @param ShippedConfigurationGuard $guard   The baseline and the reset rules.
	 */
	public function __construct(
		private readonly SchemaMapper $schemas,
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
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function preview(string|int $schema, string $path): array {
		$path = trim($path);
		$entity = $this->schemas->find(id: $schema, _rbac: false, _multitenancy: false);
		$slug = (string)($entity->getSlug() ?? '');

		$refusal = $this->refusal(slug: $slug, path: $path);
		if ($refusal !== null) {
			return $this->previewShape(applicable: false, reason: $refusal, entity: $entity, path: $path);
		}

		$preview = $this->guard->previewReset(slug: $slug, live: $this->live(schema: $entity), path: $path);

		return $this->previewShape(
			applicable: $preview['applicable'],
			reason: $preview['reason'],
			entity: $entity,
			path: $path,
			from: $preview['from'],
			to: $preview['to']
		);
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
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	public function reset(string|int $schema, string $path): array {
		$path = trim($path);
		$entity = $this->schemas->find(id: $schema, _rbac: false, _multitenancy: false);
		$slug = (string)($entity->getSlug() ?? '');

		$outcome = [
			'applied' => false,
			'reason' => '',
			'schema' => $slug,
			'schemaId' => $entity->getId(),
			'path' => $path,
			'from' => null,
			'to' => null,
		];

		$refusal = $this->refusal(slug: $slug, path: $path);
		if ($refusal !== null) {
			$outcome['reason'] = $refusal;
			return $outcome;
		}

		$live = $this->live(schema: $entity);
		$result = $this->guard->resetToBaseline(slug: $slug, live: $live, path: $path, record: false);
		$outcome['from'] = $result['from'];
		$outcome['to'] = $result['to'];
		if ($result['applied'] === false) {
			$outcome['reason'] = $result['reason'];
			return $outcome;
		}

		$segments = explode(DescriptorParts::SEPARATOR, $path);
		$top = array_shift($segments);
		$value = $this->withLeaf(
			tree: $live[$top],
			segments: $segments,
			value: $result['to'],
			remove: ($result['to'] === null)
		);
		$this->writePart(schema: $entity, key: $top, value: $value);
		$this->schemas->update($entity);

		$this->guard->recordReset(slug: $slug, path: $path, from: $result['from'], to: $result['to']);

		$outcome['applied'] = true;
		return $outcome;
	}//end reset()

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
		];
	}//end live()

	/**
	 * Replace or remove one leaf, leaving every sibling as it was stored.
	 *
	 * @param mixed              $tree     The stored top-level part.
	 * @param array<int, string> $segments The path below it.
	 * @param mixed              $value    The shipped value.
	 * @param bool               $remove   True when the part was never shipped.
	 *
	 * @return mixed The part with the leaf replaced.
	 */
	private function withLeaf(mixed $tree, array $segments, mixed $value, bool $remove): mixed {
		if ($segments === []) {
			if ($remove === true) {
				return [];
			}

			return $value;
		}

		if (is_array($tree) === false) {
			$tree = [];
		}

		$key = array_shift($segments);
		if ($segments === [] && $remove === true) {
			unset($tree[$key]);
			return $tree;
		}

		$tree[$key] = $this->withLeaf(tree: ($tree[$key] ?? []), segments: $segments, value: $value, remove: $remove);
		return $tree;
	}//end withLeaf()

	/**
	 * Set one top-level part on the schema.
	 *
	 * @param Schema $schema The schema.
	 * @param string $key    properties, required or authorization.
	 * @param mixed  $value  The part.
	 *
	 * @return void
	 */
	private function writePart(Schema $schema, string $key, mixed $value): void {
		if (is_array($value) === false) {
			$value = [];
		}

		if ($key === 'properties') {
			$schema->setProperties($value);
			return;
		}

		if ($key === 'required') {
			$schema->setRequired($value);
			return;
		}

		$schema->setAuthorization($value);
	}//end writePart()

	/**
	 * The preview's shape.
	 *
	 * @param bool   $applicable Whether a reset would change anything.
	 * @param string $reason     Why not, when not.
	 * @param Schema $entity     The schema.
	 * @param string $path       The part.
	 * @param mixed  $from       The stored value.
	 * @param mixed  $to         The shipped value.
	 *
	 * @return array{applicable: bool, reason: string, schema: string, schemaId: int|null, path: string, from: mixed, to: mixed}
	 */
	private function previewShape(
		bool $applicable,
		string $reason,
		Schema $entity,
		string $path,
		mixed $from = null,
		mixed $to = null
	): array {
		return [
			'applicable' => $applicable,
			'reason' => $reason,
			'schema' => (string)($entity->getSlug() ?? ''),
			'schemaId' => $entity->getId(),
			'path' => $path,
			'from' => $from,
			'to' => $to,
		];
	}//end previewShape()
}//end class
