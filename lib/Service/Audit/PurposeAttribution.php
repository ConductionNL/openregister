<?php

/**
 * Stamps the purpose a read ran under onto the audit row it produced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Writes the accepted purpose onto a row, in both places it belongs.
 *
 * Two writes, one act, and they are not redundant:
 *
 * - `resultSummary['purpose']` is INSIDE the canonical JSON the hash chain
 *   seals, so the purpose a row was written under cannot be changed afterwards
 *   without breaking verification.
 * - the `purpose` COLUMN is outside it, and exists because "each purpose
 *   carries its own count" is a `GROUP BY` over millions of rows, which a JSON
 *   field cannot serve portably across the databases this app supports.
 *
 * The column is therefore an index over the sealed value, never a second
 * truth. {@see PurposeAttribution::disagrees()} is what makes that claim
 * checkable rather than merely stated.
 *
 * MUST be applied BEFORE the row is inserted: `resultSummary` is part of the
 * canonical form, so a purpose added after the insert would sit outside the
 * hash the row is later given.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */
class PurposeAttribution {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the request-scoped purpose context.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Stamp the accepted purpose onto a row being built.
	 *
	 * Fail-soft. An audit row is evidence and must survive a bookkeeping
	 * problem; a row with no purpose is honest about what it does not know,
	 * and the guard has already refused the reads that were required to carry
	 * one.
	 *
	 * @param AuditTrail $auditTrail The row being built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function apply(AuditTrail $auditTrail): void {
		try {
			$context = $this->container->get(PurposeContext::class);
		} catch (Throwable $e) {
			return;
		}

		if (($context instanceof PurposeContext) === false) {
			return;
		}

		$purpose = $context->accepted();
		if ($purpose === null) {
			return;
		}

		$code = $purpose->getCode();
		if ($code === null || $code === '') {
			return;
		}

		$activity = $context->acceptedActivity();
		$activityUuid = $activity?->getUuid();

		$auditTrail->setPurpose($code);

		// Merge rather than replace: an MCP tool invocation already carries its
		// outcome here, and a read made inside one must not erase it.
		$summary = ($auditTrail->getResultSummary() ?? []);
		$summary['purpose'] = [
			'code' => $code,
			'activity' => $activity?->getCode(),
			'activityUuid' => $activityUuid,
		];
		$auditTrail->setResultSummary($summary);

		// Only when nothing else claimed it. A write already resolves its own
		// processing activity from the schema annotation, and that answer is
		// about the write; this one is about the purpose the caller named.
		if ($activityUuid !== null && $activityUuid !== '') {
			$existing = $auditTrail->getProcessingActivityId();
			if ($existing === null || $existing === '') {
				$auditTrail->setProcessingActivityId($activityUuid);
			}
		}
	}//end apply()

	/**
	 * Whether a row's purpose column disagrees with its sealed purpose.
	 *
	 * The column is an unsealed index over a sealed value, so it CAN be edited
	 * without breaking the chain. This is the check that makes such an edit
	 * visible: the sealed copy is the authority, and a row where the two differ
	 * has had its index tampered with or its write interrupted.
	 *
	 * @param AuditTrail $auditTrail The row to check.
	 *
	 * @return bool True when the column and the sealed copy name different purposes.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public static function disagrees(AuditTrail $auditTrail): bool {
		$summary = ($auditTrail->getResultSummary() ?? []);
		$sealed = null;
		if (isset($summary['purpose']['code']) === true && is_string($summary['purpose']['code']) === true) {
			$sealed = $summary['purpose']['code'];
		}

		return $sealed !== $auditTrail->getPurpose();
	}//end disagrees()
}//end class
