<?php

/**
 * Register and schema references in a form that survives a move between instances.
 *
 * A saved view names its registers and schemas by id, slug or uuid, and a
 * watch names its register and schema. Ids are local to an instance, so the
 * export writes each reference as the slug, and the import resolves the slug
 * on the target back to its id. A reference the target cannot resolve is
 * reported, never created (REQ-PUM-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\UserMigration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\UserMigration;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Throwable;

/**
 * Translates register and schema references between an instance's ids and portable slugs.
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
 */
class RegisterReferences {

	/**
	 * The kind of a register reference.
	 *
	 * @var string
	 */
	public const REGISTER = 'register';

	/**
	 * The kind of a schema reference.
	 *
	 * @var string
	 */
	public const SCHEMA = 'schema';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registers The registers of this instance.
	 * @param SchemaMapperInterface   $schemas   The schemas of this instance.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
	) {
	}//end __construct()

	/**
	 * The portable form of a reference: the slug, or the reference as it was
	 * when this instance cannot resolve it.
	 *
	 * @param string     $kind      REGISTER or SCHEMA.
	 * @param string|int $reference The id, slug or uuid as stored.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
	 */
	public function portable(string $kind, string|int $reference): string {
		$slug = $this->lookup(kind: $kind, reference: $reference)['slug'] ?? null;
		if (is_string($slug) === true && $slug !== '') {
			return $slug;
		}

		return (string)$reference;
	}//end portable()

	/**
	 * The local id of a portable reference, or null when this instance does not hold it.
	 *
	 * @param string $kind      REGISTER or SCHEMA.
	 * @param string $reference The slug from the export.
	 *
	 * @return string|null The id on this instance.
	 *
	 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-objects-are-not-exported-and-both-ends-say-so-req-pum-002
	 */
	public function local(string $kind, string $reference): ?string {
		if ($reference === '') {
			return null;
		}

		$id = $this->lookup(kind: $kind, reference: $reference)['id'] ?? null;
		if ($id === null) {
			return null;
		}

		return (string)$id;
	}//end local()

	/**
	 * The id and slug of a register or schema, or an empty array when it is not here.
	 *
	 * Read as the system: a migration runs from the command line for one user
	 * and must resolve every register that user's views name.
	 *
	 * @param string     $kind      REGISTER or SCHEMA.
	 * @param string|int $reference The reference.
	 *
	 * @return array{id?: int|string|null, slug?: string|null}
	 */
	private function lookup(string $kind, string|int $reference): array {
		try {
			if ($kind === self::REGISTER) {
				$entity = $this->registers->find(id: $reference, _rbac: false, _multitenancy: false);
			} else {
				$entity = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
			}
		} catch (Throwable) {
			return [];
		}

		return ['id' => $entity->getId(), 'slug' => $entity->getSlug()];
	}//end lookup()
}//end class
