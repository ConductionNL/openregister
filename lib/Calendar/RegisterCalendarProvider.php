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

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\Calendar\ICalendarProvider;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
 */
class RegisterCalendarProvider implements ICalendarProvider {

	/**
	 * The schema mapper for loading schemas
	 *
	 * @var SchemaMapperInterface
	 */
	private SchemaMapperInterface $schemaMapper;

	/**
	 * The collaborators every calendar reads through
	 *
	 * @var RegisterCalendarSources
	 */
	private RegisterCalendarSources $sources;

	/**
	 * The user session for authentication context
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Logger instance
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Cached calendar-enabled schemas (per-request)
	 *
	 * @var array|null
	 */
	private ?array $enabledSchemasCache = null;

	/**
	 * Constructor
	 *
	 * @param SchemaMapperInterface   $schemaMapper The schema mapper
	 * @param IUserSession            $userSession  The user session
	 * @param RegisterCalendarSources $sources      Object/register lookup, transformer and logger
	 */
	public function __construct(
		SchemaMapperInterface $schemaMapper,
		IUserSession $userSession,
		RegisterCalendarSources $sources,
	) {
		$this->schemaMapper = $schemaMapper;
		$this->userSession = $userSession;
		$this->logger = $sources->logger;
		$this->sources = $sources;
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

			$enabledSchemas = $this->getCalendarEnabledSchemas();

			if (empty($enabledSchemas) === true) {
				return [];
			}

			$calendars = [];

			foreach ($enabledSchemas as $schemaData) {
				$schema = $schemaData['schema'];
				$config = $schemaData['config'];
				$calendarUri = 'openregister-schema-' . $schema->getId();

				// Filter by requested URIs if provided.
				if (empty($calendarUris) === false && in_array($calendarUri, $calendarUris, true) === false) {
					continue;
				}

				$calendars[] = new RegisterCalendar(
					schema: $schema,
					calendarConfig: $config,
					principalUri: $principalUri,
					sources: $this->sources
				);
			}

			return $calendars;
		} catch (\Exception $e) {
			$this->logger->warning(
				'[RegisterCalendarProvider] Failed to load calendars: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}//end try
	}//end getCalendars()

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

		$this->enabledSchemasCache = [];

		try {
			// Bypass multi-tenancy: a user with read access to a schema's
			// objects (which is checked downstream when each calendar event
			// is fetched) should see the calendar even if the schema's
			// organisation differs from the user's active organisation.
			// Per-object ACLs gate the actual event content.
			$allSchemas = $this->schemaMapper->findAll(
				_multitenancy: false
			);

			foreach ($allSchemas as $schema) {
				$calendarConfig = $schema->getCalendarProviderConfig();

				if ($calendarConfig === null) {
					continue;
				}

				$this->enabledSchemasCache[] = [
					'schema' => $schema,
					'config' => $calendarConfig,
				];
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				'[RegisterCalendarProvider] Failed to load schemas: ' . $e->getMessage(),
				['exception' => $e]
			);
			$this->enabledSchemasCache = [];
		}//end try

		return $this->enabledSchemasCache;
	}//end getCalendarEnabledSchemas()

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
