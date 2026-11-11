<?php

/**
 * OpenRegister Calendar Provider
 *
 * Implements ICalendarProvider to register virtual calendars
 * for OpenRegister schemas with calendar-enabled date fields.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use OCP\Calendar\ICalendarProvider;

/**
 * Calendar provider that creates virtual calendars from OpenRegister schemas
 *
 * Registers one ICalendar per schema that has calendarProvider.enabled = true
 * in its configuration. These virtual calendars surface object date fields
 * as read-only events in the Nextcloud Calendar app.
 *
 * @package OCA\OpenRegister\Calendar
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
class RegisterCalendarProvider implements ICalendarProvider {

	/**
	 * Cached calendar-enabled schemas (per-request)
	 *
	 * @var array|null
	 */
	private ?array $enabledSchemasCache = null;

	/**
	 * Constructor
	 *
	 * @param RegisterCalendarSources $sources Schema/register/object lookup, transformer and logger
	 */
	public function __construct(
		private readonly RegisterCalendarSources $sources,
	) {
	}//end __construct()

	/**
	 * Get virtual calendars for the given principal
	 *
	 * Returns one RegisterCalendar per schema that has calendar provider enabled.
	 * Respects RBAC: anonymous/unauthenticated principals get no calendars.
	 *
	 * @param string $principalUri The principal URI (e.g., principals/users/admin)
	 * @param array $calendarUris Optional URI filter to return only specific calendars
	 *
	 * @return array Array of ICalendar instances
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getCalendars(string $principalUri, array $calendarUris = []): array {
		try {
			// Reject anonymous/unauthenticated principals.
			if ($this->isValidUserPrincipal(principalUri: $principalUri) === false) {
				return [];
			}

			return $this->calendarsFor(principalUri: $principalUri, calendarUris: $calendarUris);
		} catch (\Exception $e) {
			$this->logLoadFailure(what: 'calendars', exception: $e);
			return [];
		}//end try
	}//end getCalendars()

	/**
	 * The calendars of every calendar-enabled schema, narrowed to the requested URIs.
	 *
	 * @param string             $principalUri The user principal
	 * @param array<int, string> $calendarUris The requested calendar URIs; empty for all
	 *
	 * @return array<int, RegisterCalendar> The calendars
	 */
	private function calendarsFor(string $principalUri, array $calendarUris): array {
		$enabledSchemas = $this->getCalendarEnabledSchemas();

		if (empty($enabledSchemas) === true) {
			return [];
		}

		$calendars = [];

		foreach ($enabledSchemas as $schemaData) {
			$calendarUri = 'openregister-schema-' . $schemaData['schema']->getId();
			if ($this->isRequested(calendarUri: $calendarUri, calendarUris: $calendarUris) === false) {
				continue;
			}

			$calendars[] = $this->calendarFor(schemaData: $schemaData, principalUri: $principalUri);
		}

		return $calendars;
	}//end calendarsFor()

	/**
	 * Whether a calendar is among the requested URIs (every calendar is, when none are requested).
	 *
	 * @param string             $calendarUri  The calendar's URI
	 * @param array<int, string> $calendarUris The requested calendar URIs; empty for all
	 *
	 * @return bool True when the calendar is requested
	 */
	private function isRequested(string $calendarUri, array $calendarUris): bool {
		return empty($calendarUris) === true || in_array($calendarUri, $calendarUris, true) === true;
	}//end isRequested()

	/**
	 * The calendar of one calendar-enabled schema for a principal.
	 *
	 * @param array{schema: \OCA\OpenRegister\Db\Schema, config: array} $schemaData   The schema and its calendar configuration
	 * @param string                               $principalUri The user principal
	 *
	 * @return RegisterCalendar The calendar
	 */
	private function calendarFor(array $schemaData, string $principalUri): RegisterCalendar {
		return new RegisterCalendar(
			definition: new CalendarDefinition(schema: $schemaData['schema'], config: $schemaData['config']),
			principalUri: $principalUri,
			sources: $this->sources
		);
	}//end calendarFor()

	/**
	 * Get all schemas that have calendar provider enabled
	 *
	 * Results are cached within the request to avoid repeated DB queries.
	 *
	 * @return array Array of ['schema' => Schema, 'config' => array] entries
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function getCalendarEnabledSchemas(): array {
		if ($this->enabledSchemasCache !== null) {
			return $this->enabledSchemasCache;
		}

		try {
			// Bypass multi-tenancy: a user with read access to a schema's
			// objects (which is checked downstream when each calendar event
			// is fetched) should see the calendar even if the schema's
			// organisation differs from the user's active organisation.
			// Per-object ACLs gate the actual event content.
			$allSchemas = $this->sources->catalog->schemas()->findAll(
				_multitenancy: false
			);
			$this->enabledSchemasCache = $this->calendarEnabled(schemas: $allSchemas);
		} catch (\Exception $e) {
			$this->logLoadFailure(what: 'schemas', exception: $e);
			$this->enabledSchemasCache = [];
		}

		return $this->enabledSchemasCache;
	}//end getCalendarEnabledSchemas()

	/**
	 * The schemas that carry a calendar provider configuration, with that configuration.
	 *
	 * @param array<int, \OCA\OpenRegister\Db\Schema> $schemas The schemas
	 *
	 * @return array<int, array{schema: \OCA\OpenRegister\Db\Schema, config: array}> The calendar-enabled schemas
	 */
	private function calendarEnabled(array $schemas): array {
		$enabled = [];
		foreach ($schemas as $schema) {
			$calendarConfig = $schema->getCalendarProviderConfig();

			if ($calendarConfig === null) {
				continue;
			}

			$enabled[] = [
				'schema' => $schema,
				'config' => $calendarConfig,
			];
		}

		return $enabled;
	}//end calendarEnabled()

	/**
	 * Log a failure to load calendars or schemas; the provider then answers none.
	 *
	 * @param string     $what      What failed to load
	 * @param \Exception $exception The failure
	 *
	 * @return void
	 */
	private function logLoadFailure(string $what, \Exception $exception): void {
		$this->sources->logger->warning(
			'[RegisterCalendarProvider] Failed to load ' . $what . ': ' . $exception->getMessage(),
			['exception' => $exception]
		);
	}//end logLoadFailure()

	/**
	 * Check if a principal URI represents a valid authenticated user
	 *
	 * @param string $principalUri The principal URI
	 *
	 * @return bool True if the principal is a valid user
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function isValidUserPrincipal(string $principalUri): bool {
		return preg_match('/^principals\/users\/.+$/', $principalUri) === 1;
	}//end isValidUserPrincipal()
}//end class
