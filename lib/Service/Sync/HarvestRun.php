<?php

/**
 * OpenRegister HarvestRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
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

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\Source;

/**
 * One harvest run of one source: what is harvested, through which transport,
 * under which execution id and from which incremental checkpoint.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final readonly class HarvestRun {

	/**
	 * Describe the run.
	 *
	 * @param Source                 $source      The source to harvest.
	 * @param SourceFetcherInterface $fetcher     Transport for gather/fetch.
	 * @param string                 $executionId Unique id for this run.
	 * @param string|null            $since       Incremental checkpoint (null = full).
	 */
	public function __construct(
		public Source $source,
		public SourceFetcherInterface $fetcher,
		public string $executionId,
		public ?string $since = null,
	) {
	}//end __construct()
}//end class
