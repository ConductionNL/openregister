<?php

/**
 * Where the scheduled notification scan finds its schemas and objects, and how it filters them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Notifications\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\BusinessTimeInterface;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;

/**
 * Where the scheduled notification scan finds its schemas and objects, and how it filters them.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class ScheduledObjectSources {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface   $catalog         The schema mapper (schema lookup) and the magic mapper (objects).
	 * @param ScheduledFilterEvaluator $filterEvaluator Operator-aware filter evaluator.
	 * @param BusinessTimeInterface|null $businessTime The working calendars and calculator a reminder counts its offsets with.
	 */
	public function __construct(
		public SchemaCatalogInterface $catalog,
		public ScheduledFilterEvaluator $filterEvaluator,
		public ?BusinessTimeInterface $businessTime = null,
	) {
	}//end __construct()
}//end class
