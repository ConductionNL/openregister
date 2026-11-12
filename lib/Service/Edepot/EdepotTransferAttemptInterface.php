<?php

/**
 * EdepotTransferAttemptInterface: one transport attempt for a transfer list.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;

/**
 * Run one transport attempt for a transfer list; the job decides on retry.
 *
 * Bound to EdepotTransferService in ContractBindings.
 */
interface EdepotTransferAttemptInterface {
	/**
	 * Run ONE transport attempt for a transfer list.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param TransportInterface  $transport    The transport to use.
	 * @param int                 $attempt      The 1-based attempt number.
	 *
	 * @return array<string,mixed> The updated transfer list (status, attempts[], transferResult).
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 */
	public function executeAttempt(array $transferList, TransportInterface $transport, int $attempt): array;
}//end interface
