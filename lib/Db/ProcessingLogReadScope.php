<?php

/**
 * ProcessingLogReadScope: the period and the tenant scope of a processing-log read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * Which part of the processing log a read may see: the period, the
 * organisation it is scoped to, and whether confidential entries are in.
 *
 * The per-subject extract and the filtered inquiry are bounded the same
 * way, so the two reads take one scope. The defaults are the widest read
 * the mapper has always allowed: no period, no organisation (all, admin
 * only), confidential entries excluded.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class ProcessingLogReadScope {

	/**
	 * Constructor.
	 *
	 * @param DateTime|null $from                Inclusive lower bound.
	 * @param DateTime|null $to                  Inclusive upper bound.
	 * @param string|null   $organisationId      Tenant scope (null or '' = all, admin only).
	 * @param bool          $includeConfidential Whether to include confidential entries (FG only).
	 */
	public function __construct(
		public readonly ?DateTime $from = null,
		public readonly ?DateTime $to = null,
		public readonly ?string $organisationId = null,
		public readonly bool $includeConfidential = false,
	) {
	}//end __construct()
}//end class
