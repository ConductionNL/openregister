<?php

/**
 * Encrypts one copy with one export key.
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
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

use OCA\OpenRegister\Kernel\Contracts\CopySealerInterface;
use RuntimeException;

/**
 * Writes a plaintext copy as an encrypted set under an export key header.
 *
 * The key stays in this object; the caller gets the fingerprint and the
 * sealed bytes. The set is the same container an encrypted export writes
 * (design D-1), so the same reader opens it.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */
final class ExportKeySealer implements CopySealerInterface {

	/**
	 * The header naming the key's fingerprint.
	 *
	 * @var EncryptedSetHeader
	 */
	private readonly EncryptedSetHeader $header;

	/**
	 * Constructor.
	 *
	 * @param string             $key    The 32-byte export key.
	 * @param EncryptedSetWriter $writer Writes the encrypted set.
	 *
	 * @throws \InvalidArgumentException When the key is not 32 bytes.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) EncryptedSetHeader's named constructor is a pure function of the key.
	 */
	public function __construct(
		#[\SensitiveParameter] private readonly string $key,
		private readonly EncryptedSetWriter $writer,
	) {
		$this->header = EncryptedSetHeader::forKey(key: $key);
	}//end __construct()

	/**
	 * The fingerprint of the key this sealer encrypts with.
	 *
	 * @return string The fingerprint.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public function fingerprint(): string {
		return (string) $this->header->fields['keyFingerprint'];
	}//end fingerprint()

	/**
	 * Encrypt one plaintext copy as an encrypted set.
	 *
	 * @param string $plain The copy in the clear.
	 *
	 * @return string The encrypted set.
	 *
	 * @throws RuntimeException When the set cannot be written.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public function seal(string $plain): string {
		$source = fopen('php://memory', 'w+b');
		$target = fopen('php://temp', 'w+b');
		if ($source === false || $target === false) {
			throw new RuntimeException('The encrypted copy could not be written.');
		}

		try {
			fwrite($source, $plain);
			rewind($source);
			$this->writer->write(plain: $source, encrypted: $target, key: $this->key, header: $this->header);
			rewind($target);

			return (string) stream_get_contents($target);
		} finally {
			fclose($source);
			fclose($target);
		}
	}//end seal()
}//end class
