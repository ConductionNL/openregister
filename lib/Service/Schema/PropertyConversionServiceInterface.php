<?php

/**
 * The supported property type conversions and their preview, as the schema migration API asks for them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * The supported property type conversions and their preview, as the schema migration API asks for them.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see PropertyConversionService} in ContractBindings.
 */
interface PropertyConversionServiceInterface {

	/**
	 * The published conversion matrix.
	 *
	 * @return array<int,array{from:string,to:array<int,string>}> The supported conversions.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function supported(): array;

	/**
	 * Whether one conversion is on the published list.
	 *
	 * A conversion to the same type is trivially supported and converts every
	 * value, which keeps a no-op request from being refused as unsupported.
	 *
	 * @param string $from The current type.
	 * @param string $to The requested type.
	 *
	 * @return boolean True when the conversion may be attempted.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function isSupported(string $from, string $to): bool;

	/**
	 * The reason an unsupported conversion is refused.
	 *
	 * @param string $from The current type.
	 * @param string $to The requested type.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function refusalReason(string $from, string $to): string;

	/**
	 * Preview a conversion over a set of stored values.
	 *
	 * @param array<int,mixed> $values The stored values, one per object.
	 * @param string $from The current type.
	 * @param string $to The requested type.
	 *
	 * @return array{supported:bool,total:int,convertible:int,rejected:int,samples:array<int,array{value:string,reason:string}>,reason:string|null}
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function preview(array $values, string $from, string $to): array;
}//end interface
