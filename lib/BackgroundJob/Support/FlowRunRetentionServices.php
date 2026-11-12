<?php

/**
 * OpenRegister flow run retention services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * What the flow-run retention job works with: the history it prunes, the
 * setting that says how long to keep it, and the log of what was swept.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
final readonly class FlowRunRetentionServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowRunRetentionStores $stores Runs, their steps, and the flows declaring an override.
	 * @param IAppConfig             $config Reads the retention period.
	 * @param LoggerInterface        $logger Records what was swept.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function __construct(
		public FlowRunRetentionStores $stores,
		public IAppConfig $config,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
