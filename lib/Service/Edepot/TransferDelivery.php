<?php

/**
 * OpenRegister e-Depot Transfer Delivery
 *
 * What came back from sending one attempt's packages: the transport results,
 * the objects that were sent, and the delivery they were confirmed in.
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

use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;

/**
 * The outcome of one delivery, as {@see TransferResultProcessor::process()} records it.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */
final class TransferDelivery {

	/**
	 * Constructor.
	 *
	 * @param array<int,TransportResult>     $results          One transport result per package.
	 * @param array<int,array<string,mixed>> $objectsWithFiles The objects that were sent.
	 * @param TransferContext                $context          The delivery the results came back from.
	 */
	public function __construct(
		public readonly array $results,
		public readonly array $objectsWithFiles,
		public readonly TransferContext $context,
	) {
	}//end __construct()
}//end class
