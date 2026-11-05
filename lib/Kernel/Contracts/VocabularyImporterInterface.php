<?php

/**
 * VocabularyImporterInterface: imports value lists and skos vocabularies into the vocabulary register.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Imports value lists and SKOS vocabularies into the vocabulary register. Implemented by Service\VocabularyImportService.
 */
interface VocabularyImporterInterface {

	/**
	 * Slug of the vocabulary register.
	 *
	 * @var string
	 */
	public const REGISTER = 'vocabulary';

	/**
	 * Slug of the conceptScheme schema.
	 *
	 * @var string
	 */
	public const SCHEMA_SCHEME = 'conceptScheme';

	/**
	 * Slug of the concept schema.
	 *
	 * @var string
	 */
	public const SCHEMA_CONCEPT = 'concept';

	/**
	 * Import a concept scheme + its concepts from a CSV value-list export.
	 *
	 * Expected columns: `uri` (required), `notation`, `prefLabel_<lang>` (one
	 * column per BCP-47 language, at least `prefLabel_nl`), `definition`,
	 * `broaderUri` (pipe-`|`-separated list of broader concept uris).
	 *
	 * @param string $csvPath Path to the CSV file.
	 * @param array<string,string> $schemeMeta Scheme metadata: `uri` (required),
	 *                                         `title`, `publisher`, `version`, `source`.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int}
	 *
	 * @throws \InvalidArgumentException When the file is missing/empty or scheme uri is absent.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	public function importCsvValueList(string $csvPath, array $schemeMeta): array;

	/**
	 * Import a concept scheme + its concepts from a SKOS/JSON-LD document.
	 *
	 * @param array<string,mixed> $jsonLd Decoded JSON-LD document: either a
	 *                                    `{"@graph": [...]}` envelope or a
	 *                                    single top-level node with `@id`.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int}
	 *
	 * @throws \InvalidArgumentException When the document has no ConceptScheme node.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md#requirement-idempotent-skos-import-keyed-on-uri-skos-002
	 */
	public function importJsonLd(array $jsonLd): array;
}//end interface
