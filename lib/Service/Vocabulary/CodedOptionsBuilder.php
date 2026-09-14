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
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
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
	 * @param DateTimeInterface|null $at The instant to judge windows at; now when null.
	 *
	 * @return array<int,array<string,mixed>> The options.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function options(
		CodedPropertyDeclaration $declaration,
		string $language = 'nl',
		?string $context = null,
		?DateTimeInterface $at = null,
	): array {
		$instant = ($at ?? new DateTimeImmutable());
		$conceptsByUri = $this->concepts->conceptsOf(schemeUri: $declaration->scheme);
		if ($conceptsByUri === []) {
			return [];
		}

		$candidates = array_keys($conceptsByUri);
		if ($declaration->branch !== null && isset($conceptsByUri[$declaration->branch]) === true) {
			$candidates = $this->hierarchy->branchUris(
				rootUri: $declaration->branch,
				conceptsByUri: $conceptsByUri,
				maxDepth: $declaration->maxDepth
			);
		}

		$options = [];
		foreach ($candidates as $uri) {
			$concept = ($conceptsByUri[$uri] ?? []);

			if ($this->lifecycle->isOfferable(
				concept: $concept,
				at: $instant,
				allowDeprecated: $declaration->allowDeprecated
			) === false
			) {
				continue;
			}

			if ($declaration->matchesContext(concept: $concept, context: $context) === false) {
				continue;
			}

			$leaf = $this->hierarchy->isLeaf(uri: $uri, conceptsByUri: $conceptsByUri);
			if ($declaration->leafOnly === true && $leaf === false) {
				continue;
			}

			$value = $uri;
			if ($declaration->store === 'notation') {
				$value = trim((string)($concept['notation'] ?? ''));
				if ($value === '') {
					continue;
				}
			}

			$options[] = [
				'value' => $value,
				'uri' => $uri,
				'label' => $this->hierarchy->labelOf(concept: $concept, language: $language),
				'notation' => ($concept['notation'] ?? null),
				'weight' => $this->lifecycle->weightOf(concept: $concept),
				'leaf' => $leaf,
				'fields' => ($concept[ConceptLifecycle::FIELD_FIELDS] ?? null),
			];
		}//end foreach

		return $options;
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
	 * @param DateTimeInterface|null $at The instant to judge windows at; now when null.
	 *
	 * @return array<int,array<string,mixed>> The roots of the tree.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function tree(
		CodedPropertyDeclaration $declaration,
		string $language = 'nl',
		?DateTimeInterface $at = null,
	): array {
		$instant = ($at ?? new DateTimeImmutable());
		$conceptsByUri = $this->concepts->conceptsOf(schemeUri: $declaration->scheme);
		if ($conceptsByUri === []) {
			return [];
		}

		if ($declaration->branch !== null && isset($conceptsByUri[$declaration->branch]) === true) {
			return [
				$this->hierarchy->tree(
					rootUri: $declaration->branch,
					conceptsByUri: $conceptsByUri,
					language: $language,
					at: $instant,
					maxDepth: $declaration->maxDepth
				),
			];
		}

		$roots = [];
		foreach ($this->rootUris(conceptsByUri: $conceptsByUri) as $uri) {
			$roots[] = $this->hierarchy->tree(
				rootUri: $uri,
				conceptsByUri: $conceptsByUri,
				language: $language,
				at: $instant,
				maxDepth: $declaration->maxDepth
			);
		}

		return $roots;
	}//end tree()

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
			$broader = ($concept['broader'] ?? null);
			if (is_string($broader) === true && $broader !== '') {
				continue;
			}

			if (is_array($broader) === true && $broader !== []) {
				continue;
			}

			$roots[] = (string)$uri;
		}

		return $roots;
	}//end rootUris()
}//end class
