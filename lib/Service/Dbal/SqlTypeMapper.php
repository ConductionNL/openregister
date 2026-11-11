<?php

/**
 * SqlTypeMapper — maps a DBAL column type onto a JSON-Schema property fragment.
 *
 * Keying on the dialect-normalised DBAL `Types::*` name (rather than a raw vendor
 * type string) lets one table cover MySQL, PostgreSQL and SQLite (design D5). The
 * mapping produces `type`, an optional `format`, and — where the column carries
 * them — `maxLength` and precision/scale hints in the property `description`.
 * Unknown/vendor-specific types fall back to `string` with a logged warning and
 * are flagged non-filterable (design D7).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * Translates DBAL column types into JSON-Schema property fragments.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
class SqlTypeMapper {
	/**
	 * Base map from DBAL type name → [json type, json format|null].
	 *
	 * @var array<string, array{0: string, 1: string|null}>
	 */
	private const TYPE_MAP = [
		Types::STRING => ['string', null],
		Types::ASCII_STRING => ['string', null],
		Types::TEXT => ['string', null],
		Types::GUID => ['string', 'uuid'],
		Types::INTEGER => ['integer', null],
		Types::SMALLINT => ['integer', null],
		Types::BIGINT => ['integer', null],
		Types::DECIMAL => ['number', null],
		Types::FLOAT => ['number', null],
		Types::BOOLEAN => ['boolean', null],
		Types::DATE_MUTABLE => ['string', 'date'],
		Types::DATE_IMMUTABLE => ['string', 'date'],
		Types::TIME_MUTABLE => ['string', 'time'],
		Types::TIME_IMMUTABLE => ['string', 'time'],
		Types::DATETIME_MUTABLE => ['string', 'date-time'],
		Types::DATETIME_IMMUTABLE => ['string', 'date-time'],
		Types::DATETIMETZ_MUTABLE => ['string', 'date-time'],
		Types::DATETIMETZ_IMMUTABLE => ['string', 'date-time'],
		Types::JSON => ['object', null],
		Types::BINARY => ['string', null],
		Types::BLOB => ['string', null],
		Types::SIMPLE_ARRAY => ['array', null],
		Types::ARRAY => ['array', null],
	];

	/**
	 * DBAL type names that must not be used in a SQL WHERE predicate.
	 *
	 * @var array<int, string>
	 */
	private const NON_FILTERABLE_TYPES = [
		Types::BINARY,
		Types::BLOB,
		Types::JSON,
		Types::SIMPLE_ARRAY,
		Types::ARRAY,
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for unknown-type fallback warnings.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Map a DBAL column onto a JSON-Schema property fragment.
	 *
	 * @param Column $column The introspected column.
	 *
	 * @return array<string, mixed> The property fragment (`type`, optional
	 *                              `format`/`maxLength`/`description`, and an
	 *                              internal `x-filterable` flag).
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function mapColumn(Column $column): array {
		$typeName = $this->typeName(column: $column);

		if (isset(self::TYPE_MAP[$typeName]) === false) {
			return $this->unknownTypeFallback(column: $column, typeName: $typeName);
		}

		$property = array_merge(
			$this->typedProperty(typeName: $typeName),
			$this->maxLengthFacet(column: $column, typeName: $typeName),
			$this->typeDescription(column: $column, typeName: $typeName)
		);
		$property['x-filterable'] = (in_array($typeName, self::NON_FILTERABLE_TYPES, true) === false);

		return $property;
	}//end mapColumn()

	/**
	 * The fragment for a column of an unmapped type: a non-filterable string, with a warning.
	 *
	 * @param Column $column   The introspected column.
	 * @param string $typeName Its DBAL type name, not in TYPE_MAP.
	 *
	 * @return array<string, mixed> The fallback property fragment.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function unknownTypeFallback(Column $column, string $typeName): array {
		$this->logger->warning(
			sprintf(
				'[SqlTypeMapper] unknown column type "%s" on column "%s" — falling back to string (non-filterable)',
				$typeName,
				$column->getName()
			)
		);

		return [
			'type' => 'string',
			'x-filterable' => false,
		];
	}//end unknownTypeFallback()

	/**
	 * The JSON-Schema type of a mapped DBAL type, with its format when it has one.
	 *
	 * @param string $typeName A DBAL type name in TYPE_MAP.
	 *
	 * @return array<string, string> The `type` and optional `format`.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function typedProperty(string $typeName): array {
		[$jsonType, $format] = self::TYPE_MAP[$typeName];

		$property = ['type' => $jsonType];
		if ($format !== null) {
			$property['format'] = $format;
		}

		return $property;
	}//end typedProperty()

	/**
	 * Map the column length onto maxLength — only for length-carrying
	 * string types (VARCHAR-family); TEXT is unbounded per design D5.
	 *
	 * @param Column $column   The introspected column.
	 * @param string $typeName Its DBAL type name.
	 *
	 * @return array<string, int> The `maxLength`, or empty.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function maxLengthFacet(Column $column, string $typeName): array {
		$length = $column->getLength();
		if (in_array($typeName, [Types::STRING, Types::ASCII_STRING], true) === true && $length !== null && $length > 0) {
			return ['maxLength' => (int)$length];
		}

		return [];
	}//end maxLengthFacet()

	/**
	 * The description a type carries: DECIMAL precision/scale (not a JSON-Schema
	 * keyword), or the JS-precision note of a BIGINT (carried as an integer).
	 *
	 * @param Column $column   The introspected column.
	 * @param string $typeName Its DBAL type name.
	 *
	 * @return array<string, string> The `description`, or empty.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function typeDescription(Column $column, string $typeName): array {
		if ($typeName === Types::DECIMAL && $column->getPrecision() !== null) {
			return ['description' => sprintf('DECIMAL(%d,%d)', (int)$column->getPrecision(), (int)$column->getScale())];
		}

		if ($typeName === Types::BIGINT) {
			return ['description' => 'BIGINT — values beyond 2^53 are precision-lossy in JSON/JS; ids are carried as strings.'];
		}

		return [];
	}//end typeDescription()

	/**
	 * Resolve the dialect-normalised DBAL type name for a column.
	 *
	 * @param Column $column The introspected column.
	 *
	 * @return string The registered DBAL type name (e.g. `string`, `integer`).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Type::lookupName is DBAL's only
	 *   reverse lookup from a Type instance to its registered name.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function typeName(Column $column): string {
		return Type::lookupName($column->getType());
	}//end typeName()
}//end class
