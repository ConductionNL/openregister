<?php

/**
 * QualityAuditStores — the audit definitions and their kept runs, as one dependency.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use OCA\OpenRegister\Db\QualityAuditMapperInterface;
use OCA\OpenRegister\Db\QualityAuditRunMapperInterface;

/**
 * The two stores a quality audit reads and writes.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
final readonly class QualityAuditStores {

	/**
	 * Constructor.
	 *
	 * @param QualityAuditMapperInterface    $audits The audit definitions.
	 * @param QualityAuditRunMapperInterface $runs   The kept runs and their failing records.
	 */
	public function __construct(
		public QualityAuditMapperInterface $audits,
		public QualityAuditRunMapperInterface $runs,
	) {
	}//end __construct()
}//end class
