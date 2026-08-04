<?php

/**
 * OpenRegister Schema Import — SchemaImportService.
 *
 * The orchestration seam between the pure dialect importers and the REST
 * surface. Owns the dialect registry (so DCAT/SKOS/ZGW importers are follow-up
 * registrations, not refactors), constructs the bundled-snapshot importers,
 * resolves the dialect of an uploaded document, runs discovery/import, and
 * drives the guarded update-from-source three-way merge against the stored
 * import baseline.
 *
 * Snapshot construction and the merge are pure; this class adds no DB access of
 * its own (persistence stays in the controller's SchemaMapper plumbing), so it
 * is unit-testable with bundled or fixture snapshots.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SchemaImport;

use OCA\OpenRegister\Exception\SchemaImportException;

/**
 * Registers dialect importers and orchestrates standards imports.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
class SchemaImportService
{

    /**
     * Registered importers keyed by dialect.
     *
     * @var array<string, SchemaDialectImporter>
     */
    private array $importers = [];

    /**
     * Constructor.
     *
     * Builds the bundled Schema.org and GGM importers over the committed,
     * versioned snapshot resources and registers them. The resource root is
     * injectable so unit tests can point at fixtures.
     *
     * @param DialectDetector $detector     The dialect detector.
     * @param ThreeWayMerge   $merge        The pure three-way merge.
     * @param string|null     $resourceRoot The lib/Resources directory, or null for the bundled default.
     */
    public function __construct(
        private readonly DialectDetector $detector,
        private readonly ThreeWayMerge $merge,
        ?string $resourceRoot=null
    ) {
        $root = ($resourceRoot ?? dirname(__DIR__, 2).'/Resources');

        $schemaOrgVersion = $this->readVersion(versionFile: $root.'/schemaorg/version.json', fallback: 'schemaorg-unknown');
        $this->register(
            importer: new SchemaOrgImporter(
                new SchemaOrgSnapshot($root.'/schemaorg/schemaorg-current-https.jsonld', $schemaOrgVersion)
            )
        );

        $ggmVersion = $this->readVersion(versionFile: $root.'/ggm/version.json', fallback: 'ggm-unknown');
        $this->register(
            importer: new GgmImporter(
                new GgmSnapshot($root.'/ggm/ggm-snapshot.json', $ggmVersion)
            )
        );
    }//end __construct()

    /**
     * Register a dialect importer (pluggability seam).
     *
     * @param SchemaDialectImporter $importer The importer to register.
     *
     * @return void
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function register(SchemaDialectImporter $importer): void
    {
        $this->importers[$importer->dialect()] = $importer;
    }//end register()

    /**
     * The registered dialect keys for standards import.
     *
     * @return array<int, string> The dialect keys.
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function importableDialects(): array
    {
        return array_keys($this->importers);
    }//end importableDialects()

    /**
     * Resolve a registered importer by dialect, or fail with 404.
     *
     * @param string $dialect The dialect key.
     *
     * @return SchemaDialectImporter The importer.
     *
     * @throws SchemaImportException When no importer handles the dialect.
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function importerFor(string $dialect): SchemaDialectImporter
    {
        if (isset($this->importers[$dialect]) === false) {
            throw new SchemaImportException(
                message: sprintf('No importer registered for dialect "%s". Available: %s.', $dialect, implode(', ', $this->importableDialects())),
                httpStatus: 404
            );
        }

        return $this->importers[$dialect];
    }//end importerFor()

    /**
     * Discover importable types/objecttypes for a dialect.
     *
     * @param string $dialect The dialect key.
     * @param string $query   The search term.
     *
     * @return array<string, mixed> { snapshotVersion: string, results: array }
     *
     * @throws SchemaImportException When the dialect is unknown.
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function discover(string $dialect, string $query): array
    {
        $importer = $this->importerFor(dialect: $dialect);
        return [
            'dialect'         => $dialect,
            'snapshotVersion' => $importer->snapshotVersion(),
            'results'         => $importer->discover($query),
        ];
    }//end discover()

    /**
     * The bundled snapshot version metadata for a dialect.
     *
     * @param string $dialect The dialect key.
     *
     * @return array<string, mixed> { dialect, snapshotVersion }
     *
     * @throws SchemaImportException When the dialect is unknown.
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function snapshotInfo(string $dialect): array
    {
        $importer = $this->importerFor(dialect: $dialect);
        return [
            'dialect'         => $dialect,
            'snapshotVersion' => $importer->snapshotVersion(),
        ];
    }//end snapshotInfo()

    /**
     * Import a type/objecttype from a dialect's snapshot.
     *
     * @param string        $dialect   The dialect key.
     * @param string        $reference The type reference.
     * @param ImportOptions $options   Import options.
     *
     * @return ImportedSchema The mapped schema.
     *
     * @throws SchemaImportException When the dialect or reference is unknown.
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function import(string $dialect, string $reference, ImportOptions $options): ImportedSchema
    {
        return $this->importerFor(dialect: $dialect)->import($reference, $options);
    }//end import()

    /**
     * Import a GGM objecttype from an uploaded export (normalised intermediate).
     *
     * @param array<string, mixed> $normalised  The normalised GGM intermediate.
     * @param string               $reference   The objecttype id/name to import.
     * @param ImportOptions        $options     Import options.
     * @param string               $sourceLabel A provenance label for the upload.
     *
     * @return ImportedSchema The mapped schema.
     *
     * @throws SchemaImportException When the objecttype is unknown to the upload.
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function importGgmUpload(array $normalised, string $reference, ImportOptions $options, string $sourceLabel='upload'): ImportedSchema
    {
        $importer = new GgmImporter(GgmSnapshot::fromNormalised($normalised), $sourceLabel);
        return $importer->import($reference, $options);
    }//end importGgmUpload()

    /**
     * Detect the dialect of a decoded upload document.
     *
     * @param array<string, mixed> $document The decoded document.
     *
     * @return string|null The detected dialect, or null when undetectable.
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function detectDialect(array $document): ?string
    {
        return $this->detector->detect($document);
    }//end detectDialect()

    /**
     * Resolve the effective dialect for an upload: explicit wins, else detect,
     * else fail with 422 listing the supported dialects.
     *
     * @param array<string, mixed> $document        The decoded document.
     * @param string|null          $explicitDialect The explicit dialect parameter, or null.
     *
     * @return string The resolved dialect.
     *
     * @throws SchemaImportException When the dialect cannot be resolved (422).
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function resolveUploadDialect(array $document, ?string $explicitDialect): string
    {
        if ($explicitDialect !== null && $explicitDialect !== '') {
            if (in_array($explicitDialect, DialectDetector::supportedDialects(), true) === false) {
                throw SchemaImportException::undetectableDialect(DialectDetector::supportedDialects());
            }

            return $explicitDialect;
        }

        $detected = $this->detector->detect($document);
        if ($detected === null) {
            throw SchemaImportException::undetectableDialect(DialectDetector::supportedDialects());
        }

        return $detected;
    }//end resolveUploadDialect()

    /**
     * Compute the classified update-from-source diff for an imported schema.
     *
     * @param array<string, mixed>               $importSource      The stored configuration.importSource block (with baseline).
     * @param array<string, array<string,mixed>> $currentProperties The schema's current properties.
     * @param array<int, string>                 $resolvedConflicts Conflict property names confirmed by the caller.
     *
     * @return array<string, mixed> The merge result (added/removed/changed/keptLocal/conflicts/merged/applied).
     *
     * @throws SchemaImportException When the schema has no recorded import source.
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public function previewUpdateFromSource(array $importSource, array $currentProperties, array $resolvedConflicts=[]): array
    {
        $dialect   = (string) ($importSource['dialect'] ?? '');
        $reference = (string) ($importSource['reference'] ?? '');
        if ($dialect === '' || $reference === '') {
            throw new SchemaImportException(message: 'Schema has no recorded import source to update from.', httpStatus: 422);
        }

        $baseline = ($importSource['baseline'] ?? []);
        if (is_array($baseline) === false) {
            $baseline = [];
        }

        $fresh    = $this->import(dialect: $dialect, reference: $reference, options: new ImportOptions());
        $incoming = $fresh->properties;

        $result = $this->merge->compute($baseline, $currentProperties, $incoming, $resolvedConflicts);
        $result['incomingSource'] = $fresh->importSource;
        $result['jsonld']         = $fresh->jsonld;

        return $result;
    }//end previewUpdateFromSource()

    /**
     * Read a snapshot version from its version.json, with a fallback.
     *
     * @param string $versionFile The version.json path.
     * @param string $fallback    The fallback version when the file is unreadable.
     *
     * @return string The version identifier.
     */
    private function readVersion(string $versionFile, string $fallback): string
    {
        if (is_file($versionFile) === false) {
            return $fallback;
        }

        $decoded = json_decode((string) file_get_contents($versionFile), associative: true);
        if (is_array($decoded) === true && isset($decoded['version']) === true && is_string($decoded['version']) === true) {
            return $decoded['version'];
        }

        return $fallback;
    }//end readVersion()
}//end class
