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
use DateTimeInterface;

/**
 * Produces the options and the option tree for a coded property.
 */
class CodedOptionsBuilder {

	/**
	 * Constructor.
	 *
	 * @param ConceptRepository $concepts Reads the scheme's concepts.
	 * @param ConceptHierarchy $hierarchy Walks broader and narrower.
	 * @param ConceptLifecycle $lifecycle Answers the validity window.
	 */
	public function __construct(
		private readonly ConceptRepository $concepts,
		private readonly ConceptHierarchy $hierarchy,
		private readonly ConceptLifecycle $lifecycle,
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
	 * @param string $language The BCP-47 tag to read labels in.
	 * @param string|null $context The context value in play, when the subset is bound.
	 * @param DateTimeInterface|null $asOf The instant to judge windows at; now when null.
	 *
	 * @return array<int,array<string,mixed>> The options.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function options(
		CodedPropertyDeclaration $declaration,
		string $language = 'nl',
		?string $context = null,
		?DateTimeInterface $asOf = null,
	): array {
		$query = $this->query(declaration: $declaration, scope: [$language, $context, $asOf]);
		if ($query === null) {
			return [];
		}

		return $this->optionsFor(query: $query);
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
	 * @param string $language The BCP-47 tag to read labels in.
	 * @param DateTimeInterface|null $asOf The instant to judge windows at; now when null.
	 *
	 * @return array<int,array<string,mixed>> The roots of the tree.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function tree(
		CodedPropertyDeclaration $declaration,
		string $language = 'nl',
		?DateTimeInterface $asOf = null,
	): array {
		$query = $this->query(declaration: $declaration, scope: [$language, null, $asOf]);
		if ($query === null) {
			return [];
		}

		return array_map(
			fn (string $uri): array => $this->subtree(query: $query, uri: $uri),
			$this->treeRootUris(query: $query)
		);
	}//end tree()

	/**
	 * Every offerable option of the query, in candidate order.
	 *
	 * @param CodedOptionQuery $query The call's inputs.
	 *
	 * @return array<int,array<string,mixed>> The options.
	 */
	private function optionsFor(CodedOptionQuery $query): array {
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
		return $this->hierarchy->tree(
			rootUri: $uri,
			conceptsByUri: $query->conceptsByUri,
			reading: $query->reading,
			maxDepth: $query->declaration->maxDepth
		);
	}//end subtree()

	/**
	 * Read the declaration's scheme and bundle it with the call's inputs.
	 *
	 * @param CodedPropertyDeclaration                                $declaration The property's declaration.
	 * @param array{0: string, 1: string|null, 2: DateTimeInterface|null} $scope   Language, context and instant (now when null).
	 *
	 * @return CodedOptionQuery|null The query, or null when the scheme holds no concepts.
	 */
	private function query(CodedPropertyDeclaration $declaration, array $scope): ?CodedOptionQuery {
		$instant = ($scope[2] ?? new DateTimeImmutable());
		$conceptsByUri = $this->concepts->conceptsOf(schemeUri: $declaration->scheme);
		if ($conceptsByUri === []) {
			return null;
		}

		return new CodedOptionQuery(
			declaration: $declaration,
			conceptsByUri: $conceptsByUri,
			context: $scope[1],
			reading: new ConceptReading(language: $scope[0], instant: $instant)
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

		return $this->hierarchy->branchUris(
			rootUri: $branch,
			conceptsByUri: $query->conceptsByUri,
			maxDepth: $query->declaration->maxDepth
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
		if ($this->lifecycle->isOfferable(concept: $concept, at: $query->reading->instant, allowDeprecated: $declaration->allowDeprecated) === false) {
			return false;
		}

		if ($declaration->matchesContext(concept: $concept, context: $query->context) === false) {
			return false;
		}

		return $declaration->leafOnly === false || $this->hierarchy->isLeaf(uri: $uri, conceptsByUri: $query->conceptsByUri) === true;
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
			'label' => $this->hierarchy->labelOf(concept: $concept, language: $query->reading->language),
			'notation' => ($concept['notation'] ?? null),
			'weight' => $this->lifecycle->weightOf(concept: $concept),
			'leaf' => $this->hierarchy->isLeaf(uri: $uri, conceptsByUri: $query->conceptsByUri),
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
