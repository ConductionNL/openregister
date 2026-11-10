<?php

/**
 * OpenRegister property conversion change
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The type change a caller asked to preview for one property of a schema.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class PropertyConversionChange {
	/**
	 * Constructor.
	 *
	 * @param int    $schemaId The schema id.
	 * @param string $property The property.
	 * @param string $from     Its current type.
	 * @param string $to       The requested type.
	 */
	public function __construct(
		public readonly int $schemaId,
		public readonly string $property,
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()

	/**
	 * The change as the API echoes it.
	 *
	 * @return array{property: string, from: string, to: string}
	 */
	public function toArray(): array {
		return ['property' => $this->property, 'from' => $this->from, 'to' => $this->to];
	}//end toArray()
}//end class
