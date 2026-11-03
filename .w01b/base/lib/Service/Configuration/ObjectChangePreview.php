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
use OCA\OpenRegister\Db\MagicMapper;
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
 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
 */
final class ObjectChangePreview {

	/**
	 * Constructor.
	 *
	 * @param MagicMapper $objectMapper The object mapper, to find the local object a remote one would update.
	 * @param Closure     $compare      PreviewHandler::compareArrays(), as fn(array $current, array $proposed): array.
	 *
	 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $objectMapper,
		private readonly Closure $compare,
	) {
	}//end __construct()

	/**
	 * Preview what importing one remote object would do.
	 *
	 * The row carries the register and schema slugs and the object slug, which
	 * is the key ImportSelection reads back when the administrator picks it.
	 *
	 * @param array<string, mixed>                                                 $objectData      The remote object.
	 * @param array<string, Register>                                              $registersBySlug Local registers by lowercased slug.
	 * @param array<string, Schema>                                                $schemasBySlug   Local schemas by lowercased slug.
	 * @param array{registers: array<string, true>, schemas: array<string, true>} $planned         Register and schema slugs this import creates.
	 *
	 * @return array<string, mixed> Preview row: type, action, slug, title, register, schema, current, proposed, changes,
	 *                              discarded (undeclared properties the import drops), and reason on a skip.
	 *
	 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
	 */
	public function preview(array $objectData, array $registersBySlug, array $schemasBySlug, array $planned): array {
		$self = (array)($objectData['@self'] ?? []);
		$slug = (string)($self['slug'] ?? '');
		$registerSlug = (string)($self['register'] ?? '');
		$schemaSlug = (string)($self['schema'] ?? '');

		$preview = [
			'type' => 'object',
			'action' => 'skip',
			'slug' => $slug,
			'title' => $this->objectTitle(objectData: $objectData, slug: $slug),
			'register' => $registerSlug,
			'schema' => $schemaSlug,
			'current' => null,
			'proposed' => $objectData,
			'changes' => [],
		];

		if ($slug === '' || $registerSlug === '' || $schemaSlug === '') {
			$preview['reason'] = 'Missing required fields (slug, register, or schema)';
			return $preview;
		}

		$register = ($registersBySlug[strtolower($registerSlug)] ?? null);
		$schema = ($schemasBySlug[strtolower($schemaSlug)] ?? null);
		if ($register === null || $schema === null) {
			return $this->withoutLocalTarget(preview: $preview, register: $register, schema: $schema, planned: $planned);
		}

		try {
			$existing = $this->objectMapper->find(
				identifier: $slug,
				register: $register,
				schema: $schema,
				includeDeleted: false,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			$preview['action'] = 'create';
			return $preview;
		} catch (MultipleObjectsReturnedException $e) {
			// The import skips such an object too (ImportHandler); one duplicate
			// must not fail the whole preview (live pass O12: HTTP 500).
			$preview['reason'] = sprintf(
				'This register holds more than one %s object with slug "%s"; the import skips it until the duplicates are resolved',
				$schemaSlug,
				$slug
			);
			return $preview;
		}//end try

		return $this->againstExisting(preview: $preview, existing: $existing, objectData: $objectData, schema: $schema);
	}//end preview()

	/**
	 * The lowercased slugs of the preview rows that would be created.
	 *
	 * @param array<int, array<string, mixed>> $rows Register or schema preview rows.
	 *
	 * @return array<string, true>
	 *
	 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
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
	 * @param array<string, mixed>                                                 $preview  The row so far.
	 * @param Register|null                                                        $register The local register, if any.
	 * @param Schema|null                                                          $schema   The local schema, if any.
	 * @param array{registers: array<string, true>, schemas: array<string, true>} $planned  Register and schema slugs this import creates.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutLocalTarget(array $preview, ?Register $register, ?Schema $schema, array $planned): array {
		$registerComes = ($register !== null || isset($planned['registers'][strtolower($preview['register'])]) === true);
		$schemaComes = ($schema !== null || isset($planned['schemas'][strtolower($preview['schema'])]) === true);
		if ($registerComes === true && $schemaComes === true) {
			$preview['action'] = 'create';
			return $preview;
		}

		$preview['reason'] = 'Register or schema not found locally';
		return $preview;
	}//end withoutLocalTarget()

	/**
	 * The row for an object that exists locally: skip, or update with its changes.
	 *
	 * @param array<string, mixed> $preview    The row so far.
	 * @param ObjectEntity         $existing   The local object.
	 * @param array<string, mixed> $objectData The remote object.
	 * @param Schema               $schema     The local schema.
	 *
	 * @return array<string, mixed>
	 */
	private function againstExisting(array $preview, ObjectEntity $existing, array $objectData, Schema $schema): array {
		$current = $existing->jsonSerialize();
		$preview['current'] = $current;

		$self = (array)($objectData['@self'] ?? []);
		$currentVersion = (string)($current['@self']['version'] ?? $current['version'] ?? '1.0.0');
		$proposedVersion = (string)($self['version'] ?? $objectData['version'] ?? '1.0.0');
		if (version_compare($proposedVersion, $currentVersion, '>') === false) {
			$preview['reason'] = sprintf(
				'Remote version (%s) is not newer than current version (%s)',
				$proposedVersion,
				$currentVersion
			);
			return $preview;
		}

		// The row is matched on register and schema already, and the remote side
		// names them by slug where the local side holds ids: comparing them
		// would report a change on every object.
		unset($current['@self']['register'], $current['@self']['schema'], $objectData['@self']['register'], $objectData['@self']['schema']);

		// Compare what the import would WRITE (live pass O2). The version only
		// gates the update: the stored version is OpenRegister's own counter, so
		// it differs on every row. The seed format's top-level uuid and slug are
		// identity, stripped from the data on import unless the schema declares
		// them (ImportHandler::withoutSeedMetadataKeys()), so the stored object
		// never holds them and they would read as a change on every row.
		unset($current['@self']['version'], $objectData['@self']['version']);
		$undeclaredIdentity = array_diff_key(['uuid' => true, 'slug' => true], $schema->getProperties());
		$objectData = array_diff_key($objectData, $undeclaredIdentity);

		// Properties the schema does not declare are discarded on save
		// (MagicMapper::reportDroppedProperties()), so they never arrive and
		// would read as a change after every import (live pass O13).
		$discarded = $this->undeclaredProperties(objectData: $objectData, schema: $schema);
		$objectData = array_diff_key($objectData, array_flip($discarded));
		if ($discarded !== []) {
			$preview['discarded'] = $discarded;
		}

		$preview['changes'] = ($this->compare)($current, $objectData);
		if ($preview['changes'] === []) {
			$preview['reason'] = 'The stored object already holds what the import would write';
			return $preview;
		}

		$preview['action'] = 'update';
		return $preview;
	}//end againstExisting()

	/**
	 * The top-level properties of a remote object that its schema does not declare.
	 *
	 * Mirrors MagicMapper::reportDroppedProperties(): `@`- and `_`-prefixed keys,
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
			if ($name === '' || $name === 'id' || $name === 'uuid' || $name[0] === '@' || $name[0] === '_') {
				continue;
			}

			if (array_key_exists($name, $declared) === false) {
				$undeclared[] = $name;
			}
		}

		return $undeclared;
	}//end undeclaredProperties()

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
