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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
	 * @param SchemaMapperInterface $schemaMapper Schema lookup mapper.
	 * @param MagicMapperInterface $objectMapper Magic object mapper.
	 * @param ScheduledFilterEvaluator $filterEvaluator Operator-aware filter evaluator.
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public MagicMapperInterface $objectMapper,
		public ScheduledFilterEvaluator $filterEvaluator,
	) {
	}//end __construct()
}//end class
