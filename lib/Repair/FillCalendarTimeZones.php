<?php

/**
 * FillCalendarTimeZones: gives every working calendar without a time zone the
 * instance's own one.
 *
 * A calendar saved before its zone was asked for has none, and is read in UTC.
 * This step writes the instance's `default_timezone` onto each of them, so the
 * calendar keeps meaning the local working day it was meant to describe. An
 * instance without a valid `default_timezone` gets `UTC`, which is what such a
 * calendar was already read in, and the step says so out loud. A calendar that
 * already names a zone is never touched.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use DateTimeZone;
use OCA\OpenRegister\Contract\RegisterSchemaSlugs;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills the `timezone` of every working calendar that has none.
 *
 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
 */
class FillCalendarTimeZones implements IRepairStep {

	/**
	 * The zone a calendar gets when the instance names no valid one.
	 */
	public const FALLBACK = 'UTC';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects Reads and writes the calendars.
	 * @param IConfig                   $config  The instance's `default_timezone`.
	 * @param LoggerInterface           $logger  Records the fallback and any calendar not written.
	 *
	 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
	 */
	public function getName(): string {
		return 'Give every working calendar without a time zone the instance default_timezone';
	}//end getName()

	/**
	 * Fill the missing zones and report how many calendars got one.
	 *
	 * @param IOutput $output Output interface for status messages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$calendars = $this->calendarsWithoutZone();
		} catch (Throwable $failure) {
			// A fresh install runs this before the flow-timers register exists; there is nothing to fill.
			$output->info('Working calendar time zones not filled: ' . $failure->getMessage());

			return;
		}

		if ($calendars === []) {
			$output->info('Every working calendar names its time zone.');

			return;
		}

		$zone = $this->instanceZone(output: $output);

		$filled = 0;
		foreach ($calendars as $calendar) {
			if ($this->fill(calendar: $calendar, zone: $zone) === true) {
				$filled++;
			}
		}

		$output->info(sprintf('%d of %d working calendars without a time zone now use %s.', $filled, count($calendars), $zone));
	}//end run()

	/**
	 * The zone the calendars get: the instance's valid `default_timezone`, else UTC with a warning.
	 *
	 * @param IOutput $output Output interface for the warning.
	 *
	 * @return string The IANA zone name.
	 *
	 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
	 */
	public function instanceZone(IOutput $output): string {
		$zone = trim($this->config->getSystemValueString('default_timezone', ''));
		if ($zone !== '' && in_array($zone, DateTimeZone::listIdentifiers(), true) === true) {
			return $zone;
		}

		$advice = sprintf(
			"working calendars without a time zone get %s; set each calendar's zone if that is not where it works.",
			self::FALLBACK
		);
		$message = sprintf("The instance's default_timezone '%s' is not an IANA zone name, so ", $zone) . $advice;
		if ($zone === '') {
			$message = 'The instance sets no default_timezone, so ' . $advice;
		}

		$output->warning($message);
		$this->logger->warning('[FillCalendarTimeZones] ' . $message);

		return self::FALLBACK;
	}//end instanceZone()

	/**
	 * The stored calendars whose `timezone` is missing or blank.
	 *
	 * @return list<ObjectEntity> The calendars.
	 *
	 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
	 */
	private function calendarsWithoutZone(): array {
		$result = $this->objects->searchObjectsBySlug(
			target: new RegisterSchemaSlugs(register: FlowTimerDefinitionStore::REGISTER, schema: FlowTimerDefinitionStore::SCHEMA_CALENDAR),
			filters: [],
			_rbac: false,
			_multitenancy: false
		);
		if (is_array($result) === false) {
			return [];
		}

		$calendars = [];
		foreach ($result as $entity) {
			if ($entity instanceof ObjectEntity === false) {
				continue;
			}

			$zone = ($entity->getObject()['timezone'] ?? null);
			if ($zone === null || (is_string($zone) === true && trim($zone) === '')) {
				$calendars[] = $entity;
			}
		}

		return $calendars;
	}//end calendarsWithoutZone()

	/**
	 * Write the zone onto one calendar; a calendar that cannot be written is logged and left as it was.
	 *
	 * @param ObjectEntity $calendar The calendar.
	 * @param string       $zone     The zone.
	 *
	 * @return bool Whether it was written.
	 *
	 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
	 */
	private function fill(ObjectEntity $calendar, string $zone): bool {
		$data = $calendar->getObject();
		$data['timezone'] = $zone;

		try {
			$this->objects->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: FlowTimerDefinitionStore::REGISTER, schema: FlowTimerDefinitionStore::SCHEMA_CALENDAR),
					uuid: $calendar->getUuid()
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[FillCalendarTimeZones] Working calendar not given a time zone: ' . $failure->getMessage(),
				['calendar' => $calendar->getUuid(), 'slug' => ($data['slug'] ?? null)]
			);

			return false;
		}

		return true;
	}//end fill()
}//end class
