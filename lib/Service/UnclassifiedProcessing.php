<?php

/**
 * OpenRegister UnclassifiedProcessing
 *
 * The platform's unclassified-processing gap: processing-log entries the
 * flagged fallback activity absorbed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ProcessingLogMapperInterface;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;

/**
 * Counts processing-log entries attributed to the flagged fallback activity
 * (`niet-geclassificeerde-verwerking`, OR-PA-4).
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class UnclassifiedProcessing {

	/**
	 * Constructor.
	 *
	 * @param ProcessingLogMapperInterface|null         $logMapper Read-log mapper; null when the read log is unavailable.
	 * @param VerwerkingsactiviteitMapperInterface|null $vrwMapper Activity resolver; null when the register is unavailable.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		private readonly ?ProcessingLogMapperInterface $logMapper = null,
		private readonly ?VerwerkingsactiviteitMapperInterface $vrwMapper = null,
	) {
	}//end __construct()

	/**
	 * The number of entries attributed to the fallback activity.
	 *
	 * 0 when the read-log machinery is unavailable or there is no fallback
	 * activity; a failing lookup is thrown to the caller, which fails soft.
	 *
	 * @return int The count.
	 *
	 * @throws \Throwable When a mapper fails.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function count(): int {
		if ($this->logMapper === null || $this->vrwMapper === null) {
			return 0;
		}

		$fallback = $this->vrwMapper->findByCode(code: AvgComplianceService::FALLBACK_CODE);
		if ($fallback === null) {
			return 0;
		}

		$counts = $this->logMapper->countByActivity();
		return (int)($counts[(string)$fallback->getUuid()] ?? 0);
	}//end count()
}//end class
