<?php

/**
 * OpenRegister auto transition services
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

use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPassInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;

/**
 * What re-fetches a queued automatic move's object, resumes its pass and applies the move.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AutoTransitionServices {
	/**
	 * Bundle the services.
	 *
	 * @param DeferredEntryObjectResolverInterface $resolver Stale-safe entry re-fetch.
	 * @param TransitionEngine                     $engine   The one path every automatic move goes through.
	 * @param AutoTransitionPassInterface          $pass     The pass, resumed with the entry's carried lineage.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly DeferredEntryObjectResolverInterface $resolver,
		public readonly TransitionEngine $engine,
		public readonly AutoTransitionPassInterface $pass,
	) {
	}//end __construct()
}//end class
