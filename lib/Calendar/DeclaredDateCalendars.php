<?php

/**
 * DeclaredDateCalendars — the platform calendars of declared object dates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedSources;
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;
use OCA\OpenRegister\Service\Calendar\ObjectDateEvents;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Joins the subscribable feed to the platform calendar provider.
 *
 * A calendar-enabled schema that declares date kinds, and every saved view
 * the principal may see that is presented as a calendar, becomes a platform
 * calendar. Its events are asked of the feed's own generator, as the
 * principal, through the object access path: the same events, on the same
 * dates, with the same alarms and the term engine's working-day date, and no
 * second access rule. A schema that declares no date kinds is not touched
 * here and keeps the virtual calendar it has today.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
 */
class DeclaredDateCalendars {

	/**
	 * The colour of a calendar that configures none.
	 *
	 * @var string
	 */
	public const DEFAULT_COLOR = '#0082C9';

	/**
	 * The key prefix of a saved-view calendar.
	 *
	 * @var string
	 */
	public const VIEW_KEY_PREFIX = 'openregister-view-';

	/**
	 * The key prefix of a schema calendar, shared with the virtual calendar it replaces.
	 *
	 * @var string
	 */
	public const SCHEMA_KEY_PREFIX = 'openregister-schema-';

	/**
	 * Constructor.
	 *
	 * @param ObjectCalendarFeedService $feed    The one event generator, shared with the feed.
	 * @param ObjectCalendarFeedSources $sources The object access path, the view store and the user lookup.
	 * @param VeventSearchShape         $shape   Reads a generated VEVENT as a search result.
	 * @param LoggerInterface           $logger  Logs what could not be read.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function __construct(
		private readonly ObjectCalendarFeedService $feed,
		private readonly ObjectCalendarFeedSources $sources,
		private readonly VeventSearchShape $shape,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a calendar-enabled schema declares date kinds, and so renders through the feed's generator.
	 *
	 * A declaration that cannot be read is logged and answers false: the
	 * schema then keeps the virtual calendar it has today rather than losing
	 * its calendar.
	 *
	 * @param Schema               $schema The schema.
	 * @param array<string, mixed> $config Its calendar provider configuration.
	 *
	 * @return bool True when the schema declares at least one date kind.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectDateDeclaration::allFromConfig is the declaration's named constructor.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function declaresDates(Schema $schema, array $config): bool {
		try {
			return ObjectDateDeclaration::allFromConfig(calendarConfig: $config) !== [];
		} catch (Throwable $unreadable) {
			$this->logger->warning(
				'[DeclaredDateCalendars] Schema ' . $schema->getId() . ' declares unreadable date kinds; keeping its virtual calendar: '
				. $unreadable->getMessage()
			);
			return false;
		}
	}//end declaresDates()

	/**
	 * The platform calendar of a schema that declares date kinds.
	 *
	 * @param Schema               $schema       The schema.
	 * @param array<string, mixed> $config       Its calendar provider configuration.
	 * @param string               $principalUri The principal.
	 *
	 * @return ObjectDateCalendar The calendar.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function schemaCalendar(Schema $schema, array $config, string $principalUri): ObjectDateCalendar {
		return new ObjectDateCalendar(
			scope: new ObjectDateCalendarScope(
				key: self::SCHEMA_KEY_PREFIX . $schema->getId(),
				displayName: $this->nameOr(candidate: ($config['displayName'] ?? $schema->getTitle())),
				color: $this->colorOr(candidate: ($config['color'] ?? null)),
				scopeType: CalendarFeedToken::SCOPE_SCHEMA,
				scopeId: (string)$schema->getId(),
				principalUri: $principalUri
			),
			source: $this
		);
	}//end schemaCalendar()

	/**
	 * The calendars of the saved views the principal may see that are presented as a calendar.
	 *
	 * The views are listed as the principal, so the view store's own read
	 * and organisation checks decide; a failure is logged and answers none.
	 *
	 * @param string $principalUri The principal.
	 *
	 * @return array<int, ObjectDateCalendar> The calendars.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function viewCalendars(string $principalUri): array {
		$user = $this->principalUser(principalUri: $principalUri);
		if ($user === null) {
			return [];
		}

		$calendars = [];
		foreach ($this->visibleViews(user: $user) as $view) {
			if ($view instanceof View === false || $this->isCalendarView(view: $view) === false) {
				continue;
			}

			$calendars[] = $this->viewCalendar(view: $view, principalUri: $principalUri);
		}

		return $calendars;
	}//end viewCalendars()

	/**
	 * The search results of one calendar, generated for its principal.
	 *
	 * An unknown principal, a scope that no longer resolves and a refused
	 * read all answer no events, never somebody else's.
	 *
	 * @param ObjectDateCalendarScope $scope The calendar.
	 * @param CalendarSearchQuery     $query The pattern, timerange and paging.
	 *
	 * @return array<int, array<string, mixed>> The search results.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-the-platform-calendar-is-read-only-and-resolved-per-principal-req-pcb-002
	 */
	public function searchResults(ObjectDateCalendarScope $scope, CalendarSearchQuery $query): array {
		$user = $this->principalUser(principalUri: $scope->principalUri);
		if ($user === null) {
			return [];
		}

		$events = $this->feed->eventsFor(user: $user, scopeType: $scope->scopeType, scopeId: $scope->scopeId);
		if ($events === null) {
			return [];
		}

		$results = $this->matchingResults(events: $events, key: $scope->key, query: $query);

		return array_values(array_slice($results, (int)($query->offset ?? 0), $query->limit));
	}//end searchResults()

