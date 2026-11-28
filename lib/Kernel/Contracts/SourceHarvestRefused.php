<?php

/**
 * OpenRegister SourceHarvestRefused
 *
 * Why a source's harvest did not run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use RuntimeException;

/**
 * A harvest that did not run: the message is the reason, isError() whether the run failed or was skipped.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
final class SourceHarvestRefused extends RuntimeException {

	public const DISABLED = 'source-disabled';

	public const FETCHER_MISSING = 'fetcher-missing';

	public const MAPPING_MISSING = 'mapping-missing';

	public const ALREADY_RUNNING = 'already-running';

	/**
	 * Constructor.
	 *
	 * @param string $reason  One of the reason constants.
	 * @param bool   $isError Whether the run failed (true) or was skipped (false).
	 */
	public function __construct(string $reason, private readonly bool $isError) {
		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * Whether the refusal fails the run.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function isError(): bool {
		return $this->isError;
	}//end isError()
}//end class
