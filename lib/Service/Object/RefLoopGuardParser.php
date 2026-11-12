<?php

/**
 * Puts a RefLoopGuard on every schema node with a reference.
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
use Opis\JsonSchema\KeywordValidator;
use Opis\JsonSchema\Parsers\DefaultVocabulary;
use Opis\JsonSchema\Parsers\KeywordValidatorParser;
use Opis\JsonSchema\Parsers\SchemaParser;

/**
 * Opis parser step that guards the nodes holding its keyword.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class RefLoopGuardParser extends KeywordValidatorParser {
	/**
	 * A schema parser with this keyword and the other reference keywords guarded.
	 *
	 * @return SchemaParser The parser.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function schemaParser(): SchemaParser {
		$guards = [$this, new self(keyword: '$recursiveRef'), new self(keyword: '$dynamicRef')];

		return new SchemaParser(extraVocabulary: new DefaultVocabulary(keywordValidators: $guards));
	}//end schemaParser()

	/**
	 * A guard for a node that holds the keyword.
	 *
	 * @param SchemaInfo   $info   The schema node.
	 * @param SchemaParser $parser The running parser.
	 * @param object       $shared The parser's shared state.
	 *
	 * @return KeywordValidator|null The guard, or null when the node has no such reference.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is fixed by KeywordValidatorParser
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function parse(SchemaInfo $info, SchemaParser $parser, object $shared): ?KeywordValidator {
		if ($this->keywordExists(schema: $info) === false) {
			return null;
		}

		return new RefLoopGuard();
	}//end parse()
}//end class
