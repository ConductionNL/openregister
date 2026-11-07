<?php

/**
 * OpenRegister CodedOptionQuery
 *
 * What one call of {@see CodedOptionsBuilder} asks for: the declaration, the
 * scheme's concepts it reads, the context value in play, the label language
 * and the instant windows are judged at. Carried as one value so each step of
 * building an option takes it instead of six loose arguments.
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

use DateTimeInterface;

/**
 * The inputs of one option-list or option-tree build.
 */
final class CodedOptionQuery {

	/**
	 * Constructor.
	 *
	 * @param CodedPropertyDeclaration          $declaration   The property's declaration.
	 * @param array<string,array<string,mixed>> $conceptsByUri The scheme's concepts, keyed by uri.
	 * @param string|null                       $context       The context value in play, when the subset is bound.
	 * @param string                            $language      The BCP-47 tag to read labels in.
	 * @param DateTimeInterface                 $asOf          The instant to judge windows at.
	 */
	public function __construct(
		public readonly CodedPropertyDeclaration $declaration,
		public readonly array $conceptsByUri,
		public readonly ?string $context,
		public readonly string $language,
		public readonly DateTimeInterface $asOf,
	) {

	}//end __construct()

	/**
	 * The decoded data of one concept, or an empty map when it is unknown.
	 *
	 * @param string $uri The concept's uri.
	 *
	 * @return array<string,mixed> The concept.
	 */
	public function concept(string $uri): array {
		return ($this->conceptsByUri[$uri] ?? []);
	}//end concept()

	/**
	 * The declaration's branch root, when it names one that exists in the scheme.
	 *
	 * @return string|null The branch root's uri, or null when unbound or unknown.
	 */
	public function existingBranch(): ?string {
		$branch = $this->declaration->branch;
		if ($branch !== null && isset($this->conceptsByUri[$branch]) === true) {
			return $branch;
		}

		return null;
	}//end existingBranch()
}//end class
