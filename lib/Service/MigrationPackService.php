<?php

/**
 * Business logic for MigrationPack rows: validation, CRUD, and pack
 * document import/export (so packs can be shared between instances as
 * plain JSON files).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\MigrationPackMapper;
use OCA\OpenRegister\Exception\BuiltinPackSlugLockedException;
use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Exception\PackChangedException;
use OCA\OpenRegister\Exception\PackSlugExistsException;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackImportResult;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;

/**
 * Class MigrationPackService
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
class MigrationPackService {
	/**
	 * App config key listing the slugs of deleted built-in packs.
	 *
	 * A slug is added when a built-in pack is deleted and removed when a
	 * built-in pack is seeded under it again, so the list holds the slugs
	 * whose built-in pack is currently gone. Nothing else clears it: after the
	 * owning app is uninstalled its slugs stay locked.
	 *
	 * @var string
	 */
	public const DELETED_BUILTIN_SLUGS_KEY = 'migration_packs_deleted_builtin_slugs';

	/**
	 * Constructor.
	 *
	 * @param MigrationPackMapper $mapper Data mapper.
	 * @param PackDefinitionValidator $validator Pack definition validator.
	 * @param MappingEngine $mappingEngine Applies a pack to source rows (preview).
	 * @param IAppConfig $appConfig Remembers the slugs of deleted built-in packs.
	 */
	public function __construct(
		private readonly MigrationPackMapper $mapper,
		private readonly PackDefinitionValidator $validator,
		private readonly MappingEngine $mappingEngine,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * List every stored migration pack.
	 *
	 * @return MigrationPack[]
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function findAll(): array {
		return $this->mapper->findAll();
	}//end findAll()

	/**
	 * Find a migration pack by its numeric id.
	 *
	 * @param int $id The migration pack id.
	 *
	 * @return MigrationPack
	 *
	 * @throws DoesNotExistException When no row matches.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function find(int $id): MigrationPack {
		return $this->mapper->find(id: $id);
	}//end find()

	/**
	 * Find a migration pack by its pack document's own `id` (slug).
	 *
	 * @param string $packSlug The pack document's own `id`.
	 *
	 * @return MigrationPack
	 *
	 * @throws DoesNotExistException When no row matches.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function findByPackSlug(string $packSlug): MigrationPack {
		return $this->mapper->findByPackSlug(packSlug: $packSlug);
	}//end findByPackSlug()

	/**
	 * Create a migration pack from a definition document.
	 *
	 * A pack created under the slug of a deleted built-in pack is built-in
	 * again, so its slug stays locked. Seeding a built-in pack (`$builtin`)
	 * takes its slug off the deleted list once the row is stored: the pack is
	 * built-in again by its own flag.
	 *
	 * @param array<string, mixed> $definition The pack definition document (the JSON body).
	 * @param string|null $ownerUid The creating admin's uid, or null for a system/built-in seed.
	 * @param bool $builtin Whether to mark this row as a built-in reference pack.
	 *
	 * @return MigrationPack
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $builtin only distinguishes admin-authored packs from the seeded reference pack.
	 *
	 * @throws InvalidPackDefinitionException When the definition is structurally invalid.
	 * @throws PackSlugExistsException When its slug already exists.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function create(array $definition, ?string $ownerUid = null, bool $builtin = false): MigrationPack {
		$this->validator->assertValid(definition: $definition);

		$slug = (string)$definition['id'];
		if ($this->slugExists(packSlug: $slug) === true) {
			throw new PackSlugExistsException(packSlug: $slug);
		}

		$pack = new MigrationPack();
		$pack->setPackSlug($slug);
		$pack->setName((string)$definition['name']);
		$pack->setSourceFormat((string)$definition['sourceFormat']);
		$pack->setVersion((string)$definition['version']);
		$pack->setDefinition(json_encode($definition, JSON_UNESCAPED_SLASHES));
		$pack->setBuiltin($builtin === true || $this->isDeletedBuiltinSlug(packSlug: $slug) === true);
		$pack->setOwner($ownerUid);
		$pack->setCreatedAt(new DateTime());
		$pack->setUpdatedAt(new DateTime());

		$stored = $this->mapper->insert(entity: $pack);

		// After the insert, so a failed insert leaves the slug locked.
		if ($builtin === true) {
			$this->forgetDeletedBuiltinSlug(packSlug: $slug);
		}

		return $stored;
	}//end create()

	/**
	 * Update an existing migration pack's definition.
	 *
	 * The slug of a built-in pack cannot change: the app that seeded it finds
	 * it again by that slug. A pack renamed to the slug of a deleted built-in
	 * pack becomes built-in.
	 *
	 * With `$expectedUpdatedAt`, the update is refused when the stored pack
	 * was saved at another time, to the second: someone saved it after the
	 * caller read it. Without it, the update overwrites what is stored. It is
	 * a Unix time, so the comparison does not depend on the offset the caller
	 * wrote the time in.
	 *
	 * @param int $id The migration pack id.
	 * @param array<string, mixed> $definition The replacement pack definition document.
	 * @param int|null $expectedUpdatedAt The `updatedAt` the caller read, as a Unix time, or null for no check.
	 *
	 * @return MigrationPack
	 *
	 * @throws DoesNotExistException When no row matches.
	 * @throws PackChangedException When the pack was saved at another time than `$expectedUpdatedAt`.
	 * @throws BuiltinPackSlugLockedException When the definition renames a built-in pack.
	 * @throws InvalidPackDefinitionException When the definition is structurally invalid.
	 * @throws PackSlugExistsException When its slug collides with a different pack.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	public function update(int $id, array $definition, ?int $expectedUpdatedAt = null): MigrationPack {
		$this->validator->assertValid(definition: $definition);

		$pack = $this->mapper->find(id: $id);

		if ($expectedUpdatedAt !== null
			&& $pack->getUpdatedAt()?->getTimestamp() !== $expectedUpdatedAt
		) {
			throw new PackChangedException(
				message: 'Migration pack ' . $id . ' was saved after ' . date(DATE_ATOM, $expectedUpdatedAt)
			);
		}

		$slug = (string)$definition['id'];
		if ($pack->getBuiltin() === true && $slug !== $pack->getPackSlug()) {
			throw new BuiltinPackSlugLockedException(
				message: 'The id of built-in migration pack "' . $pack->getPackSlug() . '" cannot be changed'
			);
		}

		if ($slug !== $pack->getPackSlug() && $this->slugExists(packSlug: $slug) === true) {
			throw new PackSlugExistsException(packSlug: $slug);
		}

		if ($slug !== $pack->getPackSlug() && $this->isDeletedBuiltinSlug(packSlug: $slug) === true) {
			$pack->setBuiltin(true);
		}

		$pack->setPackSlug($slug);
		$pack->setName((string)$definition['name']);
		$pack->setSourceFormat((string)$definition['sourceFormat']);
		$pack->setVersion((string)$definition['version']);
		$pack->setDefinition(json_encode($definition, JSON_UNESCAPED_SLASHES));
		$pack->setUpdatedAt(new DateTime());

		return $this->mapper->update(entity: $pack);
	}//end update()

	/**
	 * Delete a migration pack.
	 *
	 * The slug of a deleted built-in pack is remembered, so a pack created
	 * or imported under it later keeps the slug lock. It is remembered before
	 * the row goes: if remembering fails, the pack is still there and still
	 * built-in, so the lock never opens.
	 *
	 * @param int $id The migration pack id.
	 *
	 * @return MigrationPack The deleted pack, so the caller can name it.
	 *
	 * @throws DoesNotExistException When no row matches.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	public function delete(int $id): MigrationPack {
		$pack = $this->mapper->find(id: $id);
		if ($pack->getBuiltin() === true) {
			$this->rememberDeletedBuiltinSlug(packSlug: $pack->getPackSlug());
		}

		$this->mapper->delete(entity: $pack);

		return $pack;
	}//end delete()

	/**
	 * Validate a pack definition without storing it.
	 *
	 * @param array<string, mixed> $definition The pack definition document.
	 *
	 * @return string[] The validation errors; empty when the definition is valid.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	public function validateDefinition(array $definition): array {
		return $this->validator->validate(definition: $definition);
	}//end validateDefinition()

	/**
	 * Map sample source rows through an unsaved pack definition.
	 *
	 * Runs the same MappingEngine::mapRow() an import runs, so an editor can
	 * show what a definition does before it is saved. Rows are numbered from 1
	 * in the order given. `skipRows` is not applied: those numbers refer to
	 * rows of a source file, not to positions in the sample.
	 *
	 * @param array<string, mixed> $definition The pack definition document.
	 * @param array<int, array<string, mixed>> $rows Sample source rows, column (or key) => value.
	 *
	 * @return list<array{rowNumber: int, data: array<string, mixed>, errors: list<array<string, mixed>>}>
	 *
	 * @throws InvalidPackDefinitionException When the definition is structurally invalid.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-mapping-engine-must-apply-pack-transforms-per-row-and-never-leak-unresolved-references
	 */
	public function previewRows(array $definition, array $rows): array {
		$this->validator->assertValid(definition: $definition);

		$results = [];
		foreach (array_values($rows) as $index => $row) {
			$rowNumber = ($index + 1);
			$mapped = $this->mappingEngine->mapRow(pack: $definition, sourceRow: $row, rowNumber: $rowNumber);
			$results[] = [
				'rowNumber' => $rowNumber,
				'data' => $mapped['data'],
				'errors' => $mapped['errors'],
			];
		}

		return $results;
	}//end previewRows()

	/**
	 * Import a pack document from a JSON file (upload) — upserts by the
	 * document's own `id`, so re-importing an updated pack (e.g. shared from
	 * another instance) updates the existing row rather than colliding.
	 *
	 * The result says which of the two happened, decided by the write itself.
	 *
	 * @param array<string, mixed> $definition The decoded pack definition document.
	 * @param string|null $ownerUid The importing admin's uid.
	 *
	 * @return MigrationPackImportResult The stored pack, and whether it was created or replaced.
	 *
	 * @throws InvalidPackDefinitionException When the definition is structurally invalid.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function importDefinition(array $definition, ?string $ownerUid = null): MigrationPackImportResult {
		$this->validator->assertValid(definition: $definition);

		$slug = (string)$definition['id'];

		try {
			$existing = $this->mapper->findByPackSlug(packSlug: $slug);
		} catch (DoesNotExistException $e) {
			return new MigrationPackImportResult(
				pack: $this->create(definition: $definition, ownerUid: $ownerUid),
				created: true
			);
		}

		return new MigrationPackImportResult(
			pack: $this->update(id: $existing->getId(), definition: $definition),
			created: false
		);
	}//end importDefinition()

	/**
	 * Whether the slug belonged to a built-in pack that was deleted.
	 *
	 * @param string $packSlug The pack document's own `id`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-slug-of-a-built-in-pack-must-not-change
	 */
	private function isDeletedBuiltinSlug(string $packSlug): bool {
		$slugs = $this->appConfig->getValueArray('openregister', self::DELETED_BUILTIN_SLUGS_KEY, [], true);

		return in_array($packSlug, $slugs, true);
	}//end isDeletedBuiltinSlug()

	/**
	 * Remember the slug of a deleted built-in pack.
	 *
	 * @param string $packSlug The pack document's own `id`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-slug-of-a-built-in-pack-must-not-change
	 */
	private function rememberDeletedBuiltinSlug(string $packSlug): void {
		if ($this->isDeletedBuiltinSlug(packSlug: $packSlug) === true) {
			return;
		}

		$slugs = $this->appConfig->getValueArray('openregister', self::DELETED_BUILTIN_SLUGS_KEY, [], true);
		$slugs[] = $packSlug;
		$this->appConfig->setValueArray('openregister', self::DELETED_BUILTIN_SLUGS_KEY, array_values($slugs), true);
	}//end rememberDeletedBuiltinSlug()

	/**
	 * Take a slug off the deleted built-in list, once its pack is seeded again.
	 *
	 * @param string $packSlug The pack document's own `id`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-the-slug-of-a-built-in-pack-must-not-change
	 */
	private function forgetDeletedBuiltinSlug(string $packSlug): void {
		if ($this->isDeletedBuiltinSlug(packSlug: $packSlug) === false) {
			return;
		}

		$slugs = $this->appConfig->getValueArray('openregister', self::DELETED_BUILTIN_SLUGS_KEY, [], true);
		$remaining = array_values(array_diff($slugs, [$packSlug]));
		$this->appConfig->setValueArray('openregister', self::DELETED_BUILTIN_SLUGS_KEY, $remaining, true);
	}//end forgetDeletedBuiltinSlug()

	/**
	 * Whether a pack with the given slug already exists.
	 *
	 * @param string $packSlug The pack document's own `id`.
	 *
	 * @return bool
	 */
	private function slugExists(string $packSlug): bool {
		try {
			$this->mapper->findByPackSlug(packSlug: $packSlug);
			return true;
		} catch (DoesNotExistException $e) {
			return false;
		}
	}//end slugExists()
}//end class
