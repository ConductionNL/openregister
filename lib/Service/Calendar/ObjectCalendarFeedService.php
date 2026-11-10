<?php

/**
 * Generates a subscribable iCalendar feed of the dates on objects.
 *
 * Nothing is written into anybody's calendar. The feed is generated from the
 * objects on every read, so a term that has been suspended, extended or
 * recalculated is right at the next refresh and no stale event survives, and
 * an object that has been archived or deleted simply stops appearing. A
 * written event would need every mover to remember to rewrite it, and the one
 * that forgets is the one that misses a statutory deadline.
 *
 * Access is the object's access. The feed runs as the principal the token
 * names, through the same search path the object list uses, with RBAC on. No
 * second access rule is written for the calendar, because a second access
 * rule is how a leak happens.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTimeZone;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\SearchOptions;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The read-only calendar feed over object dates.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
class ObjectCalendarFeedService {

	/**
	 * The most objects one feed read walks. A calendar client that needs more
	 * than this wants a narrower saved view, not a longer feed.
	 *
	 * @var int
	 */
	public const MAX_OBJECTS = 1000;

	/**
	 * Declarations already read, keyed by schema id.
	 *
	 * @var array<string, array<int, ObjectDateDeclaration>>
	 */
	private array $declarationCache = [];

	/**
	 * Builds one VEVENT per declared date.
	 *
	 * @var ObjectDateEventBuilder
	 */
	private readonly ObjectDateEventBuilder $eventBuilder;

	/**
	 * Constructor.
	 *
	 * @param ObjectCalendarFeedSources   $sources   The stores the object calendar feed reads from.
	 * @param ObjectCalendarFeedRendering $rendering The collaborators that turn object dates into an iCalendar body.
	 * @param LoggerInterface             $logger    PSR logger.
	 */
	public function __construct(
		private readonly ObjectCalendarFeedSources $sources,
		private readonly ObjectCalendarFeedRendering $rendering,
		private readonly LoggerInterface $logger,
	) {
		$this->eventBuilder = $rendering->eventBuilder;
	}//end __construct()

	/**
	 * Render the feed a token addresses, or null when it cannot be rendered.
	 *
	 * Null means 404. It never means a smaller calendar: a partial calendar
	 * looks like an empty day, and an empty day is believed.
	 *
	 * @param CalendarFeedToken $token The live token.
	 *
	 * @return string|null The iCalendar body, or null.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function render(CalendarFeedToken $token): ?string {
		$user = $this->sources->userManager->get((string)$token->getUserId());

		if ($user === null) {
			// An unresolvable principal answers nothing, never the register.
			$this->logger->warning(
				'[ObjectCalendarFeedService] Feed token names an unknown principal; answering nothing.'
			);
			return null;
		}

		try {
			return $this->sources->objects->runAs(
				$user,
				function () use ($token): ?string {
					return $this->renderAsCurrentUser(token: $token);
				}
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[ObjectCalendarFeedService] Feed render failed: ' . $failure->getMessage(),
				['exception' => $failure]
			);
			return null;
		}
	}//end render()

	/**
	 * Render the feed as whoever the session currently is.
	 *
	 * @param CalendarFeedToken $token The token naming the scope.
	 *
	 * @return string|null The iCalendar body, or null when the scope is gone.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function renderAsCurrentUser(CalendarFeedToken $token): ?string {
		$scope = $this->resolveScope(token: $token);
		if ($scope === null) {
			return null;
		}

		$timeZone = $this->tenantTimeZone();
		$objects = $this->listObjects(token: $token, scope: $scope);

		$events = $this->collectEvents(objects: $objects, timeZone: $timeZone);

		return $this->rendering->writer->calendar(
			contents: new CalendarContents(
				calendarName: $scope['name'],
				timeZone: $timeZone,
				componentLines: $events['lines'],
				years: new CalendarYearSpan(fromYear: min($events['years']), toYear: max($events['years']))
			)
		);
	}//end renderAsCurrentUser()

	/**
	 * The events of every publishable object, with the years they touch.
	 *
	 * The current year is always covered, so an empty feed still carries a
	 * usable VTIMEZONE.
	 *
	 * @param array<int, mixed> $objects The objects the scope lists.
	 * @param DateTimeZone $timeZone The tenant zone.
	 *
	 * @return array{lines: array<int, string>, years: array<int, int>} The unfolded lines and years.
	 */
	private function collectEvents(array $objects, DateTimeZone $timeZone): array {
		$events = ['lines' => [], 'years' => [(int)date('Y')]];

		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity === false || $this->isPublishable(object: $object) === false) {
				continue;
			}

			$added = $this->objectEvents(object: $object, timeZone: $timeZone);
			$events['lines'] = array_merge($events['lines'], $added['lines']);
			$events['years'] = array_merge($events['years'], $added['years']);
		}

		return $events;
	}//end collectEvents()

	/**
	 * The events of every date one object declares.
	 *
	 * @param ObjectEntity $object The publishable object.
	 * @param DateTimeZone $timeZone The tenant zone.
	 *
	 * @return array{lines: array<int, string>, years: array<int, int>} The object's lines and years.
	 */
	private function objectEvents(ObjectEntity $object, DateTimeZone $timeZone): array {
		$events = ['lines' => [], 'years' => []];
		foreach ($this->declarationsFor(object: $object) as $declaration) {
			$event = $this->eventBuilder->build(
				occurrence: new ObjectDateOccurrence(
					object: $object,
					schemaId: (string)$object->getSchema(),
					declaration: $declaration,
					timeZone: $timeZone
				)
			);

			if ($event === null) {
				continue;
			}

			$events['lines'] = array_merge($events['lines'], $event['lines']);
			$events['years'] = array_merge($events['years'], $event['years']);
		}

		return $events;
	}//end objectEvents()

	/**
	 * Resolve what a token covers: one schema, or one saved view.
	 *
	 * @param CalendarFeedToken $token The token.
	 *
	 * @return array{name: string, schemaId: string|null, viewId: string|null}|null
	 *         The scope, or null when it no longer exists.
	 */
	private function resolveScope(CalendarFeedToken $token): ?array {
		$scopeId = (string)$token->getScopeId();

		try {
			if ($token->getScopeType() === CalendarFeedToken::SCOPE_VIEW) {
				$view = $this->sources->views->find($scopeId);
				return [
					'name' => $this->displayName(candidate: (string)$view->getName()),
					'schemaId' => null,
					'viewId' => $scopeId,
				];
			}

			$schema = $this->sources->schemas->find($scopeId);
			return [
				'name' => $this->displayName(candidate: (string)$schema->getTitle()),
				'schemaId' => (string)$schema->getId(),
				'viewId' => null,
			];
		} catch (Throwable $missing) {
			$this->logger->warning(
				'[ObjectCalendarFeedService] Feed scope no longer resolves: ' . $missing->getMessage()
			);
			return null;
		}
	}//end resolveScope()

	/**
	 * The name a client shows for the feed, never an empty string.
	 *
	 * @param string $candidate The scope's own name.
	 *
	 * @return string The display name.
	 */
	private function displayName(string $candidate): string {
		$trimmed = trim($candidate);
		if ($trimmed === '') {
			return 'OpenRegister';
		}

		return $trimmed;
	}//end displayName()

	/**
	 * List the objects the current principal may read within the scope.
	 *
	 * The RBAC and multi-tenancy flags are explicit and true. They are the
	 * whole access story of this feed: there is no second rule below them.
	 *
	 * @param CalendarFeedToken $token The token.
	 * @param array{name: string, schemaId: string|null, viewId: string|null} $scope The scope.
	 *
	 * @return array<int, mixed> The objects.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function listObjects(CalendarFeedToken $token, array $scope): array {
		unset($token);

		$query = ['_limit' => self::MAX_OBJECTS];
		$views = null;

		if ($scope['viewId'] !== null) {
			$views = [$scope['viewId']];
		}

		if ($views === null) {
			$query['@self'] = ['schema' => $scope['schemaId']];
		}

		$results = $this->sources->objects->searchObjects(
			query: $query,
			_rbac: true,
			_multitenancy: true,
			options: new SearchOptions(views: $views)
		);

		if (is_array($results) === false) {
			return [];
		}

		return $results;
	}//end listObjects()

	/**
	 * Whether an object may appear in a feed at all.
	 *
	 * A deleted or archived object leaves the feed at the next refresh, which
	 * is the whole point of generating on read.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when the object publishes.
	 */
	private function isPublishable(ObjectEntity $object): bool {
		$deleted = $object->getDeleted();

		return (is_array($deleted) === false || $deleted === []);
	}//end isPublishable()

	/**
	 * The date declarations of the schema an object belongs to.
	 *
	 * Memoised per schema for the life of the request: a feed of a thousand
	 * objects over four schemas should read four schemas.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, ObjectDateDeclaration> The declarations, possibly empty.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function declarationsFor(ObjectEntity $object): array {
		$schemaId = (string)$object->getSchema();

		if (array_key_exists($schemaId, $this->declarationCache) === true) {
			return $this->declarationCache[$schemaId];
		}

		$this->declarationCache[$schemaId] = [];

		try {
			$schema = $this->sources->schemas->find($schemaId);
			$this->declarationCache[$schemaId] = $this->declarationsOn(schema: $schema);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[ObjectCalendarFeedService] Could not read date declarations for schema '
				. $schemaId . ': ' . $failure->getMessage()
			);
		}

		return $this->declarationCache[$schemaId];
	}//end declarationsFor()

	/**
	 * The declarations a schema carries, or none when it is not calendar-enabled.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, ObjectDateDeclaration> The declarations.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectDateDeclaration::allFromConfig
	 * is a named constructor; a factory for one parse would add a class to hide a
	 * static.
	 */
	private function declarationsOn(Schema $schema): array {
		$config = $schema->getCalendarProviderConfig();

		if ($config === null) {
			return [];
		}

		return ObjectDateDeclaration::allFromConfig(calendarConfig: $config);
	}//end declarationsOn()

	/**
	 * The time zone a timed event publishes in.
	 *
	 * @return DateTimeZone The tenant zone, falling back to UTC.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function tenantTimeZone(): DateTimeZone {
		try {
			return $this->rendering->dateTimeZone->getTimeZone();
		} catch (Throwable $failure) {
			unset($failure);
			return new DateTimeZone('UTC');
		}
	}//end tenantTimeZone()
}//end class
