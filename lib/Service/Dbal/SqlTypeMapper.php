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
		}

		[$jsonType, $format] = self::TYPE_MAP[$typeName];

		$property = ['type' => $jsonType];
		if ($format !== null) {
			$property['format'] = $format;
		}

		// Map the column length onto maxLength — only for length-carrying
		// string types (VARCHAR-family); TEXT is unbounded per design D5.
		$length = $column->getLength();
		if (in_array($typeName, [Types::STRING, Types::ASCII_STRING], true) === true && $length !== null && $length > 0) {
			$property['maxLength'] = (int)$length;
		}

		// Surface DECIMAL precision/scale in the description (not a JSON-Schema keyword).
		if ($typeName === Types::DECIMAL) {
			$precision = $column->getPrecision();
			$scale = $column->getScale();
			if ($precision !== null) {
				$property['description'] = sprintf('DECIMAL(%d,%d)', (int)$precision, (int)$scale);
			}
		}

		// BIGINT is carried as an integer but documented as JS-precision-lossy.
		if ($typeName === Types::BIGINT) {
			$property['description'] = 'BIGINT — values beyond 2^53 are precision-lossy in JSON/JS; ids are carried as strings.';
		}

		$property['x-filterable'] = (in_array($typeName, self::NON_FILTERABLE_TYPES, true) === false);

		return $property;
	}//end mapColumn()

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
