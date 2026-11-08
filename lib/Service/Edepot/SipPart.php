<?php

/**
 * OpenRegister SIP Part
 *
 * One package of a transfer: which transfer, which part of how many, which format.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * One package of a (possibly split) transfer.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */
final class SipPart {

	/**
	 * Constructor.
	 *
	 * @param string $transferId     The transfer identifier.
	 * @param int    $sequenceNumber This package's 1-based number.
	 * @param int    $totalPackages  How many packages the transfer has.
	 * @param string $format         The output format (zip|bagit).
	 */
	public function __construct(
		public readonly string $transferId,
		public readonly int $sequenceNumber,
		public readonly int $totalPackages,
		public readonly string $format,
	) {
	}//end __construct()

	/**
	 * Whether the transfer is split over more than one package.
	 *
	 * @return bool True when split.
	 */
	public function isSplit(): bool {
		return $this->totalPackages > 1;
	}//end isSplit()

	/**
	 * The archive file-name suffix: `-part{n}` for a split transfer, else ''.
	 *
	 * @return string The suffix.
	 */
	public function suffix(): string {
		if ($this->isSplit() === true) {
			return "-part{$this->sequenceNumber}";
		}

		return '';
	}//end suffix()

	/**
	 * The `sip-sequence.json` document a split transfer's packages carry.
	 *
	 * @return string The JSON.
	 */
	public function sequenceJson(): string {
		return (string)json_encode(
			[
				'transferId' => $this->transferId,
				'sequenceNumber' => $this->sequenceNumber,
				'totalPackages' => $this->totalPackages,
			],
			JSON_PRETTY_PRINT
		);
	}//end sequenceJson()
}//end class
