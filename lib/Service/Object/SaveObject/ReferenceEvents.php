<?php

/**
 * OpenRegister ReferenceEvents
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Event\ReferenceValidatedEvent;
use OCA\OpenRegister\Event\ReferenceValidationFailedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Announces accepted and refused references; a failing listener never blocks a save.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class ReferenceEvents {

	/**
	 * @param IEventDispatcher|null $dispatcher The dispatcher, when one is wired.
	 * @param LoggerInterface       $logger     The logger.
	 */
	public function __construct(
		private readonly ?IEventDispatcher $dispatcher,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Announce a reference that resolved.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function accepted(ReferenceCheck $check): void {
		$this->dispatch(eventClass: ReferenceValidatedEvent::class, check: $check);
	}//end accepted()

	/**
	 * Announce a reference that did not resolve.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function refused(ReferenceCheck $check): void {
		$this->dispatch(eventClass: ReferenceValidationFailedEvent::class, check: $check);
	}//end refused()

	/**
	 * Dispatch one event, logging a listener's failure.
	 *
	 * @param string         $eventClass The event class.
	 * @param ReferenceCheck $check      The reference it is about.
	 *
	 * @psalm-param class-string<ReferenceValidatedEvent|ReferenceValidationFailedEvent> $eventClass
	 *
	 * @return void
	 */
	private function dispatch(string $eventClass, ReferenceCheck $check): void {
		try {
			$this->dispatcher?->dispatchTyped(self::event(eventClass: $eventClass, check: $check));
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[SaveObject] '.substr(strrchr($eventClass, '\\'), 1).' dispatch failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $check->propertyName, 'uuid' => $check->uuid, 'error' => $e->getMessage()]
			);
		}
	}//end dispatch()

	/**
	 * The event about one reference.
	 *
	 * @param string         $eventClass The event class.
	 * @param ReferenceCheck $check      The reference.
	 *
	 * @psalm-param class-string<ReferenceValidatedEvent|ReferenceValidationFailedEvent> $eventClass
	 *
	 * @return ReferenceValidatedEvent|ReferenceValidationFailedEvent
	 */
	private static function event(string $eventClass, ReferenceCheck $check): ReferenceValidatedEvent|ReferenceValidationFailedEvent {
		return new $eventClass(
			propertyName: $check->propertyName,
			referencedUuid: $check->uuid,
			targetSchemaSlug: $check->targetSchemaSlug,
			targetRegister: $check->register
		);
	}//end event()
}//end class
