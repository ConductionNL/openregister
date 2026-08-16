<?php

/**
 * SeedAppVirtualSchemas — seeds the app-gated virtual registers (`contacts`,
 * `calendar`, `files`, `deck`, `talk`, `tasks`) and their `nc-contact` /
 * `nc-event` / `nc-file` / `nc-card` / `nc-conversation` / `nc-task` virtual
 * schemas.
 *
 * Each of these entities is served live (read-only) by a matching
 * ObjectSourceProvider that wraps a Nextcloud app's own API. Unlike the always-
 * available `directory` register, each schema here lives on its OWN register whose
 * `application` is the backing app (`contacts`/`calendar`/`files`), so the ADR-048
 * app-enabled gate degrades the projection to an empty list when that app is
 * uninstalled — without touching the seeded rows.
 *
 * The rows come from {@see \OCA\OpenRegister\Service\ObjectSource\NcEntitySemanticMap};
 * this step materialises every row whose register is NOT the core `directory`
 * register (the directory rows are owned by {@see SeedDirectoryVirtualSchemas}).
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
 * Seeds the app-gated virtual registers with their NC-entity schemas.
 */
class SeedAppVirtualSchemas implements IRepairStep {

	/**
	 * Read-only minimal property sets per virtual schema slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const SCHEMA_PROPERTIES = [
		'nc-contact' => [
			'id' => ['type' => 'string', 'title' => 'Contact ID', 'description' => 'The Nextcloud contact UID.'],
			'fullName' => ['type' => 'string', 'title' => 'Full name', 'description' => 'The contact display name (vCard FN).'],
			'email' => ['type' => 'string', 'title' => 'Email', 'description' => 'The contact primary email address.'],
			'org' => ['type' => 'string', 'title' => 'Organization', 'description' => 'The contact organization (vCard ORG).'],
		],
		'nc-event' => [
			'id' => ['type' => 'string', 'title' => 'Event ID', 'description' => 'The calendar event UID.'],
			'summary' => ['type' => 'string', 'title' => 'Summary', 'description' => 'The event title (VEVENT SUMMARY).'],
			'startDate' => ['type' => 'string', 'title' => 'Start date', 'description' => 'The event start (ISO-8601).'],
			'endDate' => ['type' => 'string', 'title' => 'End date', 'description' => 'The event end (ISO-8601).'],
			'location' => ['type' => 'string', 'title' => 'Location', 'description' => 'The event location.'],
		],
		'nc-file' => [
			'id' => ['type' => 'string', 'title' => 'File ID', 'description' => 'The Nextcloud file id (fileid).'],
			'name' => ['type' => 'string', 'title' => 'Name', 'description' => 'The file name.'],
			'path' => ['type' => 'string', 'title' => 'Path', 'description' => 'The user-relative file path.'],
			'mimetype' => ['type' => 'string', 'title' => 'MIME type', 'description' => 'The file MIME type.'],
			'size' => ['type' => 'integer', 'title' => 'Size', 'description' => 'The file size in bytes.'],
			'mtime' => ['type' => 'integer', 'title' => 'Modified time', 'description' => 'The file modification time (Unix timestamp).'],
		],
		'nc-card' => [
			'id' => ['type' => 'string', 'title' => 'Card ID', 'description' => 'The Nextcloud Deck card id.'],
			'title' => ['type' => 'string', 'title' => 'Title', 'description' => 'The card title.'],
			'description' => ['type' => 'string', 'title' => 'Description', 'description' => 'The card description (markdown).'],
			'stackId' => ['type' => 'integer', 'title' => 'Stack ID', 'description' => 'The id of the stack (list) the card lives in.'],
			'boardId' => ['type' => 'integer', 'title' => 'Board ID', 'description' => 'The id of the board the card lives on.'],
			'duedate' => ['type' => 'string', 'title' => 'Due date', 'description' => 'The card due date (ISO-8601), or null.'],
		],
		'nc-conversation' => [
			'id' => ['type' => 'string', 'title' => 'Conversation ID', 'description' => 'The Talk room token.'],
			'name' => ['type' => 'string', 'title' => 'Name', 'description' => 'The raw conversation name.'],
			'displayName' => ['type' => 'string', 'title' => 'Display name', 'description' => 'The display name for the acting user.'],
			'type' => ['type' => 'integer', 'title' => 'Type', 'description' => 'The Talk room type (1=one-to-one, 2=group, 3=public).'],
			'participantCount' => ['type' => 'integer', 'title' => 'Participants', 'description' => 'The participant count.'],
			'lastActivity' => ['type' => 'string', 'title' => 'Last activity', 'description' => 'The last activity (ISO-8601), or null.'],
		],
		'nc-task' => [
			'id' => ['type' => 'string', 'title' => 'Task ID', 'description' => 'The CalDAV VTODO uid.'],
			'title' => ['type' => 'string', 'title' => 'Title', 'description' => 'The task title (VTODO SUMMARY).'],
			'description' => ['type' => 'string', 'title' => 'Description', 'description' => 'The task description (VTODO DESCRIPTION).'],
			'status' => ['type' => 'string', 'title' => 'Status', 'description' => 'The task status (VTODO STATUS).'],
			'dueDate' => ['type' => 'string', 'title' => 'Due date', 'description' => 'The task due date (VTODO DUE), or null.'],
			'completed' => ['type' => 'string', 'title' => 'Completed', 'description' => 'The completion timestamp (VTODO COMPLETED), or null.'],
			'priority' => ['type' => 'integer', 'title' => 'Priority', 'description' => 'The task priority (VTODO PRIORITY), or null.'],
		],
	];

	/**
	 * Human-readable register descriptions keyed by register slug.
	 *
	 * @var array<string, string>
	 */
	private const REGISTER_DESCRIPTIONS = [
		'contacts' => 'Read-only virtual register projecting the Nextcloud Contacts app\'s contacts as OpenRegister objects.',
		'calendar' => 'Read-only virtual register projecting the Nextcloud Calendar app\'s events as OpenRegister objects.',
		'files' => 'Read-only virtual register projecting the acting user\'s Nextcloud files as OpenRegister objects.',
		'deck' => 'Read-only virtual register projecting the Nextcloud Deck app\'s cards as OpenRegister objects.',
		'talk' => 'Read-only virtual register projecting the Nextcloud Talk app\'s conversations as OpenRegister objects.',
		'tasks' => 'Read-only virtual register projecting the acting user\'s CalDAV tasks (VTODOs) as OpenRegister objects.',
	];

