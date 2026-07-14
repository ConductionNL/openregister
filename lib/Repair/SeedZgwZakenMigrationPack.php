<?php

/**
 * SeedZgwZakenMigrationPack — seeds the built-in `zgw-zaken-json` reference
 * migration pack.
 *
 * A worked example (not a ready-to-run pack) demonstrating the migration
 * mapping pack DSL against the ZGW ("Zaakgericht Werken") Zaken API export
 * shape (identificatie, omschrijving, startdatum, einddatum, status,
 * zaaktype url, vertrouwelijkheidaanduiding) — a Dutch-municipal-standard
 * JSON shape, not a proprietary vendor format. It is deliberately marked as
 * a template in its `description` and its `zaaktype`-lookup transform ships
 * with only a placeholder map entry and no `default`, so every row
 * referencing an unmapped zaaktype URL fails loudly (the literal-leak guard)
 * until the operator supplies their own catalogue mapping — see
 * `openspec/changes/migration-mapping-packs/design.md` for the full
 * pack-authoring rationale, including why Decos/Centric-format packs are
 * NOT shipped here (their export shapes could not be verified against a
 * real system).
 *
 * Idempotent: skipped when a pack with this slug already exists (an admin
 * may have customised or deleted the seeded row).
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
 * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\MigrationPackService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the built-in `zgw-zaken-json` reference migration pack.
 *
 * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
 */
class SeedZgwZakenMigrationPack implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param MigrationPackService $migrationPackService Migration pack business logic.
     * @param LoggerInterface      $logger               Logger for seed diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly MigrationPackService $migrationPackService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string The step name.
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    public function getName(): string
    {
        return 'Seed OpenRegister built-in reference migration pack (zgw-zaken-json)';
    }//end getName()

    /**
     * Run the repair step, seeding the pack when absent.
     *
     * Never throws: a seed failure logs a warning and leaves the instance
     * otherwise healthy.
     *
     * @param IOutput $output Output interface for status messages.
     *
     * @return void
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    public function run(IOutput $output): void
    {
        try {
            $this->migrationPackService->findByPackSlug(packSlug: 'zgw-zaken-json');
            $output->info('Migration pack "zgw-zaken-json" already present, skipping seed');
            return;
        } catch (Throwable $e) {
            // Not found (or lookup failed for another reason) — attempt to seed below.
        }

        try {
            $this->migrationPackService->create(definition: $this->definition(), ownerUid: null, builtin: true);
            $output->info('Seeded built-in migration pack "zgw-zaken-json"');
        } catch (Throwable $e) {
            $this->logger->warning('[SeedZgwZakenMigrationPack] seed failed: '.$e->getMessage());
            $output->warning('Migration pack seed skipped: '.$e->getMessage());
        }
    }//end run()

    /**
     * The built-in `zgw-zaken-json` pack definition.
     *
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'id'            => 'zgw-zaken-json',
            'name'          => 'ZGW Zaken (JSON) — template',
            'description'   => 'TEMPLATE, not a ready-to-run pack. Maps a ZGW ("Zaakgericht Werken") Zaken API '
                .'export (JSON) onto a generic case-like target schema. Before running a real import: (1) select or '
                .'create a target schema whose properties match the mapping targets below (caseNumber, title, '
                .'startDate, endDate, status, zaakTypeCode, confidentialityLevel), or edit the targets to match your '
                .'own schema; (2) replace the placeholder zaaktype-URL lookup map with your own catalogue\'s zaaktype '
                .'URL to code mapping — rows whose zaaktype URL is not in the map will fail import by design (the '
                .'mapping engine never passes an unresolved reference through as a literal value).',
            'sourceFormat'  => 'json',
            'version'       => '1.0.0',
            'idStrategy'    => ['type' => 'generate'],
            'fieldMappings' => [
                [
                    'source'    => '/identificatie',
                    'target'    => 'caseNumber',
                    'required'  => true,
                    'transform' => ['type' => 'trim'],
                ],
                [
                    'source'    => '/omschrijving',
                    'target'    => 'title',
                    'required'  => true,
                    'transform' => ['type' => 'trim'],
                ],
                [
                    'source'    => '/startdatum',
                    'target'    => 'startDate',
                    'required'  => false,
                    'transform' => ['type' => 'date', 'sourceFormat' => 'Y-m-d', 'targetFormat' => 'Y-m-d'],
                ],
                [
                    'source'    => '/einddatum',
                    'target'    => 'endDate',
                    'required'  => false,
                    'transform' => ['type' => 'date', 'sourceFormat' => 'Y-m-d', 'targetFormat' => 'Y-m-d'],
                ],
                [
                    'source'    => '/status',
                    'target'    => 'status',
                    'required'  => false,
                    'transform' => ['type' => 'trim'],
                ],
                [
                    'source'    => '/zaaktype',
                    'target'    => 'zaakTypeCode',
                    'required'  => false,
                    'transform' => [
                        'type' => 'lookup',
                        'map'  => [
                            'https://example.com/catalogi/api/v1/zaaktypen/00000000-0000-0000-0000-000000000000' => 'VOORBEELD',
                        ],
                    ],
                ],
                [
                    'source'    => '/vertrouwelijkheidaanduiding',
                    'target'    => 'confidentialityLevel',
                    'required'  => false,
                    'transform' => ['type' => 'trim'],
                ],
            ],
            'defaults'      => [],
            'skipRows'      => [],
        ];
    }//end definition()
}//end class
