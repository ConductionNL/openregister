<?php

/**
 * One search over the registers.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ListQuery;

/**
 * A register search: the page and column filters, raw search conditions with their parameters, and the extensions.
 *
 * Read by {@see RegisterService::searchRegisters()} and {@see RegisterService::searchRegistersSerialized()}.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final readonly class RegisterSearch {
	/**
	 * Constructor.
	 *
	 * @param ListQuery            $query            Limit (unbounded for every row), offset and column filters.
	 * @param array<mixed>         $searchConditions SQL fragments, joined with OR.
	 * @param array<mixed>         $searchParams     Parameter name to value for those fragments.
	 * @param array<string>        $extend           Extension keys to apply when serializing (`schemas`, `@self.stats`).
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function __construct(
		public ListQuery $query,
		public array $searchConditions = [],
		public array $searchParams = [],
		public array $extend = [],
	) {
	}//end __construct()
}//end class
