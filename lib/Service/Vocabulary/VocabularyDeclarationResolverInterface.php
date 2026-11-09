<?php

/**
 * VocabularyDeclarationResolverInterface: the part of VocabularyDeclarationResolver that the vocabulary controller uses.
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

/**
 * The methods of VocabularyDeclarationResolver that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface VocabularyDeclarationResolverInterface {

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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function resolveDeclaration(string $schemaRef, string $property, string $schemeUri): ?CodedPropertyDeclaration;
}//end interface
