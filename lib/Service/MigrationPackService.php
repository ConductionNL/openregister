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
 * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MigrationPack;
use OCA\OpenRegister\Db\MigrationPackMapper;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Class MigrationPackService
 *
 * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
 */
class MigrationPackService
{
    /**
     * Constructor.
     *
     * @param MigrationPackMapper     $mapper    Data mapper.
     * @param PackDefinitionValidator $validator Pack definition validator.
     */
    public function __construct(
        private readonly MigrationPackMapper $mapper,
        private readonly PackDefinitionValidator $validator
    ) {
    }//end __construct()

    /**
     * List every stored migration pack.
     *
     * @return MigrationPack[]
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    public function findAll(): array
    {
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
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    public function find(int $id): MigrationPack
    {
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
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    public function findByPackSlug(string $packSlug): MigrationPack
    {
        return $this->mapper->findByPackSlug(packSlug: $packSlug);
    }//end findByPackSlug()

    /**
     * Create a migration pack from a definition document.
     *
     * @param array<string, mixed> $definition The pack definition document (the JSON body).
     * @param string|null          $ownerUid   The creating admin's uid, or null for a system/built-in seed.
     * @param bool                 $builtin    Whether to mark this row as a built-in reference pack.
     *
     * @return MigrationPack
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $builtin only distinguishes admin-authored packs from the seeded reference pack.
     *
     * @throws InvalidArgumentException When the definition is structurally invalid or its slug already exists.
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    public function create(array $definition, ?string $ownerUid=null, bool $builtin=false): MigrationPack
    {
        $this->validator->assertValid(definition: $definition);

        $slug = (string) $definition['id'];
        if ($this->slugExists(packSlug: $slug) === true) {
            throw new InvalidArgumentException('A migration pack with id "'.$slug.'" already exists');
        }

        $pack = new MigrationPack();
        $pack->setPackSlug($slug);
        $pack->setName((string) $definition['name']);
        $pack->setSourceFormat((string) $definition['sourceFormat']);
        $pack->setVersion((string) $definition['version']);
        $pack->setDefinition(json_encode($definition, JSON_UNESCAPED_SLASHES));
        $pack->setBuiltin($builtin);
        $pack->setOwner($ownerUid);
        $pack->setCreatedAt(new DateTime());
        $pack->setUpdatedAt(new DateTime());

        return $this->mapper->insert(entity: $pack);
    }//end create()

    /**
     * Update an existing migration pack's definition.
     *
     * @param int                  $id         The migration pack id.
     * @param array<string, mixed> $definition The replacement pack definition document.
     *
     * @return MigrationPack
     *
     * @throws DoesNotExistException When no row matches.
     * @throws InvalidArgumentException When the definition is structurally invalid, or its slug collides with a different pack.
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    public function update(int $id, array $definition): MigrationPack
    {
        $this->validator->assertValid(definition: $definition);

        $pack = $this->mapper->find(id: $id);

        $slug = (string) $definition['id'];
        if ($slug !== $pack->getPackSlug() && $this->slugExists(packSlug: $slug) === true) {
            throw new InvalidArgumentException('A migration pack with id "'.$slug.'" already exists');
        }

        $pack->setPackSlug($slug);
        $pack->setName((string) $definition['name']);
        $pack->setSourceFormat((string) $definition['sourceFormat']);
        $pack->setVersion((string) $definition['version']);
        $pack->setDefinition(json_encode($definition, JSON_UNESCAPED_SLASHES));
        $pack->setUpdatedAt(new DateTime());

        return $this->mapper->update(entity: $pack);
    }//end update()

    /**
     * Delete a migration pack.
     *
     * @param int $id The migration pack id.
     *
     * @return void
     *
     * @throws DoesNotExistException When no row matches.
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    public function delete(int $id): void
    {
        $pack = $this->mapper->find(id: $id);
        $this->mapper->delete(entity: $pack);
    }//end delete()

    /**
     * Import a pack document from a JSON file (upload) — upserts by the
     * document's own `id`, so re-importing an updated pack (e.g. shared from
     * another instance) updates the existing row rather than colliding.
     *
     * @param array<string, mixed> $definition The decoded pack definition document.
     * @param string|null          $ownerUid   The importing admin's uid.
     *
     * @return MigrationPack
     *
     * @throws InvalidArgumentException When the definition is structurally invalid.
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    public function importDefinition(array $definition, ?string $ownerUid=null): MigrationPack
    {
        $this->validator->assertValid(definition: $definition);

        $slug = (string) $definition['id'];

        try {
            $existing = $this->mapper->findByPackSlug(packSlug: $slug);
        } catch (DoesNotExistException $e) {
            return $this->create(definition: $definition, ownerUid: $ownerUid);
        }

        return $this->update(id: $existing->getId(), definition: $definition);
    }//end importDefinition()

    /**
     * Whether a pack with the given slug already exists.
     *
     * @param string $packSlug The pack document's own `id`.
     *
     * @return bool
     */
    private function slugExists(string $packSlug): bool
    {
        try {
            $this->mapper->findByPackSlug(packSlug: $packSlug);
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }//end slugExists()
}//end class
