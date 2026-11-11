<?php

/**
 * OpenRegister e-Depot Transfer Context
 *
 * What every confirmed object of one delivered attempt is recorded with.
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
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;

/**
 * The transfer, package format, transport and moment one delivery was confirmed.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */
final class TransferContext {

	/**
	 * Constructor.
	 *
	 * @param mixed  $transferUuid  The transfer list UUID, as the list holds it.
	 * @param string $packageFormat The SIP format used (zip|bagit).
	 * @param string $transportName The transport used.
	 * @param string $confirmedAt   The confirmation timestamp.
	 */
	public function __construct(
		public readonly mixed $transferUuid,
		public readonly string $packageFormat,
		public readonly string $transportName,
		public readonly string $confirmedAt,
	) {
	}//end __construct()

	/**
	 * The context of a delivery that has just come back.
	 *
	 * @param array<string,mixed> $transferList  The transfer list.
	 * @param string              $transportName The transport used.
	 *
	 * @return self The context, dated now.
	 */
	public static function now(array $transferList, string $transportName): self {
		return new self(
			transferUuid: ($transferList['uuid'] ?? null),
			packageFormat: self::formatOf(transferList: $transferList),
			transportName: $transportName,
			confirmedAt: (new DateTime())->format('c')
		);
	}//end now()

	/**
	 * The SIP format a list asks for (zip default | bagit).
	 *
	 * @param array<string,mixed> $transferList The transfer list.
	 *
	 * @return string The format.
	 */
	public static function formatOf(array $transferList): string {
		return (string)($transferList['packageFormat'] ?? 'zip');
	}//end formatOf()
}//end class