	/**
	 * Per-schema extras merged into the `x-openregister-object-source` config.
	 *
	 * `nc-task` reuses the shared `caldav-vtodo` provider whose default scope only
	 * surfaces VTODOs linked (via X-OPENREGISTER) to the bound register/schema. As
	 * a projection of the acting user's whole task list it opts out of that scoping.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const SCHEMA_SOURCE_CONFIG = [
		'nc-task' => ['unscoped' => true],
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
		return 'Seed OpenRegister app virtual schemas (nc-contact, nc-event, nc-file, nc-card, nc-conversation, nc-task)';
	}//end getName()

	/**
	 * Run the repair step, seeding each app-gated register + its schemas.
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
			// Group the app-gated rows (register !== directory) by their register
			// slug so each register is created once with all of its schemas.
			$rowsByRegister = [];
			foreach (NcEntitySemanticMap::ENTITIES as $row) {
				if ($row['register'] === NcEntitySemanticMap::DIRECTORY_REGISTER) {
					continue;
				}

				// The `tables` register is NOT a single-schema projection: its
				// per-table schemas are auto-seeded by SeedTablesVirtualSchemas /
				// `occ openregister:tables:sync` (design D7). The semantic-map
				// `tables` row only records the provider + app gate, so it must
				// not be materialised into a nominal schema here.
				if ($row['register'] === 'tables') {
					continue;
				}

				$rowsByRegister[$row['register']][] = $row;
			}

			foreach ($rowsByRegister as $registerSlug => $rows) {
				$register = $this->ensureRegister(slug: $registerSlug, application: $rows[0]['application']);
				$schemaIds = $register->getSchemas();
				$changed = false;

				foreach ($rows as $row) {
					$schema = $this->ensureSchema(row: $row);
					if (in_array($schema->getId(), $schemaIds, false) === false) {
						$schemaIds[] = $schema->getId();
						$changed = true;
					}

					$output->info(sprintf('App schema "%s" (id %s) ready', $row['schema'], (string)$schema->getId()));
				}

				if ($changed === true) {
					$register->setSchemas($schemaIds);
					$this->registerMapper->update($register);
				}

				$output->info(sprintf('App virtual register "%s" seeded', $registerSlug));
			}//end foreach
		} catch (Throwable $e) {
			$this->logger->warning('[SeedAppVirtualSchemas] seed failed: ' . $e->getMessage());
			$output->warning('App virtual schema seed skipped: ' . $e->getMessage());
		}//end try
	}//end run()

	/**
	 * Find or create one app-gated virtual register.
	 *
	 * @param string $slug The register slug (e.g. `contacts`).
	 * @param string $application The register application (drives the ADR-048 gate).
	 *
	 * @return Register The existing or newly created register.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
	 */
	private function ensureRegister(string $slug, string $application): Register {
		try {
			return $this->registerMapper->find($slug, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// Not found — create it.
			return $this->registerMapper->createFromArray(
				object: [
					'title' => ucfirst($slug),
					'slug' => $slug,
					'description' => (self::REGISTER_DESCRIPTIONS[$slug] ?? sprintf('Read-only virtual register for Nextcloud %s.', $slug)),
					'application' => $application,
					'schemas' => [],
				]
			);
		}//end try
	}//end ensureRegister()

	/**
	 * Find or create one virtual schema for a semantic-map row.
	 *
	 * @param array{schema: string, schemaOrg: string, provider: string} $row The semantic-map row (only the schema fields are read here).
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

			$objectSource = [
				'provider' => $row['provider'],
				'readOnly' => true,
			];

			// Per-schema provider `config` extras (threaded to the provider's
			// find/findAll `$config` argument by GetObject). `nc-task` reuses the
			// shared `caldav-vtodo` provider, which by default only surfaces VTODOs
			// whose X-OPENREGISTER link points at the bound register/schema. As a
			// virtual projection of ALL of the acting user's tasks, `nc-task` opts
			// out of that link scoping with `config.unscoped`.
			$providerConfig = (self::SCHEMA_SOURCE_CONFIG[$row['schema']] ?? []);
			if (empty($providerConfig) === false) {
				$objectSource['config'] = $providerConfig;
			}

			return $this->schemaMapper->createFromArray(
				object: [
					'title' => $row['schema'],
					'slug' => $row['schema'],
					'description' => sprintf('Read-only virtual schema for Nextcloud %s (%s).', $row['schema'], $row['schemaOrg']),
					'properties' => $properties,
					'configuration' => [
						'x-schema-org' => $row['schemaOrg'],
						'x-openregister-object-source' => $objectSource,
					],
				]
			);
		}//end try
	}//end ensureSchema()
}//end class
