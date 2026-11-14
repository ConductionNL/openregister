<?php

/**
 * Holds uploads off a register while its documents move to another store.
 *
 * The move sets a flag per register in app config before it copies anything
 * and clears it when the register is done. Every upload path asks this guard
 * first; a read never does.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\IAppConfig;

/**
 * The per-register move flag.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
class DocumentStoreMoveGuard {

	/**
	 * The app config key of a register's flag is this prefix plus its id.
	 */
	public const KEY_PREFIX = 'document_store_moving_';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the flags.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(private readonly IAppConfig $appConfig) {
	}//end __construct()

	/**
	 * Hold uploads off a register.
	 *
	 * @param int $registerId The register about to move.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function begin(int $registerId): void {
		$this->appConfig->setValueString('openregister', self::KEY_PREFIX . $registerId, '1');
	}//end begin()

	/**
	 * Let uploads into a register through again.
	 *
	 * @param int $registerId The register that finished moving.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function end(int $registerId): void {
		$this->appConfig->deleteKey('openregister', self::KEY_PREFIX . $registerId);
	}//end end()

	/**
	 * Whether a register is being moved.
	 *
	 * @param int|string|null $registerId The register id, as an object holds it.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function isMoving(int|string|null $registerId): bool {
		$id = self::idOf(registerId: $registerId);
		if ($id === null) {
			return false;
		}

		return $this->appConfig->getValueString('openregister', self::KEY_PREFIX . $id, '') === '1';
	}//end isMoving()

	/**
	 * Refuse a write into a register being moved.
	 *
	 * @param int|string|null $registerId The register id, as an object holds it.
	 *
	 * @return void
	 *
	 * @throws DocumentStoreMoving When the register is being moved.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function assertNotMoving(int|string|null $registerId): void {
		if ($this->isMoving(registerId: $registerId) === true) {
			throw new DocumentStoreMoving(registerId: (int)self::idOf(registerId: $registerId));
		}
	}//end assertNotMoving()

	/**
	 * A register id as an integer, or null when it is not one.
	 *
	 * @param int|string|null $registerId The id.
	 *
	 * @return int|null
	 */
	private static function idOf(int|string|null $registerId): ?int {
		if (is_int($registerId) === true) {
			return $registerId;
		}

		if (is_string($registerId) === true && ctype_digit($registerId) === true) {
			return (int)$registerId;
		}

		return null;
	}//end idOf()
}//end class
