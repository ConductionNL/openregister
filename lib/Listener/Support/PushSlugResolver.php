<?php

/**
 * OpenRegister PushSlugResolver
 *
 * Resolves the register and schema slugs a notify_push message names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Register and schema slugs for push channels and payloads.
 *
 * System-internal lookups: they bypass RBAC and multitenancy. The push
 * listener is system-level, not user-scoped — without the bypass the lookup
 * throws "not found" whenever the request user's tenant doesn't own the
 * register or schema, leaving the push payload's slug fields null (issue #1454).
 * Who RECEIVES a push is decided elsewhere, from the object's readers.
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */
final class PushSlugResolver {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registerMapper Mapper for resolving register slugs.
	 * @param SchemaMapperInterface   $schemaMapper   Mapper for resolving schema slugs.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Resolve a register's slug from its UUID.
	 *
	 * @param string|null $registerUuid The register UUID from ObjectEntity::getRegister().
	 *
	 * @return string|null The register slug, or null when not resolvable.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public function registerSlug(?string $registerUuid): ?string {
		return $this->slugOf(
			id: $registerUuid,
			find: fn (string $id) => $this->registerMapper->find(id: $id, _rbac: false, _multitenancy: false)
		);
	}//end registerSlug()

	/**
	 * Resolve a schema's slug from its UUID.
	 *
	 * @param string|null $schemaUuid The schema UUID from ObjectEntity::getSchema().
	 *
	 * @return string|null The schema slug, or null when not resolvable.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public function schemaSlug(?string $schemaUuid): ?string {
		return $this->slugOf(
			id: $schemaUuid,
			find: fn (string $id) => $this->schemaMapper->find(id: $id, _rbac: false, _multitenancy: false)
		);
	}//end schemaSlug()

	/**
	 * Look one register or schema up as the system and answer its slug.
	 *
	 * @param string|null                                       $id   The UUID or id the object carries.
	 * @param \Closure(string): (\OCA\OpenRegister\Db\Register|\OCA\OpenRegister\Db\Schema) $find Looks the id up as the system.
	 *
	 * @return string|null The slug, or null when blank or not resolvable.
	 */
	private function slugOf(?string $id, \Closure $find): ?string {
		if (self::isBlank(id: $id) === true) {
			return null;
		}

		try {
			return $find($id)->getSlug();
		} catch (\Throwable $e) {
			return null;
		}
	}//end slugOf()

	/**
	 * Whether no id was carried.
	 *
	 * @param string|null $id The UUID or id.
	 *
	 * @return bool
	 *
	 * @phpstan-assert-if-false string $id
	 */
	private static function isBlank(?string $id): bool {
		return ($id === null || $id === '');
	}//end isBlank()
}//end class
