<?php

/**
 * TalkLinkService — Tier-2 talk integration service.
 *
 * Composes the {@see TalkLinkMapper} with Nextcloud Talk's internal
 * `Manager` / `RoomService` / `ParticipantService` to provide the
 * picker + inline-create UX surface area:
 *
 *   - linkRoom(uuid, registerId, schemaId, roomToken)
 *   - createAndLinkRoom(uuid, ..., name, ?description, type=2)
 *   - unlinkRoom(uuid, roomToken)
 *   - getLinkedRooms(uuid)              — refreshes stale cache (>5min)
 *   - getAvailableRoomsForUser(?search)
 *
 * Talk is reached via the server container ("OCA\\Talk\\Manager") with
 * graceful degradation: when Talk is unavailable or a Manager call
 * throws, the stored link row is returned as-is so historical
 * references survive even after Talk is uninstalled (ADR-019 AD-23).
 *
 * Linking convention preserved: the Tier-1 {@see TalkProvider} relied
 * on a `[or:{uuid}]` marker substring in the room name. Tier-2 uses
 * the explicit link table — but `createAndLinkRoom` still tags the
 * description with the marker so the legacy reverse-marker code path
 * stays consistent until that lookup is removed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapperInterface;
use OCA\OpenRegister\Service\TalkLink\ExternalInvitation;
use OCA\OpenRegister\Service\TalkLink\LinkedObject;
use OCA\OpenRegister\Service\TalkLink\RoomCreation;
use OCA\OpenRegister\Service\TalkLink\RoomPickerQuery;
use OCA\OpenRegister\Service\TalkLink\RoomRefreshRun;
use OCA\OpenRegister\Service\TalkLink\TalkGateway;
use OCA\OpenRegister\Service\TalkLink\TalkLinkFactory;
use OCA\OpenRegister\Service\TalkLink\TalkRoomFields;
use OCA\OpenRegister\Service\TalkLink\TalkSession;
use OCA\OpenRegister\Service\TalkLink\TalkViewer;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * TalkLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     Talk's Manager/RoomService/ParticipantService + user session +
 *     l10n + container. Each dependency is required for one of the
 *     Tier-2 flows (link, create, refresh, subtitle).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Defensive
 *     method_exists + try/catch guards around every getter on Talk's
 *     Room entity (which uses Entity::__call magic) inflate the
 *     cyclomatic score; splitting into helper classes would scatter
 *     the leaf-row contract.
 *
 * @spec openspec/specs/integration-talk/spec.md
 */
class TalkLinkService implements TalkLinkServiceInterface {
	private const ROOM_TAG = '[or:';
	private const STALE_AFTER = 300;
	// 5 minutes in seconds.

	/**
	 * Late-bound Talk services.
	 *
	 * @var TalkGateway
	 */
	private readonly TalkGateway $talk;

	/**
	 * Reads Talk Room entities.
	 *
	 * @var TalkRoomFields
	 */
	private readonly TalkRoomFields $roomFields;

	/**
	 * Populates link rows.
	 *
	 * @var TalkLinkFactory
	 */
	private readonly TalkLinkFactory $linkFactory;

	/**
	 * Constructor.
	 *
	 * @param TalkLinkMapperInterface $talkLinkMapper Persistence for link rows.
	 * @param ContainerInterface $container Container for late-bound Talk classes.
	 * @param IL10N $l10n Translation service.
	 * @param SchemaMapperInterface $schemaMapper Resolves the schema an object belongs to (external-participant opt-in check).
	 * @param LinkServiceRuntime $runtime Whether the app is enabled, the current user, and the logger.
	 */
	public function __construct(
		private readonly TalkLinkMapperInterface $talkLinkMapper,
		ContainerInterface $container,
		IL10N $l10n,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LinkServiceRuntime $runtime,
	) {
		$this->talk = new TalkGateway(container: $container, runtime: $runtime);
		$this->roomFields = new TalkRoomFields(l10n: $l10n);
		$this->linkFactory = new TalkLinkFactory();
	}//end __construct()

