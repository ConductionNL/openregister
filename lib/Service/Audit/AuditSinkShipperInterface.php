<?php

/**
 * AuditSinkShipperInterface: what the audit trail mapper uses of AuditSink to ship entries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;

/**
 * Shipping audit entries to the configured sink, and recording a gap when one does not ship.
 *
 * Declares only what AuditTrailMapper uses; ContractBindings binds it to
 * AuditSink, so it resolves to the same shared instance.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
interface AuditSinkShipperInterface {

	/**
	 * The audit action a failure to ship is recorded under.
	 *
	 * @var string
	 */
	public const ACTION_SINK_FAILED = 'audit.sink-failed';

	/**
	 * Whether a sink path is configured.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function isConfigured(): bool;

	/**
	 * Ship a batch of entries.
	 *
	 * @param AuditTrail[] $entries The rows to ship.
	 *
	 * @return int How many entries did not reach the file.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function shipMany(array $entries): int;

	/**
	 * Tell the sink how to record a gap on the trail.
	 *
	 * @param callable $recorder Receives the unshipped entry and the error.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function setFailureRecorder(callable $recorder): void;
}//end interface
