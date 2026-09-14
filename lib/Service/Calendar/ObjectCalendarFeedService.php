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
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTimeZone;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IDateTimeZone;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The read-only calendar feed over object dates.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
	 * Constructor.
	 *
	 * @param ObjectService $objects The object search path, RBAC included.
	 * @param SchemaMapper $schemas The schema store.
	 * @param ViewMapper $views The saved-view store.
	 * @param IUserManager $userManager Resolves the principal a token names.
	 * @param IDateTimeZone $dateTimeZone The tenant time zone.
	 * @param ObjectDateEventBuilder $eventBuilder Builds one VEVENT per declared date.
	 * @param IcalendarWriter $writer Folds and assembles the body.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly SchemaMapper $schemas,
		private readonly ViewMapper $views,
		private readonly IUserManager $userManager,
		private readonly IDateTimeZone $dateTimeZone,
		private readonly ObjectDateEventBuilder $eventBuilder,
		private readonly IcalendarWriter $writer,
		private readonly LoggerInterface $logger,
	) {

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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function render(CalendarFeedToken $token): ?string {
		$user = $this->userManager->get((string)$token->getUserId());

		if ($user === null) {
			// An unresolvable principal answers nothing, never the register.
			$this->logger->warning(
				'[ObjectCalendarFeedService] Feed token names an unknown principal; answering nothing.'
			);
			return null;
		}

		try {
			return $this->objects->runAs(
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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	private function renderAsCurrentUser(CalendarFeedToken $token): ?string {
		$scope = $this->resolveScope(token: $token);
		if ($scope === null) {
			return null;
		}

		$timeZone = $this->tenantTimeZone();
		$objects = $this->listObjects(token: $token, scope: $scope);

		$lines = [];
		$years = [(int)date('Y')];

		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity === false || $this->isPublishable(object: $object) === false) {
				continue;
			}

			foreach ($this->declarationsFor(object: $object) as $declaration) {
				$event = $this->eventBuilder->build(
					object: $object,
					schemaId: (string)$object->getSchema(),
					declaration: $declaration,
					timeZone: $timeZone
				);

				if ($event === null) {
					continue;
				}

				$lines = array_merge($lines, $event['lines']);
				$years = array_merge($years, $event['years']);
			}
		}

		return $this->writer->calendar(
			calendarName: $scope['name'],
			timeZone: $timeZone,
			componentLines: $lines,
			fromYear: min($years),
			toYear: max($years)
		);
	}//end renderAsCurrentUser()

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
				$view = $this->views->find($scopeId);
				return [
					'name' => ((string)$view->getName() !== '' ? (string)$view->getName() : 'OpenRegister'),
					'schemaId' => null,
					'viewId' => $scopeId,
				];
			}

			$schema = $this->schemas->find($scopeId);
			return [
				'name' => ((string)$schema->getTitle() !== '' ? (string)$schema->getTitle() : 'OpenRegister'),
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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	private function listObjects(CalendarFeedToken $token, array $scope): array {
		unset($token);

		$query = ['_limit' => self::MAX_OBJECTS];
		$views = null;

		if ($scope['viewId'] !== null) {
			$views = [$scope['viewId']];
		} else {
			$query['@self'] = ['schema' => $scope['schemaId']];
		}

		$results = $this->objects->searchObjects(
			query: $query,
			_rbac: true,
			_multitenancy: true,
			views: $views
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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	private function declarationsFor(ObjectEntity $object): array {
		$schemaId = (string)$object->getSchema();

		if (array_key_exists($schemaId, $this->declarationCache) === true) {
			return $this->declarationCache[$schemaId];
		}

		$this->declarationCache[$schemaId] = [];

		try {
			$schema = $this->schemas->find($schemaId);
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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	private function tenantTimeZone(): DateTimeZone {
		try {
			return $this->dateTimeZone->getTimeZone();
		} catch (Throwable $failure) {
			unset($failure);
			return new DateTimeZone('UTC');
		}
	}//end tenantTimeZone()
}//end class
