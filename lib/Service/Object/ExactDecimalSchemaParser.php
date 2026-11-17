<?php

/**
 * Opis schema parser whose drafts check multipleOf on exact decimals.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Opis\JsonSchema\Parsers\Drafts\Draft06;
use Opis\JsonSchema\Parsers\Drafts\Draft07;
use Opis\JsonSchema\Parsers\Drafts\Draft201909;
use Opis\JsonSchema\Parsers\Drafts\Draft202012;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Parsers\Vocabulary;

/**
 * The opis schema parser with ExactMultipleOfKeywordParser in every draft.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class ExactDecimalSchemaParser extends SchemaParser {
	/**
	 * The four drafts opis supports, each with the exact multipleOf parser.
	 *
	 * @param Vocabulary|null $extraVocabulary Extra keywords for every draft.
	 *
	 * @return array<string, mixed> The drafts keyed by version.
	 */
	protected function getDrafts(?Vocabulary $extraVocabulary): array {
		return [
			'06' => new class($extraVocabulary) extends Draft06 {
				/**
				 * The draft's keyword parsers with the exact multipleOf parser.
				 *
				 * @return array<int, mixed> The keyword parsers.
				 */
				protected function getKeywordParsers(): array {
					return ExactMultipleOfKeywordParser::swapInto(parsers: parent::getKeywordParsers());
				}
			},
			'07' => new class($extraVocabulary) extends Draft07 {
				/**
				 * The draft's keyword parsers with the exact multipleOf parser.
				 *
				 * @return array<int, mixed> The keyword parsers.
				 */
				protected function getKeywordParsers(): array {
					return ExactMultipleOfKeywordParser::swapInto(parsers: parent::getKeywordParsers());
				}
			},
			'2019-09' => new class($extraVocabulary) extends Draft201909 {
				/**
				 * The draft's keyword parsers with the exact multipleOf parser.
				 *
				 * @return array<int, mixed> The keyword parsers.
				 */
				protected function getKeywordParsers(): array {
					return ExactMultipleOfKeywordParser::swapInto(parsers: parent::getKeywordParsers());
				}
			},
			'2020-12' => new class($extraVocabulary) extends Draft202012 {
				/**
				 * The draft's keyword parsers with the exact multipleOf parser.
				 *
				 * @return array<int, mixed> The keyword parsers.
				 */
				protected function getKeywordParsers(): array {
					return ExactMultipleOfKeywordParser::swapInto(parsers: parent::getKeywordParsers());
				}
			},
		];
	}//end getDrafts()
}//end class
