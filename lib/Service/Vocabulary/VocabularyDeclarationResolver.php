<?php

/**
 * OpenRegister VocabularyDeclarationResolver
 *
 * Resolves the coded {@see CodedPropertyDeclaration} a vocabulary-options read
 * is about, whether that property is already saved on a schema or is a scheme
 * named on its own in the query. Extracted from
 * {@see \OCA\OpenRegister\Controller\VocabularyController} so the controller
 * keeps only its endpoint wiring and this cohesive resolution logic lives on
 * its own injected collaborator (NC autowires it).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\Schema;
use OCP\IRequest;
use Throwable;

/**
 * Resolves the coded declaration a vocabulary-options read is about.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
class VocabularyDeclarationResolver implements VocabularyDeclarationResolverInterface {

	/**
	 * Constructor.
	 *
	 * @param CodedSchemaTables $tables  Reads the schema a coded property is declared on and builds its declaration.
	 * @param IRequest          $request Current request (the unsaved path reads its knobs off the query).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CodedSchemaTables $tables,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The coded declaration this read is about, saved or not yet saved.
	 *
	 * Two sources, in order. A saved property is read off its schema. A scheme
	 * named on its own is read off the query instead, because the schema editor
	 * has to show the hierarchy of a scheme the property is not yet bound to:
	 * the person choosing a branch is choosing it FROM that tree, so they need
	 * it before the save rather than after.
	 *
	 * @param DeclarationLookup $lookup The schema and property of a saved declaration, and the scheme uri for the unsaved path.
	 *
	 * @return CodedPropertyDeclaration|null The declaration, or null when neither source yields one.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function resolveDeclaration(DeclarationLookup $lookup): ?CodedPropertyDeclaration {
		if ($lookup->schemaRef === '' || $lookup->property === '') {
			return $this->unsavedDeclaration(schemeUri: $lookup->schemeUri);
		}

		$schema = $this->findSchema(schemaRef: $lookup->schemaRef);
		if ($schema === null) {
			return null;
		}

		return ($this->savedDeclaration(schema: $schema, property: $lookup->property) ?? $this->unsavedDeclaration(schemeUri: $lookup->schemeUri));
	}//end resolveDeclaration()

	/**
	 * The schema a reference names, or null when it cannot be read.
	 *
	 * @param string $schemaRef The schema id or slug.
	 *
	 * @return Schema|null The schema.
	 */
	private function findSchema(string $schemaRef): ?Schema {
		try {
			return $this->tables->schemas->find(id: $schemaRef);
		} catch (Throwable $missing) {
			return null;
		}
	}//end findSchema()

	/**
	 * The declaration a saved schema property carries, or null when it is not coded.
	 *
	 * @param Schema $schema   The schema the property is declared on.
	 * @param string $property The property name.
	 *
	 * @return CodedPropertyDeclaration|null The declaration.
	 */
	private function savedDeclaration(Schema $schema, string $property): ?CodedPropertyDeclaration {
		$properties = ($schema->getProperties() ?? []);

		return $this->tables->declarations->fromProperty(property: ($properties[$property] ?? null));
	}//end savedDeclaration()

	/**
	 * The declaration of a scheme named on the query, or null when none is named.
	 *
	 * @param string $schemeUri The scheme uri, empty when none was given.
	 *
	 * @return CodedPropertyDeclaration|null The declaration.
	 */
	private function unsavedDeclaration(string $schemeUri): ?CodedPropertyDeclaration {
		if ($schemeUri === '') {
			return null;
		}

		return $this->tables->declarations->fromProperty(
			property: [
				CodedPropertyDeclaration::ANNOTATION => $this->declarationFromQuery(scheme: $schemeUri),
			]
		);
	}//end unsavedDeclaration()

	/**
	 * Build a declaration from the query, for a property that is not saved yet.
	 *
	 * @param string $scheme The scheme's uri.
	 *
	 * @return array<string,mixed> The declaration.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	private function declarationFromQuery(string $scheme): array {
		$declaration = ['scheme' => $scheme];

		$branch = trim((string)$this->request->getParam('branch', ''));
		if ($branch !== '') {
			$declaration['branch'] = $branch;
		}

		$store = trim((string)$this->request->getParam('store', ''));
		if ($store !== '') {
			$declaration['store'] = $store;
		}

		$maxDepth = $this->request->getParam('maxDepth', null);
		if (is_numeric($maxDepth) === true) {
			$declaration['maxDepth'] = (int)$maxDepth;
		}

		$declaration['leafOnly'] = filter_var(
			$this->request->getParam('leafOnly', false),
			FILTER_VALIDATE_BOOLEAN
		);
		$declaration['allowDeprecated'] = filter_var(
			$this->request->getParam('allowDeprecated', false),
			FILTER_VALIDATE_BOOLEAN
		);

		return $declaration;
	}//end declarationFromQuery()
}//end class
