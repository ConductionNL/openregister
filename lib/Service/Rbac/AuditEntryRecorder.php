<?php

/**
 * Hands audit entries to the trail, never throwing.
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

use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\PlacedAuditEntry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The write RevealFlusher and SettingsChangeAuditor make, through the audit trail's port.
 *
 * Both record a fact after the thing it describes has already happened, so a
 * failed write is logged at ERROR and reported as zero entries written, never
 * thrown: throwing would turn a missing audit entry into a failed request.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
final class AuditEntryRecorder {

	/**
	 * Constructor.
	 *
	 * @param AuditEntryWriterInterface $trail  The audit trail, which seals what it stores.
	 * @param LoggerInterface           $logger The logger the failure goes to.
	 */
	public function __construct(
		private readonly AuditEntryWriterInterface $trail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the entries, in order.
	 *
	 * @param list<AuditEntry|PlacedAuditEntry> $entries The entries.
	 * @param string                            $failure The error message logged when the write fails.
	 *
	 * @return int How many entries were written: all of them, or 0 when the write failed.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
	 */
	public function record(array $entries, string $failure): int {
		$written = count($entries);
		try {
			$this->trail->writeAuditEntries(entries: $entries);
		} catch (Throwable $error) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'entries' => $written, 'exception' => $error->getMessage()];
			$this->logger->error(message: $failure, context: $context);
			$written = 0;
		}

		return $written;
	}//end record()
}//end class