	/**
	 * Whether NC Talk (spreed) is installed + enabled for the current
	 * user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/integration-talk/spec.md
	 */
	public function isTalkAvailable(): bool {
		return $this->talk->isAvailable();
	}//end isTalkAvailable()

	/**
	 * Link an existing Talk room to an OR object.
	 *
	 * @param LinkedObject $object    The object to link the room to.
	 * @param string       $roomToken Talk room token.
	 *
	 * @return TalkLink The inserted link row.
	 *
	 * @throws Exception On 401/404/409/503.
	 *
	 * @spec openspec/specs/integration-talk/spec.md
	 */
	public function linkRoom(LinkedObject $object, string $roomToken): TalkLink {
		$userUid = $this->requireUserUid();
		if ($this->talkLinkMapper->findByObjectAndRoom($object->objectUuid, $roomToken) !== null) {
			throw new Exception('Room already linked to this object', 409);
		}

		$room = $this->requireRoom(roomToken: $roomToken, userUid: $userUid);
		$link = $this->linkFactory->newLink(object: $object->fields(), roomToken: $roomToken);
		$this->linkFactory->applyCache(link: $link, cache: $this->extractRoomFields(room: $room, userUid: $userUid));

		return $this->talkLinkMapper->insert($this->linkFactory->linkedBy(link: $link, userUid: $userUid));
	}//end linkRoom()

	/**
	 * The UID of the session user.
	 *
	 * @return string The user id.
	 *
	 * @throws Exception When no user is logged in.
	 */
	private function requireUserUid(): string {
		$user = $this->runtime->userSession()->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		return $user->getUID();
	}//end requireUserUid()

	/**
	 * Talk's Manager, or a 503 when Talk is unavailable.
	 *
	 * @return object Talk Manager.
	 *
	 * @throws Exception When Talk is unavailable (503).
	 */
	private function requireManager(): object {
		$manager = $this->talk->manager();
		if ($manager === null) {
			throw new Exception('Talk is not available', 503);
		}

		return $manager;
	}//end requireManager()

	/**
	 * The Talk room with this token, as the user sees it.
	 *
	 * @param string $roomToken Talk room token.
	 * @param string $userUid   User UID.
	 *
	 * @return object Talk Room.
	 *
	 * @throws Exception When Talk is unavailable (503) or the room is not found (404).
	 */
	private function requireRoom(string $roomToken, string $userUid): object {
		$room = $this->talk->findRoom(session: new TalkSession(manager: $this->requireManager(), userUid: $userUid), roomToken: $roomToken);
		if ($room === null) {
			throw new Exception('Talk room not found', 404);
		}

		return $room;
	}//end requireRoom()

	/**
	 * Unlink a Talk room from an object. Does NOT destroy the room
	 * itself — it remains in Talk for other linked objects (or the
	 * users who joined it directly).
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param string $roomToken Talk room token.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function unlinkRoom(string $objectUuid, string $roomToken): void {
		$deleted = $this->talkLinkMapper->deleteByObjectAndRoom($objectUuid, $roomToken);
		if ($deleted === 0) {
			throw new Exception('Talk link not found', 404);
		}
	}//end unlinkRoom()

	/**
	 * Invite an external (non-Nextcloud-user) participant to a linked Talk
	 * room by email.
	 *
	 * Reuses `ParticipantService::addUsers()` — the same call
	 * `createAndLinkRoom()` already makes for `actorType: 'users'` — with
	 * `actorType: 'emails'` instead, rather than inventing a second Talk API
	 * surface. Gated on the object's schema declaring
	 * `x-openregister-talk-participants: true` in its configuration: staff
	 * and learners (ordinary Nextcloud accounts, added through Talk's own
	 * UI or {@see linkRoom()}) are entirely unaffected either way — this
	 * only widens who this platform endpoint may invite.
	 *
	 * @param ExternalInvitation $invitation Who is invited (email, optional display name) into which linked room.
	 *
	 * @return array<string, mixed> The invite result; degrades to `{invited: false, unavailable: true, cause: ...}`
	 *     (AD-23) when Talk's participant API is unavailable — never throws for that.
	 *
	 * @throws Exception When the room is not linked to the object (404), the
	 *                   object's schema does not opt in (403), the email is
	 *                   malformed (400), or no user is logged in.
	 *
	 * @spec openspec/specs/guardian-participant-messaging-leaf/spec.md
	 * @spec openspec/specs/integration-talk/spec.md
	 */
	public function invite(ExternalInvitation $invitation): array {
		$link = $this->talkLinkMapper->findByObjectAndRoom($invitation->objectUuid, $invitation->roomToken);
		if ($link === null) {
			throw new Exception('Talk link not found', 404);
		}

		$this->assertInviteAllowed(link: $link, email: $invitation->email);

		$user = $this->runtime->userSession()->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$targets = $this->resolveInviteTargets(roomToken: $invitation->roomToken, userUid: $user->getUID());
		if ($targets['degraded'] === true) {
			return ['invited' => false, 'unavailable' => true, 'cause' => $targets['cause']];
		}

		return $this->sendInvite(targets: $targets, invitation: $invitation);
	}//end invite()

