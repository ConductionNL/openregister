<?php

/**
 * QualityAuditRefusedException — an audit definition refused at save, naming each fault.
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

use InvalidArgumentException;

/**
 * The faults of a refused audit, one sentence each (REQ-RCN-003).
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
final class QualityAuditRefusedException extends InvalidArgumentException {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $faults The faults.
	 */
	public function __construct(
		private readonly array $faults,
	) {
		parent::__construct(message: 'The quality audit cannot be saved: '.implode(' ', $faults));
	}//end __construct()

	/**
	 * The faults, one sentence each.
	 *
	 * @return array<int, string> The faults.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-declares-a-population-a-rule-set-and-a-tolerance-req-rcn-003
	 */
	public function faults(): array {
		return $this->faults;
	}//end faults()
}//end class
