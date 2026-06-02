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
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
 */
class TalkLinkService
{
    private const REQUIRED_APP = 'spreed';
    private const ROOM_TAG     = '[or:';
    private const STALE_AFTER  = 300;
    // 5 minutes in seconds.

    /**
     * Constructor.
     *
     * @param TalkLinkMapper     $talkLinkMapper Persistence for link rows.
     * @param ContainerInterface $container      Container for late-bound Talk classes.
     * @param IAppManager        $appManager     NC app manager.
     * @param IUserSession       $userSession    Active session.
     * @param IL10N              $l10n           Translation service.
     * @param LoggerInterface    $logger         Logger.
     */
    public function __construct(
        private readonly TalkLinkMapper $talkLinkMapper,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether NC Talk (spreed) is installed + enabled for the current
     * user.
     *
     * @return bool
     */
    public function isTalkAvailable(): bool
    {
        return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
    }//end isTalkAvailable()

    /**
     * Link an existing Talk room to an OR object.
     *
     * The link row carries room_id/room_name/room_type/subtitle/
     * participantCount/lastMessageData/lastActivity harvested from the
     * Talk room so subsequent reads don't need to hit Talk. Idempotent:
     * a duplicate link raises a 409 Exception.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id (Tier-2 column).
     * @param string $roomToken  Talk room token.
     *
     * @return TalkLink The persisted link row.
     *
     * @throws Exception On missing user, missing room (404), duplicate (409),
     *                   Talk unavailable (503).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard
     *     clauses (no user, duplicate, Talk unavailable, find failure,
     *     null room) followed by best-effort cache extraction.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function linkRoom(string $objectUuid, int $registerId, int $schemaId, string $roomToken): TalkLink
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $existing = $this->talkLinkMapper->findByObjectAndRoom($objectUuid, $roomToken);
        if ($existing !== null) {
            throw new Exception('Room already linked to this object', 409);
        }

        $manager = $this->resolveManager();
        if ($manager === null) {
            throw new Exception('Talk is not available', 503);
        }

        $room = $this->findRoom(manager: $manager, roomToken: $roomToken, userUid: $user->getUID());
        if ($room === null) {
            throw new Exception('Talk room not found', 404);
        }

        $cache = $this->extractRoomFields(room: $room, userUid: $user->getUID());

        $link = new TalkLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setRoomToken($roomToken);
        $link->setRoomId($cache['roomId']);
        $link->setRoomName($cache['roomName']);
        $link->setRoomType($cache['roomType']);
        $link->setSubtitle($cache['subtitle']);
        $link->setParticipantCount($cache['participantCount']);
        $lastMessageData = null;
        if ($cache['lastMessage'] !== null) {
            $lastMessageData = json_encode($cache['lastMessage']);
        }

        $link->setLastMessageData($lastMessageData);
        $link->setLastActivity($cache['lastActivity']);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->talkLinkMapper->insert($link);
    }//end linkRoom()

    /**
     * Unlink a Talk room from an object. Does NOT destroy the room
     * itself — it remains in Talk for other linked objects (or the
     * users who joined it directly).
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param string $roomToken  Talk room token.
     *
     * @return void
     *
     * @throws Exception When no matching link is found (404).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function unlinkRoom(string $objectUuid, string $roomToken): void
    {
        $deleted = $this->talkLinkMapper->deleteByObjectAndRoom($objectUuid, $roomToken);
        if ($deleted === 0) {
            throw new Exception('Talk link not found', 404);
        }
    }//end unlinkRoom()

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
     * @SuppressWarnings(PHPMD.LongVariable) $refreshedLastMessageData is the only name that clearly
     * distinguishes the refreshed message payload from $link->getLastMessageData(); a shorter alias
     * would lose that semantic clarity in the stale-cache refresh path.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function getLinkedRooms(string $objectUuid): array
    {
        $links   = $this->talkLinkMapper->findByObjectUuid($objectUuid);
        $manager = $this->resolveManager();
        $user    = $this->userSession->getUser();

        $results = [];
        foreach ($links as $link) {
            $row = $link->jsonSerialize();

            if ($manager !== null && $user !== null && $this->isStale(link: $link) === true) {
                try {
                    $room = $this->findRoom(manager: $manager, roomToken: $link->getRoomToken(), userUid: $user->getUID());
                    if ($room !== null) {
                        $refreshed = $this->extractRoomFields(room: $room, userUid: $user->getUID());
                        $link->setRoomId($refreshed['roomId']);
                        $link->setRoomName($refreshed['roomName']);
                        $link->setRoomType($refreshed['roomType']);
                        $link->setSubtitle($refreshed['subtitle']);
                        $link->setParticipantCount($refreshed['participantCount']);
                        $refreshedLastMessageData = null;
                        if ($refreshed['lastMessage'] !== null) {
                            $refreshedLastMessageData = json_encode($refreshed['lastMessage']);
                        }

                        $link->setLastMessageData($refreshedLastMessageData);
                        $link->setLastActivity($refreshed['lastActivity']);
                        $this->talkLinkMapper->update($link);
                        $row = $link->jsonSerialize();
                    }
                } catch (Throwable $e) {
                    // Stale link — keep cached row as-is.
                    $this->logger->debug('Stale talk link for room '.$link->getRoomToken().': '.$e->getMessage());
                }//end try
            }//end if

            $results[] = $row;
        }//end foreach

        return $results;
    }//end getLinkedRooms()

    /**
     * Create a new Talk room and link it to an object in one
     * transaction.
     *
     * Uses Talk's `Manager::createRoom` for the create then invites
     * the current user as participant (so the room is visible in
     * Talk's UI). The link row carries the new room's token + cached
     * metadata.
     *
     * Room type maps to Talk Room::TYPE_*:
     *   1 → one2one  (rejected — pickers exclude it)
     *   2 → group    (default)
     *   3 → public
     *
     * @param string      $objectUuid  Parent OR object uuid.
     * @param int         $registerId  OR register id.
     * @param int         $schemaId    OR schema id.
     * @param string      $roomName    Room display name (required).
     * @param string|null $description Room description (optional).
     * @param int         $roomType    Talk room type (default 2 = group).
     *
     * @return TalkLink The persisted link row.
     *
     * @throws Exception On missing user, Talk unavailable, invalid
     *                   type, or create failure.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  createAndLinkRoom() guards user auth, room type
     * validity, Talk availability, createRoom success, token extraction, description (best-effort),
     * and participant invite (best-effort); each is a sequential guard required for the create+link
     * contract.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Each of the best-effort optional paths (description,
     * participant invite, display-name fallback) contributes to NPath independently; all are
     * correct-by-contract Try/catch degradations.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) createAndLinkRoom() creates the room, optionally
     * sets description and invites the owner, extracts the token, builds cached metadata, and persists
     * the link row in one transactional sequence; splitting would scatter the atomic create+link
     * operation.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function createAndLinkRoom(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $roomName,
        ?string $description,
        int $roomType=2
    ): TalkLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        if (in_array($roomType, [2, 3], true) === false) {
            throw new Exception('Invalid room type (must be 2=group or 3=public)', 400);
        }

        $manager = $this->resolveManager();
        if ($manager === null) {
            throw new Exception('Talk is not available', 503);
        }

        // Tag with the OR marker so the legacy `[or:{uuid}]` reverse
        // lookup still finds it until that code path is removed.
        $taggedName = $roomName.' '.self::ROOM_TAG.$objectUuid.']';
        $userUid    = $user->getUID();

        try {
            // Talk Manager::createRoom(type, name, owner) — exact
            // signature varies across Talk releases. We pass the
            // three canonical args and trust the manager to ignore
            // extras.
            $room = $manager->createRoom($roomType, $taggedName, $userUid);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to create Talk room: '.$e->getMessage());
            throw new Exception('Failed to create Talk room: '.$e->getMessage(), 500);
        }

        if (is_object($room) === false) {
            throw new Exception('Talk createRoom returned no room', 500);
        }

        // Set the description (best-effort; older Talk versions may
        // not support it).
        if ($description !== null && $description !== '') {
            try {
                $roomService = $this->resolveRoomService();
                if ($roomService !== null && method_exists($roomService, 'setDescription') === true) {
                    $roomService->setDescription($room, $description);
                }
            } catch (Throwable $e) {
                $this->logger->debug('Failed to set Talk room description: '.$e->getMessage());
            }
        }

        // Invite the current user so the room appears in their UI.
        try {
            $participantService = $this->resolveParticipantService();
            if ($participantService !== null && method_exists($participantService, 'addUsers') === true) {
                $participantService->addUsers($room, [['actorType' => 'users', 'actorId' => $userUid]]);
            }
        } catch (Throwable $e) {
            // Best-effort — the owner is implicitly a participant in
            // many Talk versions, so a failure here is non-fatal.
            $this->logger->debug('Failed to add owner as Talk participant: '.$e->getMessage());
        }

        $roomToken = '';
        try {
            $roomToken = (string) $room->getToken();
        } catch (Throwable $e) {
            throw new Exception('Talk room has no token after create', 500);
        }

        if ($roomToken === '') {
            throw new Exception('Talk room has no token after create', 500);
        }

        $cache = $this->extractRoomFields(room: $room, userUid: $userUid);

        $link = new TalkLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setRoomToken($roomToken);
        $link->setRoomId($cache['roomId']);
        $resolvedRoomName = $roomName;
        if ($cache['roomName'] !== null && $cache['roomName'] !== '') {
            $resolvedRoomName = $cache['roomName'];
        }

        $resolvedRoomType = $roomType;
        if ($cache['roomType'] !== null) {
            $resolvedRoomType = $cache['roomType'];
        }

        $cacheLastMessageData = null;
        if ($cache['lastMessage'] !== null) {
            $cacheLastMessageData = json_encode($cache['lastMessage']);
        }

        $link->setRoomName($resolvedRoomName);
        $link->setRoomType($resolvedRoomType);
        $link->setSubtitle($cache['subtitle']);
        $link->setParticipantCount($cache['participantCount']);
        $link->setLastMessageData($cacheLastMessageData);
        $link->setLastActivity($cache['lastActivity']);
        $link->setLinkedBy($userUid);
        $link->setLinkedAt(new DateTime());

        return $this->talkLinkMapper->insert($link);
    }//end createAndLinkRoom()

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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  getAvailableRoomsForUser() iterates rooms and
     * per-room wraps every Talk Entity::__call getter (token, name, displayName, type, participantCount)
     * in try/catch; each is a cross-version compatibility guard required because Talk's Room entity API
     * changed between releases.
     * @SuppressWarnings(PHPMD.NPathComplexity)       Per-room: empty-token skip + search filter +
     * changelog/note-to-self type exclude + displayName override + participantCount resolve each
     * independently expand NPath; all are valid filtering/degradation paths.
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) getAvailableRoomsForUser() must wrap every Talk
     * getter individually in try/catch because Talk's Room entity uses Entity::__call magic and accessor
     * availability varies across Talk releases; extracting helpers for each getter would scatter the
     * version-compatibility logic.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function getAvailableRoomsForUser(?string $search=null): array
    {
        if ($this->isTalkAvailable() === false) {
            return [];
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        $manager = $this->resolveManager();
        if ($manager === null) {
            return [];
        }

        try {
            $rooms = $manager->getRoomsForUser($user->getUID(), [], true);
        } catch (Throwable $e) {
            $this->logger->debug('Talk Manager::getRoomsForUser failed: '.$e->getMessage());
            return [];
        }

        if (is_array($rooms) === false) {
            return [];
        }

        $participantService = $this->resolveParticipantService();
        $needle = null;
        if ($search !== null) {
            $needle = mb_strtolower(trim($search));
        }

        $out = [];
        foreach ($rooms as $room) {
            $token = '';
            try {
                $token = (string) $room->getToken();
            } catch (Throwable $e) {
                continue;
            }

            if ($token === '') {
                continue;
            }

            $name = '';
            try {
                $name = (string) $room->getName();
            } catch (Throwable $e) {
                // Leave default empty.
            }

            if (method_exists($room, 'getDisplayName') === true) {
                try {
                    $displayName = (string) $room->getDisplayName($user->getUID());
                    if ($displayName !== '') {
                        $name = $displayName;
                    }
                } catch (Throwable $e) {
                    // Keep existing $name.
                }
            }

            $cleanName = $this->stripMarker(name: $name);

            if ($needle !== null && $needle !== '' && mb_strpos(mb_strtolower($cleanName), $needle) === false) {
                continue;
            }

            $type = null;
            try {
                $type = (int) $room->getType();
            } catch (Throwable $e) {
                // Leave null.
            }

            // Exclude changelog / note-to-self / one2one auto-rooms
            // from the picker — the user can't usefully link those.
            if ($type === 4 || $type === 6) {
                continue;
            }

            $count = null;
            if ($participantService !== null && method_exists($participantService, 'getNumberOfActors') === true) {
                try {
                    $count = (int) $participantService->getNumberOfActors($room);
                } catch (Throwable $e) {
                    $count = null;
                }
            }

            $out[] = [
                'token'            => $token,
                'name'             => $cleanName,
                'type'             => $type,
                'participantCount' => $count,
            ];
        }//end foreach

        return $out;
    }//end getAvailableRoomsForUser()

    /**
     * Resolve Talk's `Manager` from the server container.
     *
     * @return object|null Returns null when Talk is unavailable.
     */
    private function resolveManager(): ?object
    {
        if ($this->isTalkAvailable() === false) {
            return null;
        }

        if (class_exists('OCA\\Talk\\Manager') === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\Talk\\Manager');
        } catch (Throwable $e) {
            $this->logger->debug('Talk Manager not resolvable: '.$e->getMessage());
            return null;
        }
    }//end resolveManager()

    /**
     * Resolve Talk's `RoomService`.
     *
     * @return object|null Returns null when Talk is unavailable.
     */
    private function resolveRoomService(): ?object
    {
        if (class_exists('OCA\\Talk\\Service\\RoomService') === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\Talk\\Service\\RoomService');
        } catch (Throwable $e) {
            $this->logger->debug('Talk RoomService not resolvable: '.$e->getMessage());
            return null;
        }
    }//end resolveRoomService()

    /**
     * Resolve Talk's `ParticipantService`.
     *
     * @return object|null Returns null when Talk is unavailable.
     */
    private function resolveParticipantService(): ?object
    {
        if (class_exists('OCA\\Talk\\Service\\ParticipantService') === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\Talk\\Service\\ParticipantService');
        } catch (Throwable $e) {
            $this->logger->debug('Talk ParticipantService not resolvable: '.$e->getMessage());
            return null;
        }
    }//end resolveParticipantService()

    /**
     * Look up a Talk Room by token via the Manager.
     *
     * Tries `getRoomForUserByToken(token, userUid)` first (the
     * user-scoped variant), then `getRoomByToken(token)` (raw).
     * Returns null when both throw or are missing.
     *
     * @param object $manager   Talk Manager.
     * @param string $roomToken Talk room token.
     * @param string $userUid   User UID.
     *
     * @return object|null Talk Room or null.
     */
    private function findRoom(object $manager, string $roomToken, string $userUid): ?object
    {
        if (method_exists($manager, 'getRoomForUserByToken') === true) {
            try {
                return $manager->getRoomForUserByToken($roomToken, $userUid);
            } catch (Throwable $e) {
                // Fall through.
            }
        }

        if (method_exists($manager, 'getRoomByToken') === true) {
            try {
                return $manager->getRoomByToken($roomToken);
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }//end findRoom()

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
    private function isStale(TalkLink $link): bool
    {
        $proxy = $link->getLastActivity() ?? $link->getLinkedAt();
        if ($proxy === null) {
            return true;
        }

        return ((new DateTime())->getTimestamp() - $proxy->getTimestamp()) > self::STALE_AFTER;
    }//end isStale()

    /**
     * Strip the OR linking marker `[or:{uuid}]` from a room name.
     *
     * @param string $name Raw room name.
     *
     * @return string
     */
    private function stripMarker(string $name): string
    {
        // Match [or:<uuid>] anywhere in the string.
        $stripped = preg_replace('/\s*\[or:[^\]]+\]\s*/', ' ', $name) ?? $name;
        return trim($stripped);
    }//end stripMarker()

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
     * @param object $room    Talk Room entity.
     * @param string $userUid User UID (for getDisplayName).
     *
     * @return array{roomId:?int,roomName:?string,roomType:?int,subtitle:?string,participantCount:?int,lastMessage:?array,lastActivity:?DateTime}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) extractRoomFields() wraps every Talk Room getter
     * (id, name, displayName, type, participantCount, lastActivity, lastMessage) in individual
     * try/catch blocks; each is a required cross-version guard because Talk's Room entity uses
     * Entity::__call magic and accessor availability varies.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Each optional accessor (displayName, participantService,
     * lastActivity, lastMessage) contributes independent true/false paths; all are required degradation
     * guards for older Talk releases.
     */
    private function extractRoomFields(object $room, string $userUid): array
    {
        $roomId = null;
        try {
            $roomId = (int) $room->getId();
        } catch (Throwable $e) {
            // Leave null.
        }

        $name = '';
        try {
            $name = (string) $room->getName();
        } catch (Throwable $e) {
            // Leave default empty.
        }

        if (method_exists($room, 'getDisplayName') === true) {
            try {
                $displayName = (string) $room->getDisplayName($userUid);
                if ($displayName !== '') {
                    $name = $displayName;
                }
            } catch (Throwable $e) {
                // Keep $name as-is.
            }
        }

        $cleanName = $this->stripMarker(name: $name);

        $type = null;
        try {
            $type = (int) $room->getType();
        } catch (Throwable $e) {
            // Leave null.
        }

        $subtitle = $this->buildSubtitle(room: $room, type: $type);

        $participantCount   = null;
        $participantService = $this->resolveParticipantService();
        if ($participantService !== null && method_exists($participantService, 'getNumberOfActors') === true) {
            try {
                $participantCount = (int) $participantService->getNumberOfActors($room);
            } catch (Throwable $e) {
                $participantCount = null;
            }
        }

        $lastActivity = null;
        try {
            $rawActivity = null;
            if (method_exists($room, 'getLastActivity') === true) {
                $rawActivity = $room->getLastActivity();
            }

            if ($rawActivity instanceof \DateTimeInterface) {
                $lastActivity = new DateTime($rawActivity->format(DateTime::ATOM));
            }
        } catch (Throwable $e) {
            // Leave null.
        }

        $lastMessage = $this->buildLastMessage(room: $room);

        $resolvedName = null;
        if ($cleanName !== '') {
            $resolvedName = $cleanName;
        }

        return [
            'roomId'           => $roomId,
            'roomName'         => $resolvedName,
            'roomType'         => $type,
            'subtitle'         => $subtitle,
            'participantCount' => $participantCount,
            'lastMessage'      => $lastMessage,
            'lastActivity'     => $lastActivity,
        ];
    }//end extractRoomFields()

    /**
     * Build a human-readable subtitle from the room type.
     *
     * Prefers the room description when set, falling back to the
     * translated Talk room-type label.
     *
     * @param object   $room Talk Room.
     * @param int|null $type Numeric room type.
     *
     * @return string|null
     */
    private function buildSubtitle(object $room, ?int $type): ?string
    {
        $description = '';
        if (method_exists($room, 'getDescription') === true) {
            try {
                $description = trim((string) $room->getDescription());
            } catch (Throwable $e) {
                $description = '';
            }
        }

        if ($description !== '') {
            return $description;
        }

        switch ($type) {
            case 1:
                return $this->l10n->t('Direct message');

            case 2:
                return $this->l10n->t('Group');

            case 3:
                return $this->l10n->t('Public');

            case 4:
                return $this->l10n->t('System');

            case 6:
                return $this->l10n->t('Note to self');

            default:
                return null;
        }
    }//end buildSubtitle()

    /**
     * Build the `{actor, text, timestamp}` shape from a Talk Room's
     * last comment.
     *
     * Returns `null` when the room has no last message yet, or when
     * the lookup throws (federated rooms return null by design).
     *
     * @param object $room Talk Room.
     *
     * @return array{actor:array{type:string,id:string},text:string,timestamp:?int}|null
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) buildLastMessage() handles four lastMessage shapes
     * returned by different Talk releases (IComment, Message entity, plain object, or null); each shape
     * requires distinct accessor paths that cannot be merged.
     * @SuppressWarnings(PHPMD.NPathComplexity)      The four lastMessage shapes × actor-type detection ×
     * actor-id extraction produce many paths; all are required for cross-version Talk compatibility.
     */
    private function buildLastMessage(object $room): ?array
    {
        if (method_exists($room, 'getLastMessage') === false) {
            return null;
        }

        try {
            $comment = $room->getLastMessage();
        } catch (Throwable $e) {
            return null;
        }

        if (is_object($comment) === false) {
            return null;
        }

        $text = '';
        if (method_exists($comment, 'getMessage') === true) {
            $text = (string) $comment->getMessage();
        }

        $actorType = '';
        if (method_exists($comment, 'getActorType') === true) {
            $actorType = (string) $comment->getActorType();
        }

        $actorId = '';
        if (method_exists($comment, 'getActorId') === true) {
            $actorId = (string) $comment->getActorId();
        }

        $timestamp = null;
        if (method_exists($comment, 'getCreationDateTime') === true) {
            try {
                $created = $comment->getCreationDateTime();
                if ($created instanceof \DateTimeInterface) {
                    $timestamp = $created->getTimestamp();
                }
            } catch (Throwable $e) {
                $timestamp = null;
            }
        }

        return [
            'actor'     => ['type' => $actorType, 'id' => $actorId],
            'text'      => $text,
            'timestamp' => $timestamp,
        ];
    }//end buildLastMessage()
}//end class
