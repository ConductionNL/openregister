<?php

/**
 * Adapt a positional trail-listing callback to AuditTrailMapper::findTrail().
 *
 * Several suites stub the audit trail with a callback that reads the listing
 * as `(limit, offset, filters, sort, search)`, with a null limit for an
 * unbounded listing. This hands such a callback the same values from the
 * ListQuery that findTrail() receives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
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

namespace OCA\OpenRegister\Tests\Support;

use Closure;
use OCA\OpenRegister\Db\ListQuery;

/**
 * Builds findTrail() stubs from positional listing callbacks.
 */
final class AuditTrailListing {
	/**
	 * A findTrail() callback that calls $inner with the listing's limit (null when unbounded), offset, filters, sort and search.
	 *
	 * @param callable $inner The positional callback.
	 *
	 * @return Closure The findTrail() callback.
	 */
	public static function positional(callable $inner): Closure {
		return static fn (ListQuery $query): mixed => $inner($query->limitOrNull(), $query->offset, $query->filters, $query->sort, $query->search);
	}//end positional()
}//end class
