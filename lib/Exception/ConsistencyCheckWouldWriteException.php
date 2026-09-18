<?php

/**
 * OpenRegister ConsistencyCheckWouldWriteException
 *
 * Thrown when a consistency probe hands the check a query that is not a read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * The check writes nothing, and this is how that is enforced rather than
 * promised.
 *
 * A probe that would write is a bug in the probe, not a finding about the
 * data, so it is refused loudly at the moment it is offered rather than
 * quietly skipped: a skipped probe reports zero inconsistencies, which reads
 * exactly like a clean instance.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */
class ConsistencyCheckWouldWriteException extends Exception {

	/**
	 * The probe that offered the write.
	 *
	 * @var string
	 */
	private readonly string $probe;

	/**
	 * Constructor.
	 *
	 * @param string $message What went wrong.
	 * @param string $probe   The probe slug.
	 */
	public function __construct(string $message, string $probe) {
		parent::__construct(message: $message);
		$this->probe = $probe;

	}//end __construct()

	/**
	 * The probe that offered the write.
	 *
	 * @return string The probe slug.
	 */
	public function getProbe(): string {
		return $this->probe;

	}//end getProbe()
}//end class
