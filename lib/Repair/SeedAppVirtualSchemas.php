<?php

/**
 * SeedAppVirtualSchemas — seeds the app-gated virtual registers (`contacts`,
 * `calendar`, `files`) and their `nc-contact` / `nc-event` / `nc-file` virtual
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
class SeedAppVirtualSchemas implements IRepairStep
{

    /**
     * Read-only minimal property sets per virtual schema slug.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SCHEMA_PROPERTIES = [
        'nc-contact' => [
            'id'       => ['type' => 'string', 'title' => 'Contact ID', 'description' => 'The Nextcloud contact UID.'],
            'fullName' => ['type' => 'string', 'title' => 'Full name', 'description' => 'The contact display name (vCard FN).'],
            'email'    => ['type' => 'string', 'title' => 'Email', 'description' => 'The contact primary email address.'],
            'org'      => ['type' => 'string', 'title' => 'Organization', 'description' => 'The contact organization (vCard ORG).'],
        ],
        'nc-event'   => [
            'id'        => ['type' => 'string', 'title' => 'Event ID', 'description' => 'The calendar event UID.'],
            'summary'   => ['type' => 'string', 'title' => 'Summary', 'description' => 'The event title (VEVENT SUMMARY).'],
            'startDate' => ['type' => 'string', 'title' => 'Start date', 'description' => 'The event start (ISO-8601).'],
            'endDate'   => ['type' => 'string', 'title' => 'End date', 'description' => 'The event end (ISO-8601).'],
            'location'  => ['type' => 'string', 'title' => 'Location', 'description' => 'The event location.'],
        ],
        'nc-file'    => [
            'id'       => ['type' => 'string', 'title' => 'File ID', 'description' => 'The Nextcloud file id (fileid).'],
            'name'     => ['type' => 'string', 'title' => 'Name', 'description' => 'The file name.'],
            'path'     => ['type' => 'string', 'title' => 'Path', 'description' => 'The user-relative file path.'],
            'mimetype' => ['type' => 'string', 'title' => 'MIME type', 'description' => 'The file MIME type.'],
            'size'     => ['type' => 'integer', 'title' => 'Size', 'description' => 'The file size in bytes.'],
            'mtime'    => ['type' => 'integer', 'title' => 'Modified time', 'description' => 'The file modification time (Unix timestamp).'],
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
        'files'    => 'Read-only virtual register projecting the acting user\'s Nextcloud files as OpenRegister objects.',
    ];

    /**
     * Constructor.
     *
     * @param RegisterMapper  $registerMapper Register data mapper.
     * @param SchemaMapper    $schemaMapper   Schema data mapper.
     * @param LoggerInterface $logger         Logger for seed diagnostics.
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
    public function getName(): string
    {
        return 'Seed OpenRegister app virtual schemas (nc-contact, nc-event, nc-file)';
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
    public function run(IOutput $output): void
    {
        try {
            // Group the app-gated rows (register !== directory) by their register
            // slug so each register is created once with all of its schemas.
            $rowsByRegister = [];
            foreach (NcEntitySemanticMap::ENTITIES as $row) {
                if ($row['register'] === NcEntitySemanticMap::DIRECTORY_REGISTER) {
                    continue;
                }

                $rowsByRegister[$row['register']][] = $row;
            }

            foreach ($rowsByRegister as $registerSlug => $rows) {
                $register  = $this->ensureRegister(slug: $registerSlug, application: $rows[0]['application']);
                $schemaIds = $register->getSchemas();
                $changed   = false;

                foreach ($rows as $row) {
                    $schema = $this->ensureSchema(row: $row);
                    if (in_array($schema->getId(), $schemaIds, false) === false) {
                        $schemaIds[] = $schema->getId();
                        $changed     = true;
                    }

                    $output->info(sprintf('App schema "%s" (id %s) ready', $row['schema'], (string) $schema->getId()));
                }

                if ($changed === true) {
                    $register->setSchemas($schemaIds);
                    $this->registerMapper->update($register);
                }

                $output->info(sprintf('App virtual register "%s" seeded', $registerSlug));
            }//end foreach
        } catch (Throwable $e) {
            $this->logger->warning('[SeedAppVirtualSchemas] seed failed: '.$e->getMessage());
            $output->warning('App virtual schema seed skipped: '.$e->getMessage());
        }//end try
    }//end run()

    /**
     * Find or create one app-gated virtual register.
     *
     * @param string $slug        The register slug (e.g. `contacts`).
     * @param string $application The register application (drives the ADR-048 gate).
     *
     * @return Register The existing or newly created register.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
     */
    private function ensureRegister(string $slug, string $application): Register
    {
        try {
            return $this->registerMapper->find($slug, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            // Not found — create it.
            return $this->registerMapper->createFromArray(
                object: [
                    'title'       => ucfirst($slug),
                    'slug'        => $slug,
                    'description' => (self::REGISTER_DESCRIPTIONS[$slug] ?? sprintf('Read-only virtual register for Nextcloud %s.', $slug)),
                    'application' => $application,
                    'schemas'     => [],
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
    private function ensureSchema(array $row): Schema
    {
        try {
            return $this->schemaMapper->find($row['schema'], _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            // Not found — create it as a read-only object-source-backed schema.
            $properties = (self::SCHEMA_PROPERTIES[$row['schema']] ?? []);

            return $this->schemaMapper->createFromArray(
                object: [
                    'title'         => $row['schema'],
                    'slug'          => $row['schema'],
                    'description'   => sprintf('Read-only virtual schema for Nextcloud %s (%s).', $row['schema'], $row['schemaOrg']),
                    'properties'    => $properties,
                    'configuration' => [
                        'x-schema-org'                 => $row['schemaOrg'],
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
