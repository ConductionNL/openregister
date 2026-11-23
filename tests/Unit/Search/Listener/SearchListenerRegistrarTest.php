<?php

/**
 * Search's listener slots register Search's listeners, in their order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Search\Listener\AggregationThresholdListener;
use OCA\OpenRegister\Search\Listener\ObjectMetricsListener;
use OCA\OpenRegister\Search\Listener\Registrar\SearchListenerRegistrar;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Listener\Registrar\SearchListenerRegistrar
 */
final class SearchListenerRegistrarTest extends TestCase {

	/**
	 * The metrics slot registers the metric listener on create, update and delete, in that order.
	 *
	 * @return void
	 */
	public function testTheMetricsSlotRegistersItsLines(): void {
		$context = new RecordingRegistrationContext();
		SearchListenerRegistrar::metrics(context: $context);

		$this->assertSame(
			[
				['event' => ObjectCreatedEvent::class, 'listener' => ObjectMetricsListener::class],
				['event' => ObjectUpdatedEvent::class, 'listener' => ObjectMetricsListener::class],
				['event' => ObjectDeletedEvent::class, 'listener' => ObjectMetricsListener::class],
			],
			$context->listeners
		);
	}//end testTheMetricsSlotRegistersItsLines()

	/**
	 * The thresholds slot registers the threshold evaluator on create, update, delete and transition, in that order.
	 *
	 * @return void
	 */
	public function testTheThresholdsSlotRegistersItsLines(): void {
		$context = new RecordingRegistrationContext();
		SearchListenerRegistrar::thresholds(context: $context);

		$this->assertSame(
			[
				['event' => ObjectCreatedEvent::class, 'listener' => AggregationThresholdListener::class],
				['event' => ObjectUpdatedEvent::class, 'listener' => AggregationThresholdListener::class],
				['event' => ObjectDeletedEvent::class, 'listener' => AggregationThresholdListener::class],
				['event' => ObjectTransitionedEvent::class, 'listener' => AggregationThresholdListener::class],
			],
			$context->listeners
		);
	}//end testTheThresholdsSlotRegistersItsLines()
}//end class
