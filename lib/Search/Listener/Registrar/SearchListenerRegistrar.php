<?php

/**
 * SearchListenerRegistrar: the event listeners of the Search area, one slot per position.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Search\Listener\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Listener\Registrar;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Search\Listener\AggregationThresholdListener;
use OCA\OpenRegister\Search\Listener\ObjectMetricsListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Subscribes the Search area's listeners, one public slot per position.
 *
 * Listeners on one event run in the order they were registered, and that
 * order is pinned (ListenerRegistrationOrderGoldenTest). Moving these lines
 * into SearchRegistrar would reorder the list, so each slot holds the lines
 * of one position and the registrar that owns the ordered list calls it
 * exactly where those lines stood.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class SearchListenerRegistrar {

	/**
	 * The CRUD metric listener: one operational metric row per object create, update and delete.
	 *
	 * Fail-soft: never aborts the write it observes.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function metrics(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectCreatedEvent::class, ObjectMetricsListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, ObjectMetricsListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, ObjectMetricsListener::class);
	}//end metrics()

	/**
	 * The threshold trigger evaluator: re-runs aggregations on writes and dispatches when a threshold is crossed.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listener carries its own spec.
	 */
	public static function thresholds(IRegistrationContext $context): void {
		$context->registerEventListener(ObjectCreatedEvent::class, AggregationThresholdListener::class);
		$context->registerEventListener(ObjectUpdatedEvent::class, AggregationThresholdListener::class);
		$context->registerEventListener(ObjectDeletedEvent::class, AggregationThresholdListener::class);
		$context->registerEventListener(ObjectTransitionedEvent::class, AggregationThresholdListener::class);
	}//end thresholds()
}//end class
