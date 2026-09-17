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
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IRequest;
use Throwable;

/**
 * Resolves the coded declaration a vocabulary-options read is about.
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */
class VocabularyDeclarationResolver {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Reads the schema a coded property is declared on.
	 * @param CodedPropertyDeclarationFactory $declarationFactory Builds a declaration off a schema property.
	 * @param IRequest $request Current request (the unsaved path reads its knobs off the query).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly CodedPropertyDeclarationFactory $declarationFactory,
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
	 * @param string $schemaRef The schema id or slug, empty when none was given.
	 * @param string $property The property name, empty when none was given.
	 * @param string $schemeUri The scheme uri for the unsaved path, empty when none was given.
	 *
	 * @return CodedPropertyDeclaration|null The declaration, or null when neither source yields one.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function resolveDeclaration(string $schemaRef, string $property, string $schemeUri): ?CodedPropertyDeclaration {
		if ($schemaRef !== '' && $property !== '') {
			try {
				$schema = $this->schemaMapper->find(id: $schemaRef);
			} catch (Throwable $missing) {
				return null;
			}

			$properties = ($schema->getProperties() ?? []);
			$declaration = $this->declarationFactory->fromProperty(property: ($properties[$property] ?? null));
			if ($declaration !== null) {
				return $declaration;
			}
		}

		if ($schemeUri === '') {
			return null;
		}

		return $this->declarationFactory->fromProperty(
			property: [
				CodedPropertyDeclaration::ANNOTATION => $this->declarationFromQuery(scheme: $schemeUri),
			]
		);
	}//end resolveDeclaration()

	/**
	 * Build a declaration from the query, for a property that is not saved yet.
	 *
	 * @param string $scheme The scheme's uri.
	 *
	 * @return array<string,mixed> The declaration.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
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
