<?php

/**
 * Event classes standing in for another app's events, for AppWebhookEventsTest.
 *
 * They live outside OpenRegister's namespace on purpose: a class inside it is
 * refused as an app event.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\AppWebhookFixture;

use OCP\EventDispatcher\Event;

/**
 * An app event with a payload method.
 */
class ThingSignedEvent extends Event {

	/**
	 * Construct the event.
	 *
	 * @param array<string, mixed> $payload The payload to hand over.
	 */
	public function __construct(private readonly array $payload) {
		parent::__construct();

	}//end __construct()

	/**
	 * The payload.
	 *
	 * @return array<string, mixed>
	 */
	public function getPayload(): array {
		return $this->payload;

	}//end getPayload()

	/**
	 * A payload method that needs an argument.
	 *
	 * @param string $key Any key.
	 *
	 * @return array<string, mixed>
	 */
	public function payloadFor(string $key): array {
		return [$key => true];

	}//end payloadFor()

	/**
	 * A payload method that returns no array.
	 *
	 * @return string
	 */
	public function describe(): string {
		return 'not an array';

	}//end describe()

	/**
	 * A payload method nobody outside may call.
	 *
	 * @return array<string, mixed>
	 */
	protected function hidden(): array {
		return [];

	}//end hidden()
}//end class

/**
 * A second app event.
 */
class ThingArchivedEvent extends ThingSignedEvent {
}//end class

/**
 * A class that is not an event.
 */
class NotAnEvent {

	/**
	 * The payload.
	 *
	 * @return array<string, mixed>
	 */
	public function getPayload(): array {
		return [];

	}//end getPayload()
}//end class
