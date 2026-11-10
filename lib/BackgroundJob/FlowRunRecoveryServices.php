<?php

/**
 * The recovery collaborators of the flow run worker.
 *
 * @category Cron
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\FlowClaimMapperInterface;
use OCA\OpenRegister\Db\FlowStreamMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Object\RunLockReleaseInterface;

/**
 * What the worker needs to recover from a dead pass: the place claims it
 * left, the streams they held, the flow whose `onError` policy decides the
 * siblings' fate, and the run-held object locks it orphaned.
 *
 * Each is nullable with a default so an older DI graph still constructs the
 * worker; a missing one switches its recovery pass off.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md
 */
final class FlowRunRecoveryServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowClaimMapperInterface|null  $claims  Place claims, for the abandoned-claim reaper.
	 * @param FlowStreamMapperInterface|null $streams Run streams, for failing an abandoned branch.
	 * @param FlowLocatorInterface|null      $flows   Resolves the run's flow, for the abandoned step's `onError` policy.
	 * @param RunLockReleaseInterface|null   $locks   Run-held object locks, for the orphaned-lock sweep.
	 */
	public function __construct(
		public readonly ?FlowClaimMapperInterface $claims = null,
		public readonly ?FlowStreamMapperInterface $streams = null,
		public readonly ?FlowLocatorInterface $flows = null,
		public readonly ?RunLockReleaseInterface $locks = null,
	) {
	}//end __construct()
}//end class
