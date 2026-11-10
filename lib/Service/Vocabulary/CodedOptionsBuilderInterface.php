<?php

/**
 * CodedOptionsBuilderInterface: the part of CodedOptionsBuilder that the vocabulary controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * The methods of CodedOptionsBuilder that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface CodedOptionsBuilderInterface {

	/**
	 * The options for one declaration, as a flat list.
	 *
	 * Each option is `{value, label, notation, weight, leaf, parents}`. A value
	 * outside its window is not in the list at all, which is the point: the
	 * picker stops offering a retired resultaattype the day it is retired.
	 *
	 * @param CodedPropertyDeclaration $declaration The property's declaration.
	 * @param CodedOptionScope         $scope       The label language, the context value in play and the instant to judge windows at.
	 *
	 * @return array<int,array<string,mixed>> The options.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function options(CodedPropertyDeclaration $declaration, CodedOptionScope $scope=new CodedOptionScope()): array;

	/**
	 * The options for one declaration, as a tree.
	 *
	 * A property bound to a branch returns that branch's tree. A property
	 * bound to none returns the scheme's top concepts, each with its own
	 * subtree, so a Woo categorie tree renders as a tree without the schema
	 * having to name a root.
	 *
	 * @param CodedPropertyDeclaration $declaration The property's declaration.
	 * @param CodedOptionScope         $scope       The label language and the instant to judge windows at; its context is not read.
	 *
	 * @return array<int,array<string,mixed>> The roots of the tree.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function tree(CodedPropertyDeclaration $declaration, CodedOptionScope $scope=new CodedOptionScope()): array;
}//end interface
