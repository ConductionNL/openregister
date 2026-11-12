<?php

/**
 * OpenRegister ThresholdRun
 *
 * One evaluation of one threshold notification after an object write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The schema that declares a threshold notification, the object whose write triggered it, and the notification's name.
 *
 * Built and used only inside ThresholdEvaluationService.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ThresholdRun {
	/**
	 * Start a run.
	 *
	 * @param Schema       $schema           Schema declaring the notification.
	 * @param ObjectEntity $object           Object that just changed.
	 * @param string       $notificationName Notification key in the schema config.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ObjectEntity $object,
		public readonly string $notificationName,
	) {
	}//end __construct()

	/**
	 * The state cache key of this notification on this schema.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function stateKey(): string {
		return sprintf('threshold:%d:%s', $this->schema->getId(), $this->notificationName);
	}//end stateKey()
}//end class
