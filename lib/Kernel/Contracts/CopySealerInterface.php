<?php

/**
 * CopySealerInterface: encrypts one copy with an export key the caller never sees.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * One export key, held by the implementation, that turns a plaintext copy into an encrypted set.
 *
 * The key itself never leaves the implementation: a caller gets the
 * fingerprint, which is what a record of the copy names, and the sealed
 * bytes.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */
interface CopySealerInterface {

	/**
	 * The fingerprint of the export key this sealer encrypts with.
	 *
	 * @return string The first 16 hex characters of the key's BLAKE2b hash.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public function fingerprint(): string;

	/**
	 * Encrypt one plaintext copy as an encrypted set.
	 *
	 * @param string $plain The copy in the clear.
	 *
	 * @return string The encrypted set: header, stream header and authenticated chunks.
	 *
	 * @throws \RuntimeException When the set cannot be written.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public function seal(string $plain): string;
}//end interface
