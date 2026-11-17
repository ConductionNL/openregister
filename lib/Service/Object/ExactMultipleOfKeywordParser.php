<?php

/**
 * Parses multipleOf into an ExactMultipleOfKeyword.
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

use Opis\JsonSchema\Info\SchemaInfo;
use Opis\JsonSchema\Keyword;
use Opis\JsonSchema\Keywords\MultipleOfKeyword;
use Opis\JsonSchema\Parsers\Keywords\MultipleOfKeywordParser;
use Opis\JsonSchema\Parsers\SchemaParser;

/**
 * Opis's multipleOf parser, producing the exact keyword for a literal divisor.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class ExactMultipleOfKeywordParser extends MultipleOfKeywordParser {
	/**
	 * Parse the keyword, keeping opis's checks on the divisor.
	 *
	 * @param SchemaInfo   $info   The schema node.
	 * @param SchemaParser $parser The schema parser.
	 * @param object       $shared Shared parse state.
	 *
	 * @return Keyword|null The keyword, or null when the node has none.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function parse(SchemaInfo $info, SchemaParser $parser, object $shared): ?Keyword {
		$keyword = parent::parse(info: $info, parser: $parser, shared: $shared);
		if ($keyword === null || get_class($keyword) !== MultipleOfKeyword::class) {
			return $keyword;
		}

		return new ExactMultipleOfKeyword(divisor: $this->keywordValue(schema: $info->data()));
	}//end parse()

	/**
	 * Replace opis's multipleOf parser in a draft's keyword parser list.
	 *
	 * @param array<int, mixed> $parsers The draft's keyword parsers.
	 *
	 * @return array<int, mixed> The same list with the exact parser in its place.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public static function swapInto(array $parsers): array {
		foreach ($parsers as $index => $parser) {
			if ($parser instanceof MultipleOfKeywordParser) {
				$parsers[$index] = new self(keyword: 'multipleOf');
			}
		}

		return $parsers;
	}//end swapInto()
}//end class
