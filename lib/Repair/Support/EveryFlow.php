<?php

/**
 * Every flow on the instance, read page by page
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair\Support
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

namespace OCA\OpenRegister\Repair\Support;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;

/**
 * Reads every flow for the repair steps that walk all of them.
 *
 * 🔴 `findAllFlows()` DEFAULTS TO 100, so a single call silently covers only
 * the first page. This pages until a short page comes back, rather than
 * asking for one huge limit that would truncate once an instance outgrows it.
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */
final class EveryFlow {
	/**
	 * Rows asked for per page.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface $flows The flow mapper.
	 */
	public function __construct(
		private readonly FlowMapperInterface $flows,
	) {
	}//end __construct()

	/**
	 * Every flow, in the mapper's page order.
	 *
	 * @return array<int, Flow> Every flow on the instance.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function read(): array {
		$offset = 0;
		$all = [];

		while (true) {
			$batch = $this->flows->findAllFlows(query: new ListQuery(limit: self::PAGE, offset: $offset));
			$all = array_merge($all, $batch);

			if (count($batch) < self::PAGE) {
				return $all;
			}

			$offset += self::PAGE;
		}
	}//end read()
}//end class
