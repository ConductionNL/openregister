<?php

/**
 * Writes prepared audit rows through the sealing path, never throwing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one write both RevealFlusher and SettingsChangeAuditor make.
 *
 * Both record a fact after the thing it describes has already happened, so a
 * failed write is logged at ERROR and reported as zero rows written, never
 * thrown: throwing would turn a missing audit row into a failed request.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
final class AuditRowWriter {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $mapper The trail, which owns the chain.
	 * @param LoggerInterface           $logger The logger the failure goes to.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the rows, in sealed windows of RevealFlusher::CHUNK rows.
	 *
	 * @param array<int, AuditTrail> $rows    The rows, unsealed; the mapper seals them.
	 * @param string                 $failure The error message logged when the write fails.
	 *
	 * @return int How many rows were written: all of them, or 0 when the write failed.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
	 */
	public function write(array $rows, string $failure): int {
		try {
			$this->mapper->insertAuditTrails(entries: $rows, chunkSize: RevealFlusher::CHUNK);
		} catch (Throwable $e) {
			$this->logger->error(
				message: $failure,
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'entries' => count($rows),
					'exception' => $e->getMessage(),
				]
			);
			return 0;
		}

		return count($rows);
	}//end write()
}//end class
