<?php

/**
 * Accept the shipped definition for a part an app upgrade kept local, as an administrator's act.
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
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IUserSession;

/**
 * The decision an unattended upgrade leaves for a person (REQ-LCA-003).
 *
 * An upgrade keeps the local value of a part changed on both sides and
 * records the conflict with both definitions. Accepting one writes the
 * shipped definition into that part only, moves the baseline at that path
 * to it, drops the conflict from the pending list and records who decided,
 * from what and to what. A conflict that is not pending cannot be accepted:
 * this is not a way to write arbitrary definitions.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#requirement-an-upgrade-never-silently-discards-a-local-change-req-lca-003
 */
class ShippedConflictService implements ShippedConflictServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface     $schemas   Reads and writes the schema.
	 * @param ShippedBaselineStore      $baselines The baseline and the pending conflicts.
	 * @param ShippedConfigurationGuard $guard     Puts the decision on the trail.
	 * @param IUserSession              $session   Who is deciding.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly ShippedBaselineStore $baselines,
		private readonly ShippedConfigurationGuard $guard,
		private readonly IUserSession $session,
	) {
	}//end __construct()

	/**
	 * The parts the last guarded import of a schema kept local.
	 *
	 * @param string|int $schema The schema id, uuid or slug.
	 *
	 * @return array<string, mixed> The pending conflicts and the release that reported them.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#requirement-an-upgrade-never-silently-discards-a-local-change-req-lca-003
	 */
	public function pending(string|int $schema): array {
		$entity = $this->schemas->find(id: $schema, _rbac: false, _multitenancy: false);
		$record = $this->recordOf(schema: $entity);

		return [
			'schema' => (string)($entity->getSlug() ?? ''),
			'schemaId' => $entity->getId(),
			'app' => (string)($record['app'] ?? ''),
			'appVersion' => (string)($record['appVersion'] ?? ''),
			'recordedAt' => (string)($record['recordedAt'] ?? ''),
			'conflicts' => self::conflictsIn(record: $record),
		];
	}//end pending()

	/**
	 * Take the shipped definition for one pending conflict.
	 *
	 * @param string|int $schema The schema id, uuid or slug.
	 * @param string     $path   The conflicting part, as a dotted path.
	 *
	 * @return array<string, mixed> The outcome: applied, the reason when not, and from and to.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md#requirement-an-upgrade-never-silently-discards-a-local-change-req-lca-003
	 */
	public function accept(string|int $schema, string $path): array {
		$path = trim($path);
		$entity = $this->schemas->find(id: $schema, _rbac: false, _multitenancy: false);
		$slug = (string)($entity->getSlug() ?? '');
		$outcome = ['applied' => false, 'reason' => '', 'schema' => $slug, 'schemaId' => $entity->getId(), 'path' => $path, 'from' => null, 'to' => null];

		$record = $this->recordOf(schema: $entity);
		$conflict = self::conflictAt(record: $record, path: $path);
		$reason = $this->refusal(path: $path, conflict: $conflict);
		if ($reason !== null) {
			$outcome['reason'] = $reason;
			return $outcome;
		}

		$leaf = new LeafReset(segments: explode(DescriptorParts::SEPARATOR, $path), to: ($conflict['shipped'] ?? null));
		$outcome['from'] = self::valueAt(tree: LiveDescriptor::of(schema: $entity), segments: $leaf->segments);
		$outcome['to'] = $leaf->to;

		$writer = new SchemaPartWriter();
		$writer->write(schema: $entity, leaf: $leaf);
		$this->schemas->update($entity);

		$this->settle(slug: $slug, leaf: $leaf, record: (array)$record);
		$this->guard->recordAcceptedConflict(slug: $slug, accepted: new PartReset(path: $path, from: $outcome['from'], to: $outcome['to']));

		$outcome['applied'] = true;
		return $outcome;
	}//end accept()

	/**
	 * Move the baseline at the accepted path to the shipped definition and drop the conflict from the pending list.
	 *
	 * @param string               $slug   The schema slug.
	 * @param LeafReset            $leaf   The accepted path and shipped definition.
	 * @param array<string, mixed> $record The pending conflicts record.
	 *
	 * @return void
	 */
	private function settle(string $slug, LeafReset $leaf, array $record): void {
		$path = implode(DescriptorParts::SEPARATOR, $leaf->segments);
		$baseline = $this->baselines->read(subject: $this->baselines->schemaSubject(slug: $slug));
		if ($baseline !== null) {
			$definition = (new SchemaPartWriter())->into(tree: $baseline['definition'], leaf: $leaf);
			$this->baselines->record(
				subject: $this->baselines->schemaSubject(slug: $slug),
				shipped: new ShippedDefinition(definition: (array)$definition, app: $baseline['app'], appVersion: $baseline['appVersion'])
			);
		}

		$remaining = array_values(
			array_filter(
				self::conflictsIn(record: $record),
				static fn (array $conflict): bool => ($conflict['path'] ?? '') !== $path
			)
		);
		$this->baselines->recordConflicts(
			slug: $slug,
			conflicts: new ShippedDefinition(
				definition: ['conflicts' => $remaining],
				app: (string)($record['app'] ?? ''),
				appVersion: (string)($record['appVersion'] ?? '')
			)
		);
	}//end settle()

	/**
	 * Why a conflict cannot be accepted, or null when it can.
	 *
	 * @param string                    $path     The path asked for.
	 * @param array<string, mixed>|null $conflict The pending conflict at that path, null for none.
	 *
	 * @return string|null The reason.
	 */
	private function refusal(string $path, ?array $conflict): ?string {
		if ($this->session->getUser() === null) {
			return 'accepting a shipped definition needs an actor, and there is no session; it is never done by an unattended path';
		}

		if ($path === '') {
			return 'name one conflicting part to accept (for example properties.level)';
		}

		if ($conflict === null) {
			return sprintf('"%s" is not a conflict the last upgrade kept local for this schema; nothing to accept', $path);
		}

		return null;
	}//end refusal()

	/**
	 * The pending conflicts record of a schema, or null when it has no slug or none was recorded.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string, mixed>|null The record.
	 */
	private function recordOf(Schema $schema): ?array {
		$slug = (string)($schema->getSlug() ?? '');
		if ($slug === '') {
			return null;
		}

		return $this->baselines->readConflicts(slug: $slug);
	}//end recordOf()

	/**
	 * The conflicts in a record, each an array with a path.
	 *
	 * @param array<string, mixed>|null $record The record.
	 *
	 * @return array<int, array<string, mixed>> The conflicts.
	 */
	private static function conflictsIn(?array $record): array {
		$conflicts = ($record['definition']['conflicts'] ?? []);
		if (is_array($conflicts) === false) {
			return [];
		}

		return array_values(
			array_filter($conflicts, static fn (mixed $conflict): bool => is_array($conflict) === true && is_string($conflict['path'] ?? null) === true)
		);
	}//end conflictsIn()

	/**
	 * The pending conflict at one path, or null.
	 *
	 * @param array<string, mixed>|null $record The record.
	 * @param string                    $path   The path.
	 *
	 * @return array<string, mixed>|null The conflict.
	 */
	private static function conflictAt(?array $record, string $path): ?array {
		foreach (self::conflictsIn(record: $record) as $conflict) {
			if ($conflict['path'] === $path) {
				return $conflict;
			}
		}

		return null;
	}//end conflictAt()

	/**
	 * The value at a path in a tree, null when it is not there.
	 *
	 * @param mixed              $tree     The tree.
	 * @param array<int, string> $segments The path.
	 *
	 * @return mixed The value.
	 */
	private static function valueAt(mixed $tree, array $segments): mixed {
		foreach ($segments as $segment) {
			if (is_array($tree) === false || array_key_exists($segment, $tree) === false) {
				return null;
			}

			$tree = $tree[$segment];
		}

		return $tree;
	}//end valueAt()
}//end class
