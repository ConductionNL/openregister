<?php

/**
 * FieldRedaction — one redaction of one field on a DSAR case.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Redaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Redaction;

/**
 * The field, its redacted replacement value and the ground it is redacted under.
 *
 * @spec openspec/specs/dsar-redaction-write/spec.md
 */
final class FieldRedaction {
	/**
	 * Hold the redaction.
	 *
	 * @param string $field  The case field being redacted.
	 * @param string $after  The redacted replacement value.
	 * @param string $ground The generic ground key the redaction is applied under.
	 *
	 * @spec openspec/specs/dsar-redaction-write/spec.md
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $after,
		public readonly string $ground,
	) {
	}//end __construct()
}//end class
