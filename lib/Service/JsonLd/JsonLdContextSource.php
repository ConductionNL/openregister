<?php

/**
 * OpenRegister JSON-LD context source
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\JsonLd
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

namespace OCA\OpenRegister\Service\JsonLd;

use OCA\OpenRegister\Db\Schema;

/**
 * What one schema context document is built from: the schema, its JSON-LD
 * configuration and the URL the context is served at. The head and every
 * property term of {@see JsonLdContextService::buildSchemaContext()} read all
 * three, so they travel as one.
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
final readonly class JsonLdContextSource {

	/**
	 * Bundle the source.
	 *
	 * @param Schema               $schema     The schema.
	 * @param array<string, mixed> $jsonld     The schema's JSON-LD configuration.
	 * @param string               $contextUrl The schema's context URL.
	 */
	public function __construct(
		public Schema $schema,
		public array $jsonld,
		public string $contextUrl,
	) {
	}//end __construct()
}//end class
