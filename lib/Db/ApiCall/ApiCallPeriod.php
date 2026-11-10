<?php

/**
 * OpenRegister ApiCallPeriod
 *
 * The period, contract version and row cap of one read of the API caller record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\ApiCall
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\ApiCall;

use DateTime;

/**
 * Which caller records a read selects: last call between two moments, optionally one contract version, at most so many rows.
 *
 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
 */
final class ApiCallPeriod {

	/**
	 * Constructor.
	 *
	 * @param DateTime    $from       The start of the period.
	 * @param DateTime    $to         The end of the period.
	 * @param string|null $apiVersion Narrow to one contract version, or null (or empty) for all.
	 * @param int         $limit      The most rows to return; anything below one reads one.
	 */
	public function __construct(
		public readonly DateTime $from,
		public readonly DateTime $to,
		public readonly ?string $apiVersion = null,
		public readonly int $limit = 500,
	) {
	}//end __construct()
}//end class
