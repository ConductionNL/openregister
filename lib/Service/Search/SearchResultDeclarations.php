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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration;
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
	 * Schema id => the schema, or null when it could not be loaded.
	 *
	 * One load serves both declarations a result reads.
	 *
	 * @var array<int, Schema|null>
	 */
	private array $schemas = [];

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
		$schema = $this->schemaOf(schemaId: $schemaId);
		if ($schema === null) {
			return null;
		}

		return SearchResultDeclaration::of(schema: $schema);
	}//end forSchema()

	/**
	 * The reference card one schema declares, or null.
	 *
	 * The smart picker offers objects through this search, so a result reads
	 * the same card a pasted link renders: a schema that declares a card but
	 * no search result shows the card's title and summary in the picker.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return ReferenceCardDeclaration|null The card.
	 *
	 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
	 */
	public function cardFor(int $schemaId): ?ReferenceCardDeclaration {
		$schema = $this->schemaOf(schemaId: $schemaId);
		if ($schema === null) {
			return null;
		}

		return ReferenceCardDeclaration::of(schema: $schema);
	}//end cardFor()

	/**
	 * The schema, loaded once, or null when it is not there or cannot be loaded.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaOf(int $schemaId): ?Schema {
		if ($schemaId <= 0) {
			return null;
		}

		if (array_key_exists($schemaId, $this->schemas) === false) {
			$this->schemas[$schemaId] = $this->load(schemaId: $schemaId);
		}

		return $this->schemas[$schemaId];
	}//end schemaOf()

	/**
	 * Load a schema.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return Schema|null The schema.
	 */
	private function load(int $schemaId): ?Schema {
		try {
			return $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[SearchResultDeclarations] Search result declaration not resolved',
				context: ['schemaId' => $schemaId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end load()
}//end class