	/**
	 * Refuse an invite whose schema has not opted in, or whose email is malformed.
	 *
	 * @param TalkLink $link The resolved link row (carries the schema id).
	 * @param string $email The caller-submitted email address.
	 *
	 * @return void
	 *
	 * @throws Exception When the schema does not opt in (403) or the email is malformed (400).
	 */
	private function assertInviteAllowed(TalkLink $link, string $email): void {
		if ($this->schemaAllowsExternalParticipants(schemaId: (int)$link->getSchemaId()) === false) {
			throw new Exception('This schema does not allow external Talk participants', 403);
		}

		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new Exception('Invalid email address', 400);
		}
	}//end assertInviteAllowed()

	/**
	 * Resolve the Talk room and participant service an invite needs, or a degrade descriptor.
	 *
	 * @param string $roomToken Talk room token.
	 * @param string $userUid Current user id (for room lookup).
	 *
	 * @return array{degraded: bool, cause?: string, room?: object, participantService?: object}
	 */
	private function resolveInviteTargets(string $roomToken, string $userUid): array {
		$room = $this->inviteRoom(roomToken: $roomToken, userUid: $userUid);
		if (is_string($room) === true) {
			return ['degraded' => true, 'cause' => $room];
		}

		$participantService = $this->talk->participantService();
		if ($participantService === null || method_exists($participantService, 'addUsers') === false) {
			return ['degraded' => true, 'cause' => 'participant-service-unavailable'];
		}

		return ['degraded' => false, 'room' => $room, 'participantService' => $participantService];
	}//end resolveInviteTargets()

	/**
	 * The room an invite goes to, or why it cannot be found.
	 *
	 * @param string $roomToken Talk room token.
	 * @param string $userUid   Current user id (for room lookup).
	 *
	 * @return object|string The Talk room, or the degrade cause.
	 */
	private function inviteRoom(string $roomToken, string $userUid): object|string {
		$manager = $this->talk->manager();
		if ($manager === null) {
			return 'talk-not-available';
		}

		$room = $this->talk->findRoom(session: new TalkSession(manager: $manager, userUid: $userUid), roomToken: $roomToken);
		if ($room === null) {
			return 'room-not-found';
		}

		return $room;
	}//end inviteRoom()

	/**
	 * Add the e-mail guest to the room and shape the result.
	 *
	 * @param array<string, mixed> $targets    The resolved room and participant service.
	 * @param ExternalInvitation   $invitation Who is invited.
	 *
	 * @return array<string, mixed> The invite result, or the degraded shape on failure.
	 */
	private function sendInvite(array $targets, ExternalInvitation $invitation): array {
		$email = $invitation->email;
		$resolvedDisplayName = $invitation->displayName;
		if ($resolvedDisplayName === null || $resolvedDisplayName === '') {
			$resolvedDisplayName = $email;
		}

		try {
			$targets['participantService']->addUsers($targets['room'], [
				['actorType' => 'emails', 'actorId' => $email, 'displayName' => $resolvedDisplayName],
			]);
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('Failed to invite external Talk participant: ' . $e->getMessage());
			return ['invited' => false, 'unavailable' => true, 'cause' => $e->getMessage()];
		}

		return ['invited' => true, 'actorType' => 'emails', 'actorId' => $email];
	}//end sendInvite()

	/**
	 * Whether a schema opts into external (non-Nextcloud-user) Talk participants.
	 *
	 * @param int $schemaId The schema id to check.
	 *
	 * @return bool
	 */
	private function schemaAllowsExternalParticipants(int $schemaId): bool {
		try {
			$schema = $this->schemaMapper->find(id: (string)$schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return false;
		}

		$configuration = $schema->getConfiguration();
		if (is_array($configuration) === false) {
			return false;
		}

		return ($configuration['x-openregister-talk-participants'] ?? false) === true;
	}//end schemaAllowsExternalParticipants()

	/**
	 * Return the linked rooms for an object, refreshing cached fields
	 * for rows older than {@see self::STALE_AFTER}.
	 *
	 * Always succeeds: when Talk's `Manager` is missing or `getRoom`
	 * throws for a stale link, the stored row is returned with its
	 * cached fields as-is.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getLinkedRooms(string $objectUuid): array {
		$links = $this->talkLinkMapper->findByObjectUuid($objectUuid);
		$run = $this->refreshRun();

		$results = [];
		foreach ($links as $link) {
			$results[] = $this->linkedRoomRow(link: $link, run: $run);
		}

		return $results;
	}//end getLinkedRooms()

	/**
	 * The Talk manager and current user to refresh stale rows with, or null when either is missing.
	 *
	 * @return RoomRefreshRun|null The run, or null when rows are returned as cached.
	 */
	private function refreshRun(): ?RoomRefreshRun {
		$manager = $this->talk->manager();
		$userUid = $this->runtime->userSession()->getUser()?->getUID();
		if ($manager === null || $userUid === null) {
			return null;
		}

		return new RoomRefreshRun(manager: $manager, userUid: $userUid);
	}//end refreshRun()

	/**
	 * One link row: as cached, or refreshed from Talk when the run allows it.
	 *
	 * @param TalkLink            $link The link row.
	 * @param RoomRefreshRun|null $run  The manager and user, or null when Talk or the user is missing.
	 *
	 * @return array<string, mixed> The serialized row.
	 */
	private function linkedRoomRow(TalkLink $link, ?RoomRefreshRun $run): array {
		if ($run === null) {
			return $link->jsonSerialize();
		}

		return $this->refreshedRow(link: $link, run: $run);
	}//end linkedRoomRow()

	/**
	 * Serialize a link row, refreshing its cached room fields first when stale.
	 *
	 * @param TalkLink       $link The link row.
	 * @param RoomRefreshRun $run  The manager and user to read the room with.
	 *
	 * @return array<string, mixed> The refreshed row, or the cached row when not stale or unreachable.
	 */
	private function refreshedRow(TalkLink $link, RoomRefreshRun $run): array {
		$cachedRow = $link->jsonSerialize();
		if ($this->isStale(link: $link) === false) {
			return $cachedRow;
		}

		try {
			$session = new TalkSession(manager: $run->manager, userUid: $run->userUid);
			$room = $this->talk->findRoom(session: $session, roomToken: $link->getRoomToken());
			if ($room === null) {
				return $cachedRow;
			}

			return $this->cacheRoom(link: $link, cache: $this->extractRoomFields(room: $room, userUid: $run->userUid));
		} catch (Throwable $e) {
			// Stale link — keep cached row as-is.
			$this->runtime->logger()->debug('Stale talk link for room ' . $link->getRoomToken() . ': ' . $e->getMessage());
			return $cachedRow;
		}
	}//end refreshedRow()

	/**
	 * Write freshly read room fields onto the link row and persist it.
	 *
	 * @param TalkLink             $link  The link row.
	 * @param array<string, mixed> $cache The room fields just read from Talk.
	 *
	 * @return array<string, mixed> The serialized, refreshed row.
	 */
	private function cacheRoom(TalkLink $link, array $cache): array {
		$this->linkFactory->applyCache(link: $link, cache: $cache);
		$this->talkLinkMapper->update($link);

		return $link->jsonSerialize();
	}//end cacheRoom()

	/**
	 * Create a fresh Talk room and link it to an object.
	 *
	 * @param RoomCreation $creation The object, room name, description and room type.
	 *
	 * @return TalkLink The inserted link row.
	 *
	 * @throws Exception On 400/401/500/503.
	 *
	 * @spec openspec/specs/integration-talk/spec.md
	 */
	public function createAndLinkRoom(RoomCreation $creation): TalkLink {
		$userUid = $this->requireUserUid();
		if (in_array($creation->roomType, [2, 3], true) === false) {
			throw new Exception('Invalid room type (must be 2=group or 3=public)', 400);
		}

		$room = $this->createTaggedRoom(creation: $creation, userUid: $userUid);
		$this->talk->setDescription(room: $room, description: $creation->description);
		$this->talk->addOwner(room: $room, userUid: $userUid);
		$roomToken = $this->createdRoomToken(room: $room);
		$cache = $this->extractRoomFields(room: $room, userUid: $userUid);
		$cache = $this->withRequestedFallbacks(cache: $cache, creation: $creation);

		$link = $this->linkFactory->newLink(object: $creation->object->fields(), roomToken: $roomToken);
		$this->linkFactory->applyCache(link: $link, cache: $cache);

		return $this->talkLinkMapper->insert($this->linkFactory->linkedBy(link: $link, userUid: $userUid));
	}//end createAndLinkRoom()

	/**
	 * Create the Talk room, tagged with the object uuid.
	 *
	 * @param RoomCreation $creation The object, room name and room type.
	 * @param string       $userUid  The creating user.
	 *
	 * @return object The created room.
	 *
	 * @throws Exception When Talk is missing or the room could not be created.
	 */
	private function createTaggedRoom(RoomCreation $creation, string $userUid): object {
		$manager = $this->requireManager();
		$taggedName = $creation->roomName . ' ' . self::ROOM_TAG . $creation->object->objectUuid . ']';
		try {
			$room = $manager->createRoom($creation->roomType, $taggedName, $userUid);
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('Failed to create Talk room: ' . $e->getMessage());
			throw new Exception('Failed to create Talk room: ' . $e->getMessage(), 500);
		}

		if (is_object($room) === false) {
			throw new Exception('Talk createRoom returned no room', 500);
		}

		return $room;
	}//end createTaggedRoom()

	/**
	 * The token of a room just created.
	 *
	 * @param object $room The new Talk room.
	 *
	 * @return string The token.
	 *
	 * @throws Exception When the room has no token (500).
	 */
	private function createdRoomToken(object $room): string {
		$roomToken = $this->roomFields->token(room: $room);
		if ($roomToken === '') {
			throw new Exception('Talk room has no token after create', 500);
		}

		return $roomToken;
	}//end createdRoomToken()

	/**
	 * Fill the name and type the room did not report with the requested ones.
	 *
	 * @param array<string, mixed> $cache    The room fields read from Talk.
	 * @param RoomCreation         $creation The requested name and type.
	 *
	 * @return array<string, mixed> The fields with fallbacks applied.
	 */
	private function withRequestedFallbacks(array $cache, RoomCreation $creation): array {
		if (in_array($cache['roomName'], [null, ''], true) === true) {
			$cache['roomName'] = $creation->roomName;
		}

		$cache['roomType'] = ($cache['roomType'] ?? $creation->roomType);

		return $cache;
	}//end withRequestedFallbacks()

	/**
	 * Return Talk rooms available to the current user for the picker.
	 *
	 * Each row is `{token, name, type, participantCount}`. The optional
	 * `$search` filter does a case-insensitive substring match on the
	 * room name client-side (Talk's getRoomsForUser doesn't accept a
	 * search param across all versions).
	 *
	 * Returns an empty array when Talk is unavailable or the manager
	 * throws.
	 *
	 * @param string|null $search Optional case-insensitive name filter.
	 *
	 * @return array<int,array{token:string,name:string,type:?int,participantCount:?int}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getAvailableRoomsForUser(?string $search = null): array {
		$userUid = $this->pickerUserUid();
		if ($userUid === null) {
			return [];
		}

		$rooms = $this->roomsForUser(userUid: $userUid);
		if ($rooms === null) {
			return [];
		}

		$viewer = new TalkViewer(userUid: $userUid, participantService: $this->talk->participantService());

		return $this->roomFields->pickerRows(rooms: $rooms, query: new RoomPickerQuery(viewer: $viewer, search: $search));
	}//end getAvailableRoomsForUser()

	/**
	 * The user the picker lists rooms for: none when Talk is unavailable or nobody is logged in.
	 *
	 * @return string|null The user id.
	 */
	private function pickerUserUid(): ?string {
		if ($this->isTalkAvailable() === false) {
			return null;
		}

		return $this->runtime->userSession()->getUser()?->getUID();
	}//end pickerUserUid()

	/**
	 * The user's Talk rooms, or null when Talk cannot list them.
	 *
	 * @param string $userUid The user id.
	 *
	 * @return array|null The rooms.
	 */
	private function roomsForUser(string $userUid): ?array {
		$manager = $this->talk->manager();
		if ($manager === null) {
			return null;
		}

		try {
			$rooms = $manager->getRoomsForUser($userUid, [], true);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Talk Manager::getRoomsForUser failed: ' . $e->getMessage());
			return null;
		}

		return $this->arrayOrNull(value: $rooms);
	}//end roomsForUser()

	/**
	 * The value when it is an array, else null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array|null
	 */
	private function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		return $value;
	}//end arrayOrNull()

	/**
	 * Whether a link row's cache is older than {@see self::STALE_AFTER}.
	 *
	 * Note: we treat `lastActivity` as the cache-age proxy because Talk
	 * updates it on every message; if it's missing we fall back to
	 * `linkedAt`. A row with no cache yet (lastActivity null) is
	 * considered stale.
	 *
	 * @param TalkLink $link The link row.
	 *
	 * @return bool
	 */
	private function isStale(TalkLink $link): bool {
		$proxy = $link->getLastActivity() ?? $link->getLinkedAt();
		if ($proxy === null) {
			return true;
		}

		return ((new DateTime())->getTimestamp() - $proxy->getTimestamp()) > self::STALE_AFTER;
	}//end isStale()

	/**
	 * Extract cached fields from a Talk Room entity.
	 *
	 * Returns:
	 *   - `roomId`           — Talk Room::id (legacy numeric id)
	 *   - `roomName`         — display name (or fallback raw name)
	 *   - `roomType`         — Talk Room::TYPE_*
	 *   - `subtitle`         — human-readable type label
	 *   - `participantCount` — ParticipantService::getNumberOfActors
	 *   - `lastMessage`      — {actor:{type,id},text,timestamp} or null
	 *   - `lastActivity`     — DateTime or null
	 *
	 * Each accessor is wrapped in try/catch so a missing getter on an
	 * older Talk release degrades to null rather than crashing.
	 *
	 * @param object $room Talk Room entity.
	 * @param string $userUid User UID (for getDisplayName).
	 *
	 * @return array{roomId:?int,roomName:?string,roomType:?int,subtitle:?string,participantCount:?int,lastMessage:?array,lastActivity:?DateTime}
	 *
	 */
	private function extractRoomFields(object $room, string $userUid): array {
		$viewer = new TalkViewer(userUid: $userUid, participantService: $this->talk->participantService());

		return $this->roomFields->cacheFields(room: $room, viewer: $viewer);
	}//end extractRoomFields()

}//end class
