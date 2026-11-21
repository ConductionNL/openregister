<?php

/**
 * OpenRegister object change preview
 *
 * Builds the preview row for one remote object of a configuration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Configuration;

use Closure;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * What importing one remote object would do, as PreviewHandler reports it.
 *
 * Mirrors the import: the object is matched on register, schema and slug,
 * without RBAC or tenancy, only a strictly newer version updates it, a
 * duplicate slug is skipped, and properties the schema does not declare are
 * discarded.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ObjectChangePreview {

	/**
	 * Constructor.
	 *
	 * @param MagicObjectFinderInterface $objectMapper The object mapper, to find the local object a remote one would update.
	 * @param Closure     $compare      PreviewHandler::compareArrays(), as fn(array $current, array $proposed): array.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		private readonly MagicObjectFinderInterface $objectMapper,
		private readonly Closure $compare,
	) {
	}//end __construct()

	/**
	 * Preview what importing one remote object would do.
	 *
	 * The row carries the register and schema slugs and the object slug, which
	 * is the key ImportSelection reads back when the administrator picks it.
	 *
	 * @param array<string, mixed> $objectData The remote object.
	 * @param ObjectPreviewTargets $targets    The local registers and schemas, and those this import creates.
	 *
	 * @return array<string, mixed> Preview row: type, action, slug, title, register, schema, current, proposed, changes,
	 *                              discarded (undeclared properties the import drops), and reason on a skip.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function preview(array $objectData, ObjectPreviewTargets $targets): array {
		$preview = $this->baseRow(objectData: $objectData);
		if (in_array('', [$preview['slug'], $preview['register'], $preview['schema']], true) === true) {
			$preview['reason'] = 'Missing required fields (slug, register, or schema)';
			return $preview;
		}

		$local = $targets->local(row: $preview);
		if ($local === null) {
			return $this->withoutLocalTarget(preview: $preview, targets: $targets);
		}

		return $this->againstLookup(preview: $preview, local: $local);
	}//end preview()

	/**
	 * The row before the object is looked up: a skip, with the slugs, the title and the remote object as proposed.
	 *
	 * @param array<string, mixed> $objectData The remote object.
	 *
	 * @return array<string, mixed> The row so far.
	 */
	private function baseRow(array $objectData): array {
		$self = (array)($objectData['@self'] ?? []);
		$slug = (string)($self['slug'] ?? '');
		return [
			'type' => 'object',
			'action' => 'skip',
			'slug' => $slug,
			'title' => $this->objectTitle(objectData: $objectData, slug: $slug),
			'register' => (string)($self['register'] ?? ''),
			'schema' => (string)($self['schema'] ?? ''),
			'current' => null,
			'proposed' => $objectData,
			'changes' => [],
		];
	}//end baseRow()

	/**
	 * The row for an object whose register and schema are local: create, skip a duplicate, or compare.
	 *
	 * @param array<string, mixed>                     $preview The row so far.
	 * @param array{register: Register, schema: Schema} $local   The local register and schema.
	 *
	 * @return array<string, mixed>
	 */
	private function againstLookup(array $preview, array $local): array {
		try {
			$local['existing'] = $this->findStored(slug: $preview['slug'], local: $local);
		} catch (DoesNotExistException $e) {
			$preview['action'] = 'create';
			return $preview;
		} catch (MultipleObjectsReturnedException $e) {
			return $this->duplicateRow(preview: $preview);
		}

		return $this->againstExisting(preview: $preview, local: $local);
	}//end againstLookup()

	/**
	 * The stored object the remote one would update, matched on register, schema and slug.
	 *
	 * Matched without RBAC or tenancy, as the import matches it.
	 *
	 * @param string                                    $slug  The object slug.
	 * @param array{register: Register, schema: Schema} $local The local register and schema.
	 *
	 * @return ObjectEntity The stored object.
	 *
	 * @throws DoesNotExistException When no object matches.
	 * @throws MultipleObjectsReturnedException When more than one object matches.
	 */
	private function findStored(string $slug, array $local): ObjectEntity {
		return $this->objectMapper->find(
			identifier: $slug,
			register: $local['register'],
			schema: $local['schema'],
			includeDeleted: false,
			_rbac: false,
			_multitenancy: false
		);
	}//end findStored()

	/**
	 * The row for an object whose slug matches more than one stored object: a skip.
	 *
	 * The import skips such an object too (ImportHandler); one duplicate must
	 * not fail the whole preview (live pass O12: HTTP 500).
	 *
	 * @param array<string, mixed> $preview The row so far.
	 *
	 * @return array<string, mixed>
	 */
	private function duplicateRow(array $preview): array {
		$preview['reason'] = sprintf(
			'This register holds more than one %s object with slug "%s"; the import skips it until the duplicates are resolved',
			$preview['schema'],
			$preview['slug']
		);
		return $preview;
	}//end duplicateRow()

	/**
	 * The lowercased slugs of the preview rows that would be created.
	 *
	 * @param array<int, array<string, mixed>> $rows Register or schema preview rows.
	 *
	 * @return array<string, true>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function createdSlugs(array $rows): array {
		$slugs = [];
		foreach ($rows as $row) {
			if (($row['action'] ?? null) === 'create') {
				$slugs[strtolower((string)($row['slug'] ?? ''))] = true;
			}
		}

		return $slugs;
	}//end createdSlugs()

	/**
	 * The row for an object whose register or schema is not local.
	 *
	 * When the same import creates the missing register and schema, the object
	 * is created too (live pass O13); otherwise it is skipped.
	 *
	 * @param array<string, mixed> $preview The row so far.
	 * @param ObjectPreviewTargets $targets The local and planned registers and schemas.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutLocalTarget(array $preview, ObjectPreviewTargets $targets): array {
		if ($targets->arrives(row: $preview) === true) {
			$preview['action'] = 'create';
			return $preview;
		}

		$preview['reason'] = 'Register or schema not found locally';
		return $preview;
	}//end withoutLocalTarget()

	/**
	 * The row for an object that exists locally: skip unless the remote version is newer, else compare.
	 *
	 * @param array<string, mixed>                                              $preview The row so far.
	 * @param array{register: Register, schema: Schema, existing: ObjectEntity} $local   The local register, schema and object.
	 *
	 * @return array<string, mixed>
	 */
	private function againstExisting(array $preview, array $local): array {
		$preview['current'] = $local['existing']->jsonSerialize();
		$versions = $this->versions(current: $preview['current'], objectData: $preview['proposed']);
		if (version_compare($versions['proposed'], $versions['current'], '>') === false) {
			$preview['reason'] = sprintf(
				'Remote version (%s) is not newer than current version (%s)',
				$versions['proposed'],
				$versions['current']
			);
			return $preview;
		}

		return $this->withChanges(preview: $preview, schema: $local['schema']);
	}//end againstExisting()

	/**
	 * The stored and the remote version of an object; 1.0.0 when one names none.
	 *
	 * @param array<string, mixed> $current    The stored object, serialised.
	 * @param array<string, mixed> $objectData The remote object.
	 *
	 * @return array{current: string, proposed: string}
	 */
	private function versions(array $current, array $objectData): array {
		$self = (array)($objectData['@self'] ?? []);
		return [
			'current' => (string)($current['@self']['version'] ?? $current['version'] ?? '1.0.0'),
			'proposed' => (string)($self['version'] ?? $objectData['version'] ?? '1.0.0'),
		];
	}//end versions()

	/**
	 * The row for a newer remote object: update with the changes it would write, or skip when there are none.
	 *
	 * Properties the schema does not declare are discarded on save
	 * (MagicDroppedProperties::report()), so they never arrive and would read
	 * as a change after every import (live pass O13).
	 *
	 * @param array<string, mixed> $preview The row with the stored object as current.
	 * @param Schema               $schema  The local schema.
	 *
	 * @return array<string, mixed>
	 */
	private function withChanges(array $preview, Schema $schema): array {
		$objectData = $this->writtenData(objectData: $this->comparable(data: $preview['proposed']), schema: $schema);
		$discarded = $this->undeclaredProperties(objectData: $objectData, schema: $schema);
		if ($discarded !== []) {
			$preview['discarded'] = $discarded;
		}
		$preview['changes'] = ($this->compare)($this->comparable(data: $preview['current']), array_diff_key($objectData, array_flip($discarded)));
		if ($preview['changes'] === []) {
			$preview['reason'] = 'The stored object already holds what the import would write';
			return $preview;
		}

		$preview['action'] = 'update';
		return $preview;
	}//end withChanges()

	/**
	 * An object without the `@self` keys that read as a change on every row.
	 *
	 * The row is matched on register and schema already, and the remote side
	 * names them by slug where the local side holds ids. The version only gates
	 * the update: the stored version is OpenRegister's own counter, so it
	 * differs on every row (live pass O2).
	 *
	 * @param array<string, mixed> $data A stored or remote object.
	 *
	 * @return array<string, mixed> The object without `@self` register, schema and version.
	 */
	private function comparable(array $data): array {
		unset($data['@self']['register'], $data['@self']['schema'], $data['@self']['version']);
		return $data;
	}//end comparable()

	/**
	 * What the import would WRITE of a remote object: without the identity keys the schema does not declare.
	 *
	 * The seed format's top-level uuid and slug are identity, stripped from the
	 * data on import unless the schema declares them
	 * (ImportHandler::withoutSeedMetadataKeys()), so the stored object never
	 * holds them and they would read as a change on every row.
	 *
	 * @param array<string, mixed> $objectData The remote object.
	 * @param Schema               $schema     The local schema.
	 *
	 * @return array<string, mixed>
	 */
	private function writtenData(array $objectData, Schema $schema): array {
		$undeclaredIdentity = array_diff_key(['uuid' => true, 'slug' => true], $schema->getProperties());
		return array_diff_key($objectData, $undeclaredIdentity);
	}//end writtenData()

	/**
	 * The top-level properties of a remote object that its schema does not declare.
	 *
	 * Mirrors MagicDroppedProperties::report(): `@`- and `_`-prefixed keys,
	 * `id` and `uuid` are metadata, not data, and are not counted.
	 *
	 * @param array<string, mixed> $objectData The remote object.
	 * @param Schema               $schema     The local schema.
	 *
	 * @return array<int, string>
	 */
	private function undeclaredProperties(array $objectData, Schema $schema): array {
		$declared = $schema->getProperties();
		$undeclared = [];
		foreach (array_keys($objectData) as $key) {
			$name = (string)$key;
			if ($this->isMetadataKey(name: $name) === false && array_key_exists($name, $declared) === false) {
				$undeclared[] = $name;
			}
		}

		return $undeclared;
	}//end undeclaredProperties()

	/**
	 * Whether a top-level key is metadata: empty, `id`, `uuid`, or `@`- or `_`-prefixed.
	 *
	 * @param string $name The key.
	 *
	 * @return bool
	 */
	private function isMetadataKey(string $name): bool {
		return $name === '' || in_array($name, ['id', 'uuid'], true) === true || in_array($name[0], ['@', '_'], true) === true;
	}//end isMetadataKey()

	/**
	 * The title a preview row shows for a remote object: its title, its name, or its slug.
	 *
	 * @param array<string, mixed> $objectData The remote object.
	 * @param string               $slug       The object slug.
	 *
	 * @return string
	 */
	private function objectTitle(array $objectData, string $slug): string {
		foreach (['title', 'name'] as $key) {
			if (is_string($objectData[$key] ?? null) === true && $objectData[$key] !== '') {
				return $objectData[$key];
			}
		}

		return $slug;
	}//end objectTitle()
}//end class
