<?php

/**
 * A newly created export key, as its creator sees it once.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

/**
 * The credential id, the fingerprint and the recovery string of a new export key.
 *
 * The recovery string is the key itself, base64url-encoded: it is shown once
 * and never stored anywhere but the credential broker.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */
final class ExportKeyCreated {
	/**
	 * Constructor.
	 *
	 * @param string $keyId       The credential UUID of the key.
	 * @param string $fingerprint The key's fingerprint.
	 * @param string $recovery    The key as a base64url recovery string.
	 */
	public function __construct(
		public readonly string $keyId,
		public readonly string $fingerprint,
		#[\SensitiveParameter] public readonly string $recovery,
	) {
	}//end __construct()

	/**
	 * The key as var_dump() and print_r() show it, with the recovery string masked.
	 *
	 * @return array<string, string> The fields, recovery replaced by a marker.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function __debugInfo(): array {
		return ['keyId' => $this->keyId, 'fingerprint' => $this->fingerprint, 'recovery' => '[redacted]'];
	}//end __debugInfo()
}//end class
