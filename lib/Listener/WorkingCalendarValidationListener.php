<?php

/**
 * OpenRegister WorkingCalendarValidationListener
 *
 * Refuses to store a working calendar the timer engine would refuse to
 * resolve.
 *
 * The engine has always refused a malformed calendar; it just did it at ARM
 * time, on an unrelated timer, hours or days after the edit that caused it.
 * This listener moves the same refusal to the moment the calendar is written,
 * which is the only moment at which refusing is free and the only moment at
 * which the person who can fix it is still looking.
 *
 * It hangs off `ObjectCreatingEvent` / `ObjectUpdatingEvent` because those are
 * dispatched by `MagicMapper` for every persisted object, so the objects API,
 * the admin page and the configuration importer all pass through here. A
 * validation that only guarded the admin page would be bypassed by the two
 * doors that actually carry bulk edits.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-every-write-of-a-working-calendar-is-validated-the-same-way
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates a working calendar before it is persisted.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 */
class WorkingCalendarValidationListener implements IEventListener {

	/**
	 * The error code the refusal carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'working-calendar-invalid';

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendarWriteGuard $guard Recognises and validates a calendar write.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly WorkingCalendarWriteGuard $guard,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Reject a save whose working calendar the engine could not resolve.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-every-write-of-a-working-calendar-is-validated-the-same-way
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$object = $event->getObject();
		} elseif ($event instanceof ObjectUpdatingEvent) {
			$object = $event->getNewObject();
		} else {
			return;
		}

		try {
			if ($this->guard->isWorkingCalendar(object: $object) === false) {
				return;
			}

			$data = $object->getObject();
			if (is_array($data) === false) {
				$data = [];
			}

			$this->guard->assertValid(definition: $data, fallbackSlug: $this->storedSlug(event: $event));
		} catch (FlowTimerValidationException $refused) {
			$event->setErrors(
				[
					'code' => self::ERROR_CODE,
					'message' => $refused->getMessage(),
				]
			);
			$event->stopPropagation();
		} catch (Throwable $failure) {
			// The guard is a guard, not a gate on unrelated saves. When it
			// fails for a reason of its own the save proceeds: the refusal in
			// WorkingCalendarService::bySlug() is still the backstop, which is
			// exactly the situation before this listener existed.
			$this->logger->warning(
				message: '[WorkingCalendarValidationListener] The guard itself failed, allowing the save: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try

	}//end handle()

	/**
	 * The slug the stored calendar already carries, for a partial update.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return string|null The stored slug, or null on a create.
	 */
	private function storedSlug(Event $event): ?string {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return null;
		}

		$old = $event->getOldObject();
		if ($old === null) {
			return null;
		}

		$data = $old->getObject();
		if (is_array($data) === false) {
			return null;
		}

		$slug = trim((string)($data['slug'] ?? ''));
		if ($slug === '') {
			return null;
		}

		return $slug;
	}//end storedSlug()
}//end class
