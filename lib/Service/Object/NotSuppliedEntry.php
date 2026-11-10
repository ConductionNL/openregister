<?php

/**
 * OpenRegister not-supplied entry
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

/**
 * One entry of a body's not-supplied record: the property, its reason and the body it came with.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class NotSuppliedEntry {

	/**
	 * Constructor.
	 *
	 * @param string $property The property marked not supplied.
	 * @param mixed  $reason   The reason code it was marked with, as sent.
	 * @param array  $object   The candidate object body.
	 */
	public function __construct(
		public readonly string $property,
		public readonly mixed $reason,
		public readonly array $object,
	) {
	}//end __construct()
}//end class
