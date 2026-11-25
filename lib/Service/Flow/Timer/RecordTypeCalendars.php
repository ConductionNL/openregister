<?php

/**
 * The working calendar a record type (schema) names, if any.
 *
 * A schema names one in its configuration as `workingCalendar`, the slug of a
 * `working-calendar` object. A schema that names none, or that cannot be read,
 * names none: resolution then falls through to the unit and the instance
 * calendar, which is what every instance does today (design D-C67-1). Whether
 * the named slug exists is not decided here: the arm path resolves it at once
 * and refuses an unknown name with the name in the message.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;

/**
 * Reads the `workingCalendar` a schema declares.
 */
class RecordTypeCalendars {

	/**
	 * The schema configuration key that names the calendar.
	 *
	 * @var string
	 */
	public const CONFIGURATION_KEY = 'workingCalendar';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemas Reads the schema.
	 * @param LoggerInterface $logger  Notes a schema that cannot be read.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The calendar slug the schema names, or null when it names none.
	 *
	 * Read without the caller's RBAC and tenancy: the timer engine arms on
	 * behalf of a flow, and the calendar a record type declares is the same
	 * for everybody who works on it.
	 *
	 * @param string $schema The schema id, uuid or slug.
	 *
	 * @return string|null The calendar slug.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
	 */
	public function calendarOf(string $schema): ?string {
		$schema = trim($schema);
		if ($schema === '') {
			return null;
		}

		try {
			$configuration = ($this->schemas->find(id: $schema, _rbac: false, _multitenancy: false)->getConfiguration() ?? []);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			$this->logger->info(
				message: '[RecordTypeCalendars] Schema "' . $schema . '" could not be read; its terms use the unit or instance calendar.',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$slug = ($configuration[self::CONFIGURATION_KEY] ?? null);
		if (is_string($slug) === false || trim($slug) === '') {
			return null;
		}

		return trim($slug);
	}//end calendarOf()
}//end class
