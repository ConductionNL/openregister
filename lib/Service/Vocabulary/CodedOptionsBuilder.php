<?php

/**
 * OpenRegister CodedOptionsBuilder
 *
 * Builds the option set a form renders for a coded property: a tree when the
 * property names a branch, a flat list otherwise, narrowed by the context in
 * play and by each value's validity window.
 *
 * The one behaviour worth naming: a retired value is ABSENT from the options
 * and still resolvable on the record that holds it. Those two facts are
 * produced here and in {@see ConceptRepository} respectively, and keeping
 * them apart is what lets a 2019 dossier read correctly while the picker
 * beside it no longer offers the value (design.md D-1).
 *
 * The context subset is the answer to "one field, a different list per case
 * type" (design.md D-4). One `categorie` property bound to the value of
 * `zaaktype` serves every case type, instead of forty near-identical fields
 * that drift apart the first time one of them is edited.
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

use DateTimeImmutable;

/**
 * Produces the options and the option tree for a coded property.
 */
class CodedOptionsBuilder implements CodedOptionsBuilderInterface {

	/**
	 * Constructor.
	 *
	 * @param ConceptSchemeAccess $scheme Reads the scheme's concepts, hierarchy and validity windows.
	 */
	public function __construct(
		private readonly ConceptSchemeAccess $scheme,
	) {

	}//end __construct()

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
	public function options(CodedPropertyDeclaration $declaration, CodedOptionScope $scope=new CodedOptionScope()): array {
		return $this->optionsFor(query: $this->query(declaration: $declaration, scope: $scope));
	}//end options()

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
	public function tree(CodedPropertyDeclaration $declaration, CodedOptionScope $scope=new CodedOptionScope()): array {
		return $this->treeFor(query: $this->query(declaration: $declaration, scope: new CodedOptionScope(language: $scope->language, asOf: $scope->asOf)));
	}//end tree()

	/**
	 * The option tree of the query, one root per tree root uri.
	 *
	 * @param CodedOptionQuery|null $query The call's inputs, or null when the scheme holds no concepts.
	 *
	 * @return array<int,array<string,mixed>> The roots of the tree.
	 */
	private function treeFor(?CodedOptionQuery $query): array {
		if ($query === null) {
			return [];
		}

		return array_map(
			fn (string $uri): array => $this->subtree(query: $query, uri: $uri),
			$this->treeRootUris(query: $query)
		);
	}//end treeFor()

	/**
	 * Every offerable option of the query, in candidate order.
	 *
	 * @param CodedOptionQuery|null $query The call's inputs, or null when the scheme holds no concepts.
	 *
	 * @return array<int,array<string,mixed>> The options.
	 */
	private function optionsFor(?CodedOptionQuery $query): array {
		if ($query === null) {
			return [];
		}

		$options = array_map(
			fn ($uri): ?array => $this->optionFor(query: $query, uri: (string) $uri),
			$this->candidateUris(query: $query)
		);

		return array_values(array_filter($options, static fn (?array $option): bool => $option !== null));
	}//end optionsFor()

	/**
	 * The option tree under one root.
	 *
	 * @param CodedOptionQuery $query The call's inputs.
	 * @param string           $uri   The root's uri.
	 *
	 * @return array<string,mixed> The root's tree node.
	 */
	private function subtree(CodedOptionQuery $query, string $uri): array {
		return $this->scheme->hierarchy->tree(
			branch: new ConceptBranch(rootUri: $uri, conceptsByUri: $query->conceptsByUri, maxDepth: $query->declaration->maxDepth),
			reading: $query->reading
		);
	}//end subtree()

	/**
	 * Read the declaration's scheme and bundle it with the call's inputs.
	 *
	 * @param CodedPropertyDeclaration $declaration The property's declaration.
	 * @param CodedOptionScope         $scope       Language, context and instant (now when null).
	 *
	 * @return CodedOptionQuery|null The query, or null when the scheme holds no concepts.
	 */
	private function query(CodedPropertyDeclaration $declaration, CodedOptionScope $scope): ?CodedOptionQuery {
		$instant = ($scope->asOf ?? new DateTimeImmutable());
		$conceptsByUri = $this->scheme->concepts->conceptsOf(schemeUri: $declaration->scheme);
		if ($conceptsByUri === []) {
			return null;
		}

		return new CodedOptionQuery(
			declaration: $declaration,
			conceptsByUri: $conceptsByUri,
			context: $scope->context,
			reading: new ConceptReading(language: $scope->language, instant: $instant)
		);
	}//end query()

	/**
	 * The uris a flat option list is drawn from: the bound branch, else the whole scheme.
	 *
	 * @param CodedOptionQuery $query The call's inputs.
	 *
	 * @return array<int,int|string> The candidate uris.
	 */
	private function candidateUris(CodedOptionQuery $query): array {
		$branch = $query->existingBranch();
		if ($branch === null) {
			return array_keys($query->conceptsByUri);
		}

		return $this->scheme->hierarchy->branchUris(
			branch: new ConceptBranch(rootUri: $branch, conceptsByUri: $query->conceptsByUri, maxDepth: $query->declaration->maxDepth)
		);
	}//end candidateUris()

