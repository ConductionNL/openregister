<?php

/**
 * OpenRegister e-Depot Transfer Attempt
 *
 * One delivery attempt of a transfer list: its number and its transport.
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
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;

/**
 * One delivery attempt, and its record in the list's append-only `attempts[]`.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
final class TransferAttempt {

	/**
	 * Constructor.
	 *
	 * @param int                $number    The 1-based attempt number.
	 * @param TransportInterface $transport The transport the attempt sends with.
	 */
	public function __construct(
		public readonly int $number,
		public readonly TransportInterface $transport,
	) {
	}//end __construct()

	/**
	 * The transport's name.
	 *
	 * @return string The name.
	 */
	public function transportName(): string {
		return $this->transport->getName();
	}//end transportName()

	/**
	 * Append this attempt to the list's append-only `attempts[]`.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 * @param string              $outcome      The resulting status.
	 * @param string|null         $error        The error message, when any.
	 *
	 * @return array<string,mixed> The transfer list with the appended attempt.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Requirement: Append-only attempt records)
	 */
	public function appendTo(array $transferList, string $outcome, ?string $error): array {
		$attempts = ($transferList['attempts'] ?? []);
		if (is_array($attempts) === false) {
			$attempts = [];
		}

		$attempts[] = [
			'attempt' => $this->number,
			'timestamp' => (new DateTime())->format('c'),
			'transport' => $this->transportName(),
			'outcome' => $outcome,
			'error' => $error,
		];

		$transferList['attempts'] = $attempts;

		return $transferList;
	}//end appendTo()
}//end class
