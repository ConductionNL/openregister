<?php

/**
 * The one reading of "a scalar as a non-empty string" the send-email classes share.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Reads loosely typed config and event values as trimmed strings.
 *
 * FlowRecipientAddresses reads recipient entries with it and FlowEmailAnnouncer
 * reads the sent message with it, so "empty" means the same in both.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md
 */
final class FlowScalarText {

	/**
	 * A scalar as a non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The trimmed string, or null when empty or not scalar.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md
	 */
	public static function orNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		return $value;

	}//end orNull()
}//end class
