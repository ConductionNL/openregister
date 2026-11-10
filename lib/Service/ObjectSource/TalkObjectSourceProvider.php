<?php

/**
 * TalkObjectSourceProvider — serves the `nc-conversation` virtual schema's objects
 * live from the acting user's Nextcloud Talk (spreed) conversations (read-only).
 *
 * The authoritative record is the Talk room held by the spreed app; this provider
 * projects each conversation the acting user is a participant of as a virtual
 * ObjectEntity (uuid = room token; object = {id, name, displayName, type,
 * participantCount, lastActivity}) and never writes back. Talk's own `Manager`
 * runs `getRoomsForUser()` scoped to the acting user's rooms, so the projection is
 * inherently user-scoped (denied == absent, no enumeration oracle) — mirroring the
 * scoping approach of the sibling object-source providers.
 *
 * Talk lives in ANOTHER app's namespace, so all access is gated behind
 * `class_exists` + a guarded container lookup (the defensive style of the CalDAV
 * and Deck object-source providers): when Talk is absent or its service classes
 * cannot be resolved, the projection degrades to an empty list with a logged
 * warning rather than erroring.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only object-source provider backed by the Nextcloud Talk (spreed) app.
 */
class TalkObjectSourceProvider implements ObjectSourceProvider {

	use CountsThroughFindAll;
	use ReadsRowsById;
	use InstalledAppCheck;
	use ContainerServiceLookup;
	use DefensiveGetters;

	/**
	 * NC app id whose install-state gates this provider.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'spreed';

	/**
	 * Label the warnings of this provider carry.
	 *
	 * @var string
	 */
	private const SOURCE_LABEL = 'talk-source';

	/**
	 * Talk's room manager (resolved dynamically — Talk's namespace).
	 *
	 * @var string
	 */
	private const MANAGER = 'OCA\\Talk\\Manager';

	/**
	 * Talk's participant service (resolved dynamically — Talk's namespace).
	 *
	 * @var string
	 */
	private const PARTICIPANT_SERVICE = 'OCA\\Talk\\Service\\ParticipantService';

	/**
	 * App availability checks.
	 *
	 * @var IAppManager
	 */
	private readonly IAppManager $appManager;

	/**
	 * Server container (lazy app-service lookup).
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * Logger for read failures.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param OptionalAppBridge $app         Whether the app is installed, its services, and the log of failed reads.
	 * @param IUserSession      $userSession The acting-user session.
	 *
	 * @return void
	 */
	public function __construct(
		OptionalAppBridge $app,
		private readonly IUserSession $userSession,
	) {
		$this->appManager = $app->appManager;
		$this->container = $app->container;
		$this->logger = $app->logger;
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider id.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function getId(): string {
		return 'talk-source';
	}//end getId()

	/**
	 * Read the Talk rooms the acting user participates in, failing closed to an
	 * empty list.
	 *
	 * Every access is guarded so an absent Talk app or a per-room read failure
	 * degrades to an empty/partial list rather than erroring.
	 *
	 * @return array<int, array<string, mixed>> The normalised room arrays.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	protected function readRows(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$rooms = $this->listRooms(userId: $user->getUID());
		if ($rooms === null) {
			return [];
		}

		return $this->normaliseRooms(rooms: $rooms, userId: $user->getUID());
	}//end readRows()

	/**
	 * The rooms of a user, or null when Talk's manager is missing or throws.
	 *
	 * @param string $userId The acting user's uid.
	 *
	 * @return mixed The rooms (whatever Manager::getRoomsForUser() returns), or null.
	 */
	private function listRooms(string $userId): mixed {
		$manager = $this->resolveService(class: self::MANAGER);
		if ($manager === null) {
			return null;
		}

		try {
			return $manager->getRoomsForUser($userId);
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:talk-source] could not list rooms: ' . $e->getMessage());
			return null;
		}
	}//end listRooms()

	/**
	 * Normalise the object entries of a room list.
	 *
	 * @param mixed  $rooms  The rooms.
	 * @param string $userId The acting user's uid.
	 *
	 * @return array<int, array<string, mixed>> The normalised rooms.
	 */
	private function normaliseRooms(mixed $rooms, string $userId): array {
		$viewer = new TalkRoomViewer(userId: $userId, participantService: $this->resolveService(class: self::PARTICIPANT_SERVICE));

		$result = [];
		foreach ($rooms as $room) {
			if (is_object($room) === true) {
				$result[] = $this->normaliseRoom(room: $room, viewer: $viewer);
			}
		}

		return $result;
	}//end normaliseRooms()

	/**
	 * Normalise a single Talk room entity into a flat array.
	 *
	 * @param object         $room   The Talk room entity.
	 * @param TalkRoomViewer $viewer The acting user and Talk's participant service.
	 *
	 * @return array<string, mixed> The normalised room array.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function normaliseRoom(object $room, TalkRoomViewer $viewer): array {
		return [
			'id' => $this->stringGetter(entity: $room, getter: 'getToken'),
			'name' => $this->stringGetter(entity: $room, getter: 'getName'),
			'displayName' => $this->displayName(room: $room, userId: $viewer->userId),
			'type' => $this->intGetter(entity: $room, getter: 'getType'),
			'participantCount' => $this->participantCount(room: $room, participantService: $viewer->participantService),
			'lastActivity' => $this->lastActivity(room: $room),
		];
	}//end normaliseRoom()

	/**
	 * Resolve the acting-user display name for a room, defaulting to the room
	 * name when the per-user getter is unavailable.
	 *
	 * @param object $room The Talk room entity.
	 * @param string $userId The acting user's uid.
	 *
	 * @return string The display name, or ''.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function displayName(object $room, string $userId): string {
		try {
			return (string)$room->getDisplayName($userId);
		} catch (Throwable $e) {
			return $this->stringGetter(entity: $room, getter: 'getName');
		}
	}//end displayName()

	/**
	 * Resolve the participant count for a room, defaulting to 0 when the
	 * participant service is unavailable.
	 *
	 * @param object $room The Talk room entity.
	 * @param object|null $participantService Talk's participant service (may be null).
	 *
	 * @return int The participant count.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function participantCount(object $room, ?object $participantService): int {
		if ($participantService === null) {
			return 0;
		}

		try {
			return (int)$participantService->getNumberOfActors($room);
		} catch (Throwable $e) {
			return 0;
		}
	}//end participantCount()

	/**
	 * Resolve a room's last-activity timestamp as an ISO-8601 string, or null.
	 *
	 * @param object $room The Talk room entity.
	 *
	 * @return string|null The ISO-8601 last activity, or null.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	private function lastActivity(object $room): ?string {
		try {
			$activity = $room->getLastActivity();
			if ($activity instanceof \DateTimeInterface) {
				return $activity->format(\DateTime::ATOM);
			}
		} catch (Throwable $e) {
			// Getter missing on this Talk version — leave null.
			$this->logger->debug(
				message: '[TalkObjectSourceProvider] Room last activity not readable',
				context: [
					'exception' => $e->getMessage(),
				]
			);
		}

		return null;
	}//end lastActivity()
}//end class
