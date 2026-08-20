<?php

/**
 * SeedDirectoryVirtualSchemas — seeds the always-available `directory` virtual
 * register and its `nc-user` / `nc-group` virtual schemas.
 *
 * Each schema is a normal OpenRegister schema row carrying a schema-level
 * `x-schema-org` semantic marker and an `x-openregister-object-source.provider`
 * binding, so its objects are served live (read-only) by the matching
 * ObjectSourceProvider and it is discoverable by SemanticTypeResolver with no
 * resolver change. The register's `application` is `openregister`, so the ADR-048
 * app-enabled gate treats it as always enabled — every instance therefore has a
 * Person and an Organization provider with no third-party app installed.
 *
 * The rows come from {@see \OCA\OpenRegister\Service\ObjectSource\NcEntitySemanticMap};
 * this step materialises the always-available core rows (requiredApp === null).
 * Idempotent: existing register/schemas are reused, never duplicated. Mirrors
 * OpenRegister's register-import-via-Repair convention (runs during `occ upgrade`,
 * before peer autoloaders).
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectSource\NcEntitySemanticMap;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the virtual `directory` register with the core NC-entity schemas.
 */
class SeedDirectoryVirtualSchemas implements IRepairStep {

	/**
	 * Read-only minimal property sets per virtual schema slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const SCHEMA_PROPERTIES = [
		'nc-user' => [
			'id' => ['type' => 'string', 'title' => 'User ID', 'description' => 'The Nextcloud user id (uid).'],
			'displayName' => ['type' => 'string', 'title' => 'Display name', 'description' => 'The user display name.'],
			'email' => ['type' => 'string', 'title' => 'Email', 'description' => 'The user email address.'],
		],
		'nc-group' => [
			'id' => ['type' => 'string', 'title' => 'Group ID', 'description' => 'The Nextcloud group id (gid).'],
			'displayName' => ['type' => 'string', 'title' => 'Display name', 'description' => 'The group display name.'],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Register data mapper.
	 * @param SchemaMapper $schemaMapper Schema data mapper.
	 * @param LoggerInterface $logger Logger for seed diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
	 */
	public function getName(): string {
		return 'Seed OpenRegister directory virtual schemas (nc-user, nc-group)';
	}//end getName()

	/**
	 * Run the repair step, seeding the directory register + core schemas.
	 *
	 * Never throws: a seed failure logs a warning and leaves the instance
	 * otherwise healthy (the providers simply have no bound schema to serve).
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
	 */
	public function run(IOutput $output): void {
		try {
			$register = $this->ensureRegister();
			$schemaIds = $register->getSchemas();
			$changed = false;

			foreach (NcEntitySemanticMap::ENTITIES as $row) {
				// This step only owns the core rows that live on the always-available
				// `directory` register (all of which are Nextcloud-core, requiredApp
				// null); app-gated rows on their own app-named registers are seeded by
				// SeedAppVirtualSchemas.
				if ($row['register'] !== NcEntitySemanticMap::DIRECTORY_REGISTER) {
					continue;
				}

				$schema = $this->ensureSchema(row: $row);
				if (in_array($schema->getId(), $schemaIds, false) === false) {
					$schemaIds[] = $schema->getId();
					$changed = true;
				}

				$output->info(sprintf('Directory schema "%s" (id %s) ready', $row['schema'], (string)$schema->getId()));
			}//end foreach

			if ($changed === true) {
				$register->setSchemas($schemaIds);
				$this->registerMapper->update($register);
			}

			$output->info('Directory virtual register seeded');
		} catch (Throwable $e) {
			$this->logger->warning('[SeedDirectoryVirtualSchemas] seed failed: ' . $e->getMessage());
			$output->warning('Directory virtual schema seed skipped: ' . $e->getMessage());
		}//end try
	}//end run()

	/**
	 * Find or create the `directory` virtual register (application: openregister).
	 *
	 * @return Register The existing or newly created register.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
	 */
	private function ensureRegister(): Register {
		try {
			return $this->registerMapper->find(NcEntitySemanticMap::DIRECTORY_REGISTER, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// Not found — create it.
			return $this->registerMapper->createFromArray(
				object: [
					'title' => 'Directory',
					'slug' => NcEntitySemanticMap::DIRECTORY_REGISTER,
					'description' => 'Read-only virtual register projecting Nextcloud directory entities (users, groups) as OpenRegister objects.',
					'application' => 'openregister',
					'schemas' => [],
				]
			);
		}//end try
	}//end ensureRegister()

	/**
	 * Find or create one virtual schema for a semantic-map row.
	 *
	 * @param array{register: string, schema: string, schemaOrg: string, provider: string, requiredApp: string|null} $row The semantic-map row.
	 *
	 * @return Schema The existing or newly created schema.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
	 */
	private function ensureSchema(array $row): Schema {
		try {
			return $this->schemaMapper->find($row['schema'], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// Not found — create it as a read-only object-source-backed schema.
			$properties = (self::SCHEMA_PROPERTIES[$row['schema']] ?? []);

			return $this->schemaMapper->createFromArray(
				object: [
					'title' => $row['schema'],
					'slug' => $row['schema'],
					'description' => sprintf('Read-only virtual schema for Nextcloud %s (%s).', $row['schema'], $row['schemaOrg']),
					'properties' => $properties,
					'configuration' => [
						'x-schema-org' => $row['schemaOrg'],
						'x-openregister-object-source' => [
							'provider' => $row['provider'],
							'readOnly' => true,
						],
					],
				]
			);
		}//end try
	}//end ensureSchema()
}//end class
