<?php

/**
 * Writes the copies of one destruction list run, encrypted as the archival setting asks.
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

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\CopySealerInterface;
use OCA\OpenRegister\Kernel\Contracts\ExportKeySealersInterface;
use RuntimeException;

/**
 * The copy before a destruction can be required to be encrypted (design D-6).
 *
 * `destructionCopyEncryption` reads:
 * - `off` (the default): the copy is written in the clear, as REQ-IPC-004 specifies.
 * - `when-key`: encrypted with the organisation's export key when one is usable, in the clear otherwise.
 * - `required`: encrypted, or not written at all. Without a usable export key
 *   every copy fails, so by REQ-IPC-004's own rule (no copy, no destruction)
 *   nothing on the list is destroyed, and {@see refusal()} names the missing key.
 *
 * Any other value reads as `required`: a misspelt setting may stop a
 * destruction, which is reported, but never lets a copy land in the clear
 * that an administrator meant to be encrypted. Without a copy location no
 * copy is written in any mode, as before.
 *
 * The export key is looked up once per run, on the first copy that needs it,
 * so its use is audited once and a list without a copy location never asks.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */
final class DestructionCopies {

	/**
	 * The archival setting naming the mode.
	 *
	 * @var string
	 */
	public const SETTING = 'destructionCopyEncryption';

	/**
	 * Copies are written in the clear.
	 *
	 * @var string
	 */
	public const MODE_OFF = 'off';

	/**
	 * Copies are encrypted when a usable export key exists.
	 *
	 * @var string
	 */
	public const MODE_WHEN_KEY = 'when-key';

	/**
	 * Copies are encrypted, or not written, so nothing is destroyed.
	 *
	 * @var string
	 */
	public const MODE_REQUIRED = 'required';

	/**
	 * The mode this run writes under.
	 *
	 * @var string
	 */
	private readonly string $mode;

	/**
	 * The sealer, once looked up: false when the organisation has none.
	 *
	 * @var CopySealerInterface|false|null
	 */
	private CopySealerInterface|false|null $sealer = null;

	/**
	 * Why copies were refused, once one was.
	 *
	 * @var string|null
	 */
	private ?string $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $settings     The archival settings.
	 * @param DestructionCopyWriter $writer      Writes each copy.
	 * @param Closure              $sealers      Answers the ExportKeySealersInterface, resolved only when a copy is encrypted.
	 * @param string|null          $organisation The organisation the list belongs to.
	 */
	public function __construct(
		private readonly array $settings,
		private readonly DestructionCopyWriter $writer,
		private readonly Closure $sealers,
		private readonly ?string $organisation,
	) {
		$this->mode = self::mode(value: ($settings[self::SETTING] ?? null));
	}//end __construct()

	/**
	 * The mode a setting value reads as.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string One of the three modes; unset or empty reads as `off`, anything unknown as `required`.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public static function mode(mixed $value): string {
		if ($value === null || $value === '' || $value === self::MODE_OFF) {
			return self::MODE_OFF;
		}

		if ($value === self::MODE_WHEN_KEY) {
			return self::MODE_WHEN_KEY;
		}

		return self::MODE_REQUIRED;
	}//end mode()

	/**
	 * Write the copy of one object, or answer null when no copy location is configured.
	 *
	 * @param string       $listUuid The destruction list.
	 * @param ObjectEntity $object   The object about to be destroyed.
	 *
	 * @return DestructionCopy|null The copy, or null when none is written.
	 *
	 * @throws RuntimeException When the copy cannot be written, or must be encrypted and no export key is usable.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public function write(string $listUuid, ObjectEntity $object): ?DestructionCopy {
		$location = trim((string) ($this->settings['destructionCopyLocation'] ?? ''));
		if ($location === '') {
			return null;
		}

		if ($this->mode === self::MODE_OFF) {
			return new DestructionCopy(path: $this->writer->write(location: $location, listUuid: $listUuid, object: $object));
		}

		$sealer = $this->sealer();
		if ($sealer === null && $this->mode === self::MODE_WHEN_KEY) {
			return new DestructionCopy(path: $this->writer->write(location: $location, listUuid: $listUuid, object: $object));
		}

		if ($sealer === null) {
			$this->refusal = sprintf(
				'The copy before destruction must be encrypted (%s is "%s"), and organisation "%s" has no usable export key; nothing was destroyed. Create an export key for the organisation, or change the setting.',
				self::SETTING,
				self::MODE_REQUIRED,
				(string) $this->organisation
			);
			throw new RuntimeException(sprintf('The copy of object %s could not be encrypted: %s', (string) $object->getUuid(), $this->refusal));
		}

		$path = $this->writer->writeSealed(location: $location, listUuid: $listUuid, object: $object, sealer: $sealer);

		return new DestructionCopy(path: $path, keyFingerprint: $sealer->fingerprint());
	}//end write()

	/**
	 * Why copies were refused in this run, or null when none was.
	 *
	 * @return string|null The reason, naming the organisation whose export key is missing.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public function refusal(): ?string {
		return $this->refusal;
	}//end refusal()

	/**
	 * The organisation's sealer, looked up once.
	 *
	 * @return CopySealerInterface|null The sealer, or null when the list has no organisation or it has no usable key.
	 *
	 * @throws \Throwable Whatever the lookup throws; nothing is remembered then.
	 */
	private function sealer(): ?CopySealerInterface {
		// A lookup that throws leaves the sealer unknown, so the object it was
		// for is not destroyed and the next copy asks again; only an answer
		// of "no usable key" is remembered for the run.
		if ($this->sealer === null) {
			$found = null;
			$organisation = trim((string) $this->organisation);
			if ($organisation !== '') {
				$sealers = ($this->sealers)();
				if ($sealers instanceof ExportKeySealersInterface) {
					$found = $sealers->sealerFor(organisation: $organisation);
				}
			}

			$this->sealer = ($found ?? false);
		}

		if ($this->sealer === false) {
			return null;
		}

		return $this->sealer;
	}//end sealer()
}//end class
