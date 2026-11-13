<?php
/**
 * OpenRegister search result declarations, per schema.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Looks up and caches the search result each schema declares.
 *
 * A schema that cannot be loaded reads as declaring none, so its results are
 * built the way they were before any schema could declare one.
 *
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
 */
class SearchResultDeclarations {

	/**
	 * Schema id => its declaration, or null when it declares none.
	 *
	 * @var array<int, SearchResultDeclaration|null>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper Loads the schema.
	 * @param LoggerInterface $logger       Logger.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The declaration of one schema, or null.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return SearchResultDeclaration|null The declaration.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
	 */
	public function forSchema(int $schemaId): ?SearchResultDeclaration {
		if ($schemaId <= 0) {
			return null;
		}

		if (array_key_exists($schemaId, $this->cache) === false) {
			$this->cache[$schemaId] = $this->load(schemaId: $schemaId);
		}

		return $this->cache[$schemaId];
	}//end forSchema()

	/**
	 * Load a schema's declaration.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return SearchResultDeclaration|null The declaration.
	 */
	private function load(int $schemaId): ?SearchResultDeclaration {
		try {
			return SearchResultDeclaration::of(
				schema: $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false)
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[SearchResultDeclarations] Search result declaration not resolved',
				context: ['schemaId' => $schemaId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end load()
}//end class