	/**
	 * The roots an option tree is drawn from: the bound branch, else the scheme's top concepts.
	 *
	 * @param CodedOptionQuery $query The call's inputs.
	 *
	 * @return array<int,string> The root uris.
	 */
	private function treeRootUris(CodedOptionQuery $query): array {
		$branch = $query->existingBranch();
		if ($branch === null) {
			return $this->rootUris(conceptsByUri: $query->conceptsByUri);
		}

		return [$branch];
	}//end treeRootUris()

	/**
	 * The option for one candidate uri, or null when it is not offerable.
	 *
	 * The per-candidate filtering lives here so {@see options()} stays a plain
	 * gather-loop: a value outside its window, outside the context, off a
	 * leaf-only branch, or without the notation it is stored by returns null
	 * and is left out.
	 *
	 * @param CodedOptionQuery $query The call's inputs.
	 * @param string           $uri   The candidate concept uri.
	 *
	 * @return array<string,mixed>|null The option, or null to skip this uri.
	 */
	private function optionFor(CodedOptionQuery $query, string $uri): ?array {
		$value = $this->storedValue(query: $query, uri: $uri);
		if ($value === null || $this->isOffered(query: $query, uri: $uri) === false) {
			return null;
		}

		return ['value' => $value] + $this->optionFields(query: $query, uri: $uri);
	}//end optionFor()

	/**
	 * Whether a candidate is in its window, in the context and, when required, a leaf.
	 *
	 * @param CodedOptionQuery $query The call's inputs.
	 * @param string           $uri   The candidate concept uri.
	 *
	 * @return boolean True when the candidate may be offered.
	 */
	private function isOffered(CodedOptionQuery $query, string $uri): bool {
		$declaration = $query->declaration;
		$concept = $query->concept(uri: $uri);
		$lifecycle = $this->scheme->lifecycle;
		$offerable = ($declaration->allowDeprecated === true)
			? $lifecycle->isWithinWindow(concept: $concept, at: $query->reading->instant)
			: $lifecycle->isOfferable(concept: $concept, at: $query->reading->instant);
		if ($offerable === false) {
			return false;
		}

		if ($declaration->matchesContext(concept: $concept, context: $query->context) === false) {
			return false;
		}

		return $declaration->leafOnly === false || $this->scheme->hierarchy->isLeaf(uri: $uri, conceptsByUri: $query->conceptsByUri) === true;
	}//end isOffered()

	/**
	 * The value an option stores: the uri, or the trimmed notation.
	 *
	 * @param CodedOptionQuery $query The call's inputs.
	 * @param string           $uri   The candidate concept uri.
	 *
	 * @return string|null The stored value, or null when stored by a notation the concept lacks.
	 */
	private function storedValue(CodedOptionQuery $query, string $uri): ?string {
		if ($query->declaration->store !== 'notation') {
			return $uri;
		}

		$notation = trim((string) ($query->concept(uri: $uri)['notation'] ?? ''));
		if ($notation === '') {
			return null;
		}

		return $notation;
	}//end storedValue()

	/**
	 * Everything an option shows besides its stored value.
	 *
	 * @param CodedOptionQuery $query The call's inputs.
	 * @param string           $uri   The candidate concept uri.
	 *
	 * @return array<string,mixed> The uri, label, notation, weight, leaf flag and extra fields.
	 */
	private function optionFields(CodedOptionQuery $query, string $uri): array {
		$concept = $query->concept(uri: $uri);

		return [
			'uri' => $uri,
			'label' => $this->scheme->hierarchy->labelOf(concept: $concept, language: $query->reading->language),
			'notation' => ($concept['notation'] ?? null),
			'weight' => $this->scheme->lifecycle->weightOf(concept: $concept),
			'leaf' => $this->scheme->hierarchy->isLeaf(uri: $uri, conceptsByUri: $query->conceptsByUri),
			'fields' => ($concept[ConceptLifecycle::FIELD_FIELDS] ?? null),
		];
	}//end optionFields()

	/**
	 * The uris of the concepts nothing in the scheme is broader of.
	 *
	 * @param array<string,array<string,mixed>> $conceptsByUri The scheme's concepts.
	 *
	 * @return array<int,string> The top-level uris.
	 */
	private function rootUris(array $conceptsByUri): array {
		$roots = [];
		foreach ($conceptsByUri as $uri => $concept) {
			if ($this->namesBroader(broader: ($concept['broader'] ?? null)) === false) {
				$roots[] = (string) $uri;
			}
		}

		return $roots;
	}//end rootUris()

	/**
	 * Whether a `broader` value names at least one parent.
	 *
	 * @param mixed $broader The raw `broader` value.
	 *
	 * @return boolean True for a non-empty string or a non-empty list.
	 */
	private function namesBroader(mixed $broader): bool {
		if (is_string($broader) === true) {
			return $broader !== '';
		}

		return is_array($broader) === true && $broader !== [];
	}//end namesBroader()
}//end class
