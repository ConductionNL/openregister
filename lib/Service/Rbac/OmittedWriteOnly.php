<?php

/**
 * The stored object and the write-only paths an incoming payload left out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * What a save carries forward: the RAW stored object (never a rendered one) and
 * the declared write-only paths absent from the incoming payload.
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
 */
final class OmittedWriteOnly {

	/**
	 * Constructor.
	 *
	 * @param array              $stored The RAW stored object, from ObjectEntity::getObject() or a `_render: false` read.
	 * @param array<int, string> $paths  Declared write-only paths absent from the incoming payload.
	 */
	public function __construct(
		public readonly array $stored,
		public readonly array $paths,
	) {
	}//end __construct()
}//end class
