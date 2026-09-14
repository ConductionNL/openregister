<?php

/**
 * The subscribable calendar feed and its tokens.
 *
 * One public endpoint answers `text/calendar` for a token, and three
 * authenticated endpoints let a principal mint, list and revoke their own
 * tokens. The public endpoint is public in the Nextcloud sense only: it
 * carries no session, so it is reachable without one, but the calendar it
 * answers is generated as the principal the token names and contains exactly
 * what that principal may list.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService;
use OCA\OpenRegister\Service\Calendar\CalendarFeedTokenService;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * Calendar feed controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CalendarFeedController extends Controller {

	/**
	 * Brute-force throttler action for rejected feed tokens.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'openregister_calendar_feed';

	/**
	 * Constructor.
	 *
	 * @param string $appName App name (injected by Nextcloud).
	 * @param IRequest $request Current request.
	 * @param CalendarFeedTokenService $tokens The feed-token lifecycle.
	 * @param ObjectCalendarFeedService $feed The feed generator.
	 * @param AppointmentAttendeeService $attendees The attendee-response store.
	 * @param IThrottler $throttler Brute-force throttler for rejected tokens.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CalendarFeedTokenService $tokens,
		private readonly ObjectCalendarFeedService $feed,
		private readonly AppointmentAttendeeService $attendees,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/public/calendar-feeds/{token}.ics
	 *
	 * Answers the calendar for a live token, generated on read as the
	 * principal the token names. An unknown, revoked or expired token gets the
	 * same 404: distinguishing them would make the endpoint an enumeration
	 * oracle, and a reduced calendar would be believed.
	 *
	 * @param string $token The opaque feed token.
	 *
	 * @return DataDisplayResponse|JSONResponse The calendar, or 404.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function feed(string $token): DataDisplayResponse | JSONResponse {
		$resolved = $this->tokens->resolve(token: $token);
		$body = ($resolved === null ? null : $this->feed->render(token: $resolved));

		if ($resolved === null || $body === null) {
			$this->registerRejectedAttempt();

			return new JSONResponse(['message' => 'Not Found'], Http::STATUS_NOT_FOUND);
		}

		$this->tokens->noteRead(token: $resolved);

		return new DataDisplayResponse(
			$body,
			Http::STATUS_OK,
			[
				'Content-Type' => 'text/calendar; charset=UTF-8',
				'Content-Disposition' => 'inline; filename="openregister.ics"',
				'Cache-Control' => 'no-store, private',
			]
		);
	}//end feed()

	/**
	 * POST /api/calendar-feeds
	 *
	 * Mint a feed token for the calling principal over one schema or one
	 * saved view.
	 *
	 * @return JSONResponse The minted token and its URL, or 400.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	#[NoAdminRequired]
	public function mint(): JSONResponse {
		try {
			$minted = $this->tokens->mint(
				scopeType: (string)$this->request->getParam('scopeType', ''),
				scopeId: (string)$this->request->getParam('scopeId', ''),
				label: $this->stringParam(name: 'label'),
				ttlSeconds: $this->intParam(name: 'ttlSeconds')
			);
		} catch (InvalidArgumentException $refused) {
			return new JSONResponse(['message' => $refused->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($minted, Http::STATUS_CREATED);
	}//end mint()

	/**
	 * GET /api/calendar-feeds
	 *
	 * Every feed token the calling principal owns.
	 *
	 * @return JSONResponse The tokens.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse(['results' => $this->tokens->listForCurrentUser()]);
	}//end index()

	/**
	 * DELETE /api/calendar-feeds/{id}
	 *
	 * Revoke one of the calling principal's own tokens. A token owned by
	 * somebody else is, to this caller, a token that does not exist.
	 *
	 * @param int $id The token row id.
	 *
	 * @return JSONResponse Empty on success, 404 when there is nothing to revoke.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	#[NoAdminRequired]
	public function revoke(int $id): JSONResponse {
		if ($this->tokens->revoke(id: $id) === false) {
			return new JSONResponse(['message' => 'Not Found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([], Http::STATUS_OK);
	}//end revoke()

	/**
	 * GET /api/objects/{id}/attendee-responses
	 *
	 * The attendance recorded on an object, with responder and time.
	 *
	 * @param string $id The object uuid.
	 *
	 * @return JSONResponse The responses, or 404 when the object is unknown.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	#[NoAdminRequired]
	public function attendeeResponses(string $id): JSONResponse {
		try {
			return new JSONResponse(['results' => $this->attendees->responsesFor(objectUuid: $id)]);
		} catch (InvalidArgumentException $unknown) {
			return new JSONResponse(['message' => $unknown->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}//end attendeeResponses()

	/**
	 * POST /api/objects/{id}/attendee-responses
	 *
	 * Record one invitee's answer on the object. The write goes through the
	 * ordinary object save path, so a caller who may not write the object
	 * cannot record an answer on it.
	 *
	 * @param string $id The object uuid.
	 *
	 * @return JSONResponse The full response list, or 400.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	#[NoAdminRequired]
	public function recordAttendeeResponse(string $id): JSONResponse {
		try {
			$responses = $this->attendees->recordResponse(
				objectUuid: $id,
				attendee: (string)$this->request->getParam('attendee', ''),
				status: (string)$this->request->getParam('status', ''),
				respondedBy: $this->stringParam(name: 'respondedBy')
			);
		} catch (InvalidArgumentException $refused) {
			return new JSONResponse(['message' => $refused->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['results' => $responses], Http::STATUS_OK);
	}//end recordAttendeeResponse()

	/**
	 * Note a rejected token against the throttler.
	 *
	 * A uniform 404 hides WHICH failure occurred; it does nothing about how
	 * fast the next guess can be attempted.
	 *
	 * @return void
	 */
	private function registerRejectedAttempt(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (\Throwable $throttlerFailure) {
			$this->logger->warning(
				'CalendarFeedController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}//end registerRejectedAttempt()

	/**
	 * A trimmed non-empty request parameter, or null.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value, or null.
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name, null);

		if (is_string($value) === false) {
			return null;
		}

		$trimmed = trim($value);

		return ($trimmed === '' ? null : $trimmed);
	}//end stringParam()

	/**
	 * A positive integer request parameter, or null.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return int|null The value, or null.
	 */
	private function intParam(string $name): ?int {
		$value = $this->request->getParam($name, null);

		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end intParam()
}//end class
