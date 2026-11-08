<?php

/**
 * ApiCallRecordMapperInterface: what callers outside the component use of ApiCallRecordMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * The API call records the API callers endpoint reads.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ApiCallRecordMapperInterface {

	/**
	 * The records whose last call falls in a period.
	 *
	 * @param DateTime $from The start of the period.
	 * @param DateTime $to The end of the period.
	 * @param string|null $apiVersion Narrow to one contract version, or null for all.
	 * @param int $limit The most rows to return.
	 *
	 * @return array<int, ApiCallRecord> The records, busiest first.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function findInPeriod(DateTime $from, DateTime $to, ?string $apiVersion = null, int $limit = 500): array;
}//end interface
