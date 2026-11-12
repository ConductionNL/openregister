<?php

/**
 * OpenRegister BundleManageGuard
 *
 * Refuses a shared bundle that would overwrite a register or schema the person
 * installing it may not manage. An install writes as the system, so the manage
 * right has to be judged here, against the person who asked for the install.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use Throwable;

/**
 * Judges the registers and schemas a bundle would overwrite, for the signed-in user.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
class BundleManageGuard {

	/**
	 * Every bundle path ImportHandler writes objects from (components.objects,
	 * top-level objects, x-openregister.seedData.objects). A new seed bucket in
	 * the importer belongs here too.
	 *
	 * @var list<list<string>>
	 */
	public const SEED_OBJECT_PATHS = [
		['components', 'objects'],
		['objects'],
		['x-openregister', 'seedData', 'objects'],
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper   $registerMapper Finds an existing register by slug.
	 * @param SchemaMapper     $schemaMapper   Finds existing schemas by slug.
	 * @param SchemaManageGate $manageGate     Who may manage a register or schema.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly SchemaManageGate $manageGate,
	) {
	}//end __construct()

	/**
	 * Refuse the bundle when it names an existing register or schema the caller may not manage.
	 *
	 * New registers and schemas are not judged here: who may install at all is
	 * FederatedConfigAccess::canInstall(). A bundle that seeds objects (any of
	 * SEED_OBJECT_PATHS) is refused for a non-administrator. Administrators always pass.
	 *
	 * @param array<string, mixed> $bundle The bundle.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the bundle would overwrite a definition the caller may not manage.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function assertAllowed(array $bundle): void {
		if ($this->manageGate->isAdministrator() === true) {
			return;
		}

		$registerSlugs = $this->slugs(documents: ($bundle['components']['registers'] ?? []));
		foreach ($registerSlugs as $slug) {
			if ($this->mayOverwriteRegister(slug: $slug) === false) {
				throw new NotAuthorizedException(message: 'This bundle would change a register you may not manage');
			}
		}

		$schemaSlugs = $this->slugs(documents: ($bundle['components']['schemas'] ?? []));
		foreach ($schemaSlugs as $slug) {
			if ($this->mayOverwriteSchema(slug: $slug) === false) {
				throw new NotAuthorizedException(message: 'This bundle would change a schema you may not manage');
			}
		}

		// Seed objects are written as the system, with no RBAC, into registers
		// and schemas the importer resolves by several paths. Rather than chase
		// each path, a bundle that seeds objects is for administrators only
		// (and SystemOperationContext / CLI work, which do not come through here).
		if ($this->carriesSeedObjects(bundle: $bundle) === true) {
			throw new NotAuthorizedException(message: 'Only an administrator may install a bundle that seeds objects');
		}
	}//end assertAllowed()

	/**
	 * Whether the caller may overwrite the register with this slug (true when there is none).
	 *
	 * @param string $slug The register slug.
	 *
	 * @return bool False when one exists that the caller may not manage, or the lookup fails.
	 */
	private function mayOverwriteRegister(string $slug): bool {
		try {
			// Every row the importer's register lookups could pick (uuid in any
			// case, slug in any case, numeric id), across all organisations;
			// the manage gate judges each one.
			$registers = $this->registerMapper->findAllByIdentifier(identifier: $slug);
		} catch (Throwable $e) {
			return false;
		}

		foreach ($registers as $register) {
			if ($this->manageGate->mayManageRegister(register: $register) === false) {
				return false;
			}
		}

		return true;
	}//end mayOverwriteRegister()

	/**
	 * Whether the caller may overwrite every schema with this slug (true when there is none).
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return bool False when one exists that the caller may not manage, or the lookup fails.
	 */
	private function mayOverwriteSchema(string $slug): bool {
		try {
			// The lookup sees every schema the system write could reach; the manage gate judges it.
			// Every row the importer's lookups could pick: uuid, numeric id, or the
			// slug in any case (find(), findBySlugInIds(), findBySlug()). No RBAC or
			// organisation filter; the manage gate below judges each row.
			$schemas = $this->schemaMapper->findAllByIdentifier(identifier: $slug);
		} catch (Throwable $e) {
			return false;
		}

		foreach ($schemas as $schema) {
			if ($this->manageGate->mayManage(schema: $schema) === false) {
				return false;
			}
		}

		return true;
	}//end mayOverwriteSchema()

	/**
	 * Whether the bundle holds objects in any bucket ImportHandler seeds from.
	 *
	 * @param array<string, mixed> $bundle The bundle.
	 *
	 * @return bool True when any seed bucket is a non-empty list.
	 */
	private function carriesSeedObjects(array $bundle): bool {
		foreach (self::SEED_OBJECT_PATHS as $path) {
			$value = $bundle;
			foreach ($path as $key) {
				if (is_array($value) === false) {
					break;
				}

				$value = ($value[$key] ?? null);
			}

			if (is_array($value) === true && $value !== []) {
				return true;
			}
		}

		return false;
	}//end carriesSeedObjects()

	/**
	 * The slugs of a bundle's register or schema documents.
	 *
	 * @param mixed $documents The documents, keyed by slug or carrying a `slug`.
	 *
	 * @return list<string> The slugs.
	 */
	private function slugs(mixed $documents): array {
		if (is_array($documents) === false) {
			return [];
		}

		$slugs = [];
		foreach ($documents as $key => $document) {
			$slug = '';
			if (is_array($document) === true && is_scalar($document['slug'] ?? null) === true) {
				$slug = (string)$document['slug'];
			} else if (is_string($key) === true) {
				$slug = $key;
			}

			if ($slug !== '') {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}//end slugs()
}//end class
