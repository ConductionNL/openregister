<?php

/**
 * One restorable copy written before a destruction.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * Where a copy lies and, when it is encrypted, the fingerprint of its key.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */
final class DestructionCopy {

	/**
	 * Constructor.
	 *
	 * @param string      $path           The written file.
	 * @param string|null $keyFingerprint The export key's fingerprint, or null for a copy in the clear.
	 */
	public function __construct(
		public readonly string $path,
		public readonly ?string $keyFingerprint = null,
	) {
	}//end __construct()

	/**
	 * What the recorded destruction says about its copy.
	 *
	 * @return array<string, string> `copy`, and `copyKeyFingerprint` for an encrypted copy.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public function auditContext(): array {
		$context = ['copy' => $this->path];
		if ($this->keyFingerprint !== null) {
			$context['copyKeyFingerprint'] = $this->keyFingerprint;
		}

		return $context;
	}//end auditContext()
}//end class