	/**
	 * The generated events as search results that match the pattern and the timerange.
	 *
	 * @param ObjectDateEvents    $events The generated events.
	 * @param string              $key    The calendar key.
	 * @param CalendarSearchQuery $query  The search.
	 *
	 * @return array<int, array<string, mixed>> The results.
	 */
	private function matchingResults(ObjectDateEvents $events, string $key, CalendarSearchQuery $query): array {
		$results = [];
		foreach ($events->events as $event) {
			$result = $this->shape->toSearchResult(lines: $event['lines'], calendarKey: $key);
			if ($result === null
				|| $this->matchesPattern(vevent: $result['objects'][0], pattern: $query->pattern) === false
				|| $this->withinTimerange(vevent: $result['objects'][0], events: $events, options: $query->options) === false
			) {
				continue;
			}

			$results[] = $result;
		}

		return $results;
	}//end matchingResults()

	/**
	 * Whether an event's summary contains the pattern ('' matches every event).
	 *
	 * @param array<string, mixed> $vevent  The VEVENT properties.
	 * @param string               $pattern The pattern.
	 *
	 * @return bool True on a match.
	 */
	private function matchesPattern(array $vevent, string $pattern): bool {
		if ($pattern === '') {
			return true;
		}

		$summary = ($vevent['SUMMARY'][0] ?? '');

		return is_string($summary) === true && stripos($summary, $pattern) !== false;
	}//end matchesPattern()

	/**
	 * Whether an event overlaps the search timerange (every event does when none is given).
	 *
	 * @param array<string, mixed> $vevent  The VEVENT properties.
	 * @param ObjectDateEvents     $events  The generated events, for their zone.
	 * @param array<string, mixed> $options The search options.
	 *
	 * @return bool True when the event is in range.
	 */
	private function withinTimerange(array $vevent, ObjectDateEvents $events, array $options): bool {
		$timerange = ($options['timerange'] ?? null);
		if (is_array($timerange) === false || $timerange === []) {
			return true;
		}

		$span = $this->shape->span(vevent: $vevent, timeZone: $events->timeZone);
		if ($span === null) {
			return false;
		}

		$from  = $this->bound(value: ($timerange['start'] ?? null));
		$until = $this->bound(value: ($timerange['end'] ?? null));

		return ($from === null || $span['end'] >= $from) && ($until === null || $span['start'] <= $until);
	}//end withinTimerange()

	/**
	 * A timerange bound as an instant, or null when absent or unreadable.
	 *
	 * @param mixed $value The bound.
	 *
	 * @return DateTimeImmutable|null The instant.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface is the PHP conversion.
	 */
	private function bound(mixed $value): ?DateTimeImmutable {
		if ($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value);
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $unreadable) {
			unset($unreadable);
			return null;
		}
	}//end bound()

	/**
	 * The views the principal may see, listed as the principal; none (logged) on a failure.
	 *
	 * @param IUser $user The principal.
	 *
	 * @return array<int, mixed> The views.
	 */
	private function visibleViews(IUser $user): array {
		try {
			$views = $this->sources->objects->runAs(
				$user,
				fn () => $this->sources->views->findAll($user->getUID())
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[DeclaredDateCalendars] Could not list saved views: ' . $failure->getMessage(),
				['exception' => $failure]
			);
			return [];
		}

		if (is_array($views) === false) {
			return [];
		}

		return $views;
	}//end visibleViews()

	/**
	 * Whether a saved view is presented as a calendar.
	 *
	 * @param View $view The view.
	 *
	 * @return bool True for a calendar view.
	 */
	private function isCalendarView(View $view): bool {
		$presentation = $view->getPresentation();

		return is_array($presentation) === true && ($presentation['viewType'] ?? null) === 'calendar';
	}//end isCalendarView()

	/**
	 * The platform calendar of one saved view.
	 *
	 * @param View   $view         The view.
	 * @param string $principalUri The principal.
	 *
	 * @return ObjectDateCalendar The calendar.
	 */
	private function viewCalendar(View $view, string $principalUri): ObjectDateCalendar {
		$presentation = (array)$view->getPresentation();
		$calendar     = ($presentation['calendar'] ?? []);
		$color        = null;
		if (is_array($calendar) === true) {
			$color = ($calendar['color'] ?? null);
		}

		return new ObjectDateCalendar(
			scope: new ObjectDateCalendarScope(
				key: self::VIEW_KEY_PREFIX . $view->getId(),
				displayName: $this->nameOr(candidate: $view->getName()),
				color: $this->colorOr(candidate: $color),
				scopeType: CalendarFeedToken::SCOPE_VIEW,
				scopeId: (string)$view->getId(),
				principalUri: $principalUri
			),
			source: $this
		);
	}//end viewCalendar()

	/**
	 * The user a principal URI names, or null when it names no known user.
	 *
	 * @param string $principalUri The principal URI.
	 *
	 * @return IUser|null The user.
	 */
	private function principalUser(string $principalUri): ?IUser {
		if (preg_match('/^principals\/users\/(.+)$/', $principalUri, $matches) !== 1) {
			return null;
		}

		return $this->sources->userManager->get($matches[1]);
	}//end principalUser()

	/**
	 * A display name, never blank.
	 *
	 * @param mixed $candidate The configured or stored name.
	 *
	 * @return string The name.
	 */
	private function nameOr(mixed $candidate): string {
		if (is_string($candidate) === true && trim($candidate) !== '') {
			return trim($candidate);
		}

		return 'OpenRegister';
	}//end nameOr()

	/**
	 * A configured colour, or the default.
	 *
	 * @param mixed $candidate The configured colour.
	 *
	 * @return string The colour.
	 */
	private function colorOr(mixed $candidate): string {
		if (is_string($candidate) === true && $candidate !== '') {
			return $candidate;
		}

		return self::DEFAULT_COLOR;
	}//end colorOr()
}//end class
