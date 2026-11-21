<?php

/**
 * PropertySearchProfileInterface: how a property wants to be searched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The search profile of a schema property: its match type, its input control and
 * whether it takes part in the free-text scan.
 *
 * The Search component owns the rules; the object store, the facet discovery and the
 * schema property validator read them through this contract.
 */
interface PropertySearchProfileInterface {

	/**
	 * The term has to equal the value.
	 */
	public const MATCH_EXACT = 'exact';

	/**
	 * The value has to start with the term.
	 */
	public const MATCH_PREFIX = 'prefix';

	/**
	 * The field is filtered by a lower and an upper bound, not by a term.
	 */
	public const MATCH_RANGE = 'range';

	/**
	 * Near enough counts, through pg_trgm similarity.
	 */
	public const MATCH_FUZZY = 'fuzzy';

	/**
	 * The term may appear anywhere in the value. The historical behaviour.
	 */
	public const MATCH_FULLTEXT = 'fulltext';

	/**
	 * Every match type a property may declare.
	 *
	 * @var string[]
	 */
	public const MATCH_TYPES = [
		self::MATCH_EXACT,
		self::MATCH_PREFIX,
		self::MATCH_RANGE,
		self::MATCH_FUZZY,
		self::MATCH_FULLTEXT,
	];

	/**
	 * Every input control a property may ask a list surface to render.
	 *
	 * @var string[]
	 */
	public const INPUT_CONTROLS = [
		'text',
		'select',
		'multiselect',
		'range',
		'date-range',
		'boolean',
	];

	/**
	 * What a property declares about being searched: its match type, input control and whether the type was declared.
	 *
	 * @param array<string, mixed> $property The property definition.
	 *
	 * @return array{matchType: string, inputControl: string, declared: bool}
	 */
	public function declaredBy(array $property): array;

	/**
	 * The match type to apply to this property.
	 *
	 * @param array $property The property definition.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @return string One of the MATCH_* constants.
	 */
	public static function matchTypeFor(array $property): string;

	/**
	 * Whether this property takes part in the free-text `_search` scan.
	 *
	 * @param array $property The property definition.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @return bool True when the free-text scan should read this column.
	 */
	public static function participatesInFreeText(array $property): bool;
}//end interface
