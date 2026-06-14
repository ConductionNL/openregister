<?php

/**
 * OpenRegister Schema Import — pluggable dialect importer interface.
 *
 * Each external standard (Schema.org, GGM, and future DCAT/SKOS/ZGW dialects)
 * is implemented as a `SchemaDialectImporter`. Implementations are registered
 * with the {@see SchemaImportService} so additional dialects are follow-up
 * changes, not refactors. The interface is deliberately free of Nextcloud
 * dependencies so concrete importers stay pure and unit-testable.
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
 * Contract for importing a register schema from an external standard.
 *
 * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
 */
interface SchemaDialectImporter
{
    /**
     * The dialect identifier this importer handles (e.g. `schema.org`, `ggm`).
     *
     * @return string The dialect key.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function dialect(): string;

    /**
     * Search the bundled snapshot for importable types/objecttypes.
     *
     * @param string $query A name/term query; empty returns a bounded sample.
     *
     * @return array<int, array<string, mixed>> Candidates: id, label, description, parent (where applicable), snapshotVersion.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function discover(string $query): array;

    /**
     * Resolve and map an external type reference into a register schema.
     *
     * @param string        $reference The type reference (IRI, bare name, or objecttype id).
     * @param ImportOptions $options   Import options (subset, ancestors, target register).
     *
     * @return ImportedSchema The mapped schema + configuration fragments.
     *
     * @throws SchemaImportException When the reference is unknown to the snapshot.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function import(string $reference, ImportOptions $options): ImportedSchema;

    /**
     * The bundled snapshot version this importer reads from.
     *
     * @return string The snapshot/release version identifier.
     *
     * @spec openspec/changes/schema-import-standards/specs/schema-import/spec.md
     */
    public function snapshotVersion(): string;
}//end interface
