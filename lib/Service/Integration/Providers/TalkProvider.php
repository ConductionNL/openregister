<?php

/**
 * TalkProvider — exposes NC Talk (spreed) conversations linked to an OR
 * object via the IntegrationProvider contract.
 *
 * Tier-2 storage: the `openregister_talk_links` table pairs
 * object ↔ room token. When the table contains rows for the object
 * we return them via the {@see TalkLinkMapper} (cheap, no Talk API
 * roundtrip needed for the leaf-row shape — the cache already has
 * subtitle/participantCount/lastMessage). When it's empty we fall
 * back to the legacy marker scan (`[or:{uuid}]` substring in the
 * room display name) so rooms linked before Tier-2 still surface.
 *
 * NB: NC Talk's internal app id is `spreed`, not `talk` — that's what
 * IAppManager resolves against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-talk/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use DateTime;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Integration provider that surfaces NC Talk rooms linked to an OpenRegister object.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The overall complexity is
 *     driven by the many `method_exists` guards required to safely call Talk's
 *     loose-typed Room/Comment API across NC versions; extracting additional
 *     classes would not reduce complexity — it would only scatter guards.
 */
class TalkProvider extends AbstractIntegrationProvider {

	use NcAppLinkTableProvider;

	private const REQUIRED_APP = 'spreed';

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface           $container      Resolves Talk's room manager lazily.
	 * @param AppProviderDescription       $description    The app manager (isEnabled check) and localisation.
	 * @param IUserSession                 $userSession    The signed-in user.
	 * @param TalkLinkMapperInterface|null $talkLinkMapper Local link table, when wired.
	 *
	 * @return void
	 */
	public function __construct(
		private ContainerInterface $container,
		AppProviderDescription $description,
		private IUserSession $userSession,
		private ?TalkLinkMapperInterface $talkLinkMapper = null,
	) {
		$this->appManager = $description->appManager;
		$this->l10n       = $description->l10n;
	}//end __construct()

	public function getId(): string {
		return 'talk';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Chat');
	}//end getLabel()

	public function getIcon(): string {
		return 'ChatOutline';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'comms';
	}//end getGroup()

	/**
	 * List Talk rooms linked to an OR object.
	 *
	 * Linking convention: a Talk room whose display name contains the
	 * marker `[or:{objectUuid}]`. The provider calls
	 * `OCA\Talk\Manager::getRoomsForUser` (the current user's rooms),
	 * filters by the marker, and normalises rows into the registry leaf
	 * row contract.
	 *
	 * Each row carries the widened Phase B-1 payload:
	 *   * `id`               — Talk room token (canonical id).
	 *   * `title`            — room display name (with the `[or:…]` marker stripped).
	 *   * `type`             — numeric Talk room type (1=one2one, 2=group, 3=public, 4=changelog, 6=note-to-self).
	 *   * `subtitle`         — human-readable type label, e.g. "Direct message" / "Group" / "Public" / "Note to self".
	 *   * `participantCount` — count via `ParticipantService::getNumberOfActors`. `null` when unavailable.
	 *   * `lastMessage`      — `{actor: {type, id}, text, timestamp}` from `Room::getLastMessage()`. `null` if no messages.
	 *   * `unreadMessages`   — `null` for now; admin's per-room read state is queried via
	 *     `ParticipantService::getParticipant(...)` which throws ParticipantNotFoundException
	 *     for system-owner roles, and the cost of catching that per row outweighs the
	 *     value until we have a per-user UI surface that needs it. Documented as a
	 *     known limitation; the bespoke CnTalkTab treats `null` as "no badge".
	 *   * `lastActivity`     — unix timestamp from `Room::getLastActivity()`. `null` if unset.
	 *   * `url`              — deep-link `/index.php/call/{token}`.
	 *
	 * @param ObjectRef              $ref The owning object: register, schema and uuid.
	 * @param array<string,mixed> $filters  Optional filters (unused).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/integration-talk/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The IntegrationProvider contract fixes the signature.
	 */
	public function list(ObjectRef $ref, array $filters = []): array {
		return $this->rowsForSignedIn(objectId: $ref->id);
	}//end list()

	/**
	 * The object's conversation rows; none without a signed-in user.
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rowsForSignedIn(string $objectId): array {
		if ($this->userSession->getUser() === null) {
			return [];
		}

		return $this->objectRows(objectId: $objectId);
	}//end rowsForSignedIn()

	/**
	 * The object's link rows; none when no link mapper is wired or the link
	 * table cannot be read, so list() falls back to the marker scan.
	 *
	 * @param string $objectId Object uuid.
	 *
	 * @return array<int,object> The link entities.
	 */
	protected function linkedRows(string $objectId): array {
		if ($this->talkLinkMapper === null) {
			return [];
		}

		try {
			return $this->talkLinkMapper->findByObjectUuid($objectId);
		} catch (Throwable $e) {
			return [];
		}
	}//end linkedRows()

	/**
	 * One {@see \OCA\OpenRegister\Db\TalkLink} in the registry leaf-row contract
	 * (same shape as the legacy marker-scan path).
	 *
	 * @param TalkLink $link Persisted row.
	 *
	 * @return array<string,mixed>
	 */
	protected function rowFromLink(TalkLink $link): array {
		$serialized = $link->jsonSerialize();
		$token = (string)($serialized['roomToken'] ?? '');

		return [
			'id' => $token,
			'title' => $serialized['roomName'] ?? $token,
			'type' => $serialized['roomType'] ?? null,
			'subtitle' => $serialized['subtitle'] ?? null,
			'participantCount' => $serialized['participantCount'] ?? null,
			'lastMessage' => $serialized['lastMessage'] ?? null,
			'unreadMessages' => null,
			'lastActivity' => $this->isoTimestamp(iso: $serialized['lastActivity'] ?? null),
			'url' => $this->linkUrl(url: $serialized['url'] ?? null, token: $token),
		];
	}//end rowFromLink()

	/**
	 * A unix timestamp from an ISO date; null when there is none or it does not parse.
	 *
	 * @param string|null $iso ISO-8601 date.
	 *
	 * @return int|null
	 */
	private function isoTimestamp(?string $iso): ?int {
		if ($iso === null) {
			return null;
		}

		try {
			return (new DateTime($iso))->getTimestamp();
		} catch (Throwable $e) {
			return null;
		}
	}//end isoTimestamp()

	/**
	 * A link's URL, or the room's call URL when the link has none.
	 *
	 * @param string|null $url   Stored URL.
	 * @param string      $token Room token.
	 *
	 * @return string|null
	 */
	private function linkUrl(?string $url, string $token): ?string {
		if ($url === null && $token !== '') {
			return '/index.php/call/' . $token;
		}

		return $url;
	}//end linkUrl()

	/**
	 * Legacy fallback: the current user's Talk rooms whose display name carries
	 * the `[or:{objectId}]` marker. Empty when Talk is unavailable.
	 *
	 * @param string $objectId Object uuid used to build the marker string.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function unlinkedRows(string $objectId): array {
		$scope = new TalkRoomScope(userId: (string)$this->userSession->getUser()?->getUID(), objectId: $objectId);
		$rooms = $this->roomsOf(scope: $scope);
		if ($rooms === null) {
			return [];
		}

		return $this->rowsOfRooms(rooms: $rooms, scope: $scope);
	}//end unlinkedRows()

	/**
	 * The user's rooms, last message included; null when Talk cannot answer (AD-23).
	 *
	 * @param TalkRoomScope $scope The scan's user and marker.
	 *
	 * @return iterable<object>|null
	 */
	private function roomsOf(TalkRoomScope $scope): ?iterable {
		try {
			return $this->container->get('OCA\\Talk\\Manager')->getRoomsForUser($scope->userId, [], true);
		} catch (Throwable $e) {
			return null;
		}
	}//end roomsOf()

	/**
	 * The leaf rows of the rooms that carry the marker, with participant counts
	 * when Talk's ParticipantService resolves.
	 *
	 * @param iterable<object> $rooms Talk rooms.
	 * @param TalkRoomScope    $scope The scan's user and marker.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rowsOfRooms(iterable $rooms, TalkRoomScope $scope): array {
		$participants = $this->participantServiceOrNull();
		$out = [];
		foreach ($rooms as $room) {
			$row = $this->buildRoomRow(room: $room, scope: $scope);
			if ($row !== null) {
				$row['participantCount'] = $this->resolveParticipantCount(participantService: $participants, room: $room);
				$out[] = $row;
			}
		}

		return $out;
	}//end rowsOfRooms()

	/**
	 * Talk's ParticipantService, or null when it does not resolve.
	 *
	 * @return object|null
	 */
	private function participantServiceOrNull(): ?object {
		try {
			return $this->container->get('OCA\\Talk\\Service\\ParticipantService');
		} catch (Throwable $e) {
			return null;
		}
	}//end participantServiceOrNull()

	/**
	 * A leaf row for one Talk room, or null when it does not carry the marker.
	 * `participantCount` is filled in by the caller.
	 *
	 * @param object        $room  Talk Room object.
	 * @param TalkRoomScope $scope The scan's user and marker.
	 *
	 * @return array<string,mixed>|null
	 */
	private function buildRoomRow(object $room, TalkRoomScope $scope): ?array {
		$name = $this->roomName(room: $room, userId: $scope->userId);
		if (str_contains($name, $scope->marker) === false) {
			return null;
		}

		return $this->roomRow(room: $room, title: $this->stripMarker(name: $name, marker: $scope->marker));
	}//end buildRoomRow()

	/**
	 * A room's name: its display name for the user when it has one, else its name.
	 *
	 * @param object $room   Talk Room object.
	 * @param string $userId Current user id.
	 *
	 * @return string
	 */
	private function roomName(object $room, string $userId): string {
		if (method_exists($room, 'getDisplayName') === true) {
			$displayName = (string)$room->getDisplayName($userId);
			if ($displayName !== '') {
				return $displayName;
			}
		}

		return (string)($this->optionalCall(target: $room, method: 'getName') ?? '');
	}//end roomName()

	/**
	 * The leaf row of a room that carries the marker.
	 *
	 * @param object $room  Talk Room object.
	 * @param string $title Room name without the marker.
	 *
	 * @return array<string,mixed>
	 */
	private function roomRow(object $room, string $title): array {
		$token = (string)$this->optionalCall(target: $room, method: 'getToken');
		$type = $this->roomType(room: $room);

		return [
			'id' => $token,
			'title' => $title,
			'type' => $type,
			'subtitle' => $this->buildSubtitle(room: $room, type: $type),
			'participantCount' => null,
			'lastMessage' => $this->buildLastMessage(room: $room),
			'unreadMessages' => null,
			'lastActivity' => $this->lastActivityOf(room: $room),
			'url' => '/index.php/call/' . $token,
		];
	}//end roomRow()

	/**
	 * A room's numeric type, or null when the room has none.
	 *
	 * @param object $room Talk Room object.
	 *
	 * @return int|null
	 */
	private function roomType(object $room): ?int {
		if (method_exists($room, 'getType') === true) {
			return (int)$room->getType();
		}

		return null;
	}//end roomType()

	/**
	 * A room's last activity as a unix timestamp, or null when unset.
	 *
	 * @param object $room Talk Room object.
	 *
	 * @return int|null
	 */
	private function lastActivityOf(object $room): ?int {
		if (method_exists($room, 'getLastActivity') === true && $room->getLastActivity() !== null) {
			return $room->getLastActivity()->getTimestamp();
		}

		return null;
	}//end lastActivityOf()

	/**
	 * What a getter answers, or null when the object does not have it.
	 *
	 * @param object $target Talk object (loose-typed across NC versions).
	 * @param string $method Getter name.
	 *
	 * @return mixed
	 */
	private function optionalCall(object $target, string $method): mixed {
		if (method_exists($target, $method) === true) {
			return $target->$method();
		}

		return null;
	}//end optionalCall()

	/**
	 * Strip the OR linking marker `[or:{uuid}]` from a room title so the
	 * UI doesn't expose plumbing strings.
	 *
	 * @param string $name Raw room name / display name.
	 * @param string $marker Marker substring to strip (already includes
	 *                       trailing `]`).
	 *
	 * @return string
	 */
	private function stripMarker(string $name, string $marker): string {
		$stripped = str_replace($marker, '', $name);
		return trim($stripped);
	}//end stripMarker()

	/**
	 * Build a human-readable subtitle: the room description, else a label for
	 * the room type (NC Talk's `Room::TYPE_*` labels).
	 *
	 * @param object   $room The Talk Room (loose-typed because the
	 *                       provider stays usable when Talk is missing).
	 * @param int|null $type Numeric room type.
	 *
	 * @return string|null
	 */
	private function buildSubtitle(object $room, ?int $type): ?string {
		$description = trim((string)$this->optionalCall(target: $room, method: 'getDescription'));
		if ($description !== '') {
			return $description;
		}

		return $this->typeLabel(type: $type);
	}//end buildSubtitle()

	/**
	 * The label of a Talk room type, or null for a type without one.
	 *
	 * @param int|null $type Numeric room type.
	 *
	 * @return string|null
	 */
	private function typeLabel(?int $type): ?string {
		return match ($type) {
			1 => $this->l10n->t('Direct message'),
			2 => $this->l10n->t('Group'),
			3 => $this->l10n->t('Public'),
			4 => $this->l10n->t('System'),
			6 => $this->l10n->t('Note to self'),
			default => null,
		};
	}//end typeLabel()

	/**
	 * Resolve the participant count for a Talk room.
	 *
	 * Prefers `ParticipantService::getNumberOfActors(Room)` (Talk's
	 * canonical count) and falls back to `null` when the service is
	 * unavailable or throws.
	 *
	 * @param object|null $participantService Talk ParticipantService.
	 * @param object $room Talk Room.
	 *
	 * @return int|null
	 */
	private function resolveParticipantCount(?object $participantService, object $room): ?int {
		if ($participantService === null || method_exists($participantService, 'getNumberOfActors') === false) {
			return null;
		}

		return $this->countActors(participantService: $participantService, room: $room);
	}//end resolveParticipantCount()

	/**
	 * `ParticipantService::getNumberOfActors(Room)`; null when it throws.
	 *
	 * @param object $participantService Talk ParticipantService.
	 * @param object $room               Talk Room.
	 *
	 * @return int|null
	 */
	private function countActors(object $participantService, object $room): ?int {
		try {
			return (int)$participantService->getNumberOfActors($room);
		} catch (Throwable $e) {
			return null;
		}
	}//end countActors()

	/**
	 * Build the `{actor, text, timestamp}` shape from a Talk Room's last
	 * comment; null when the room has no last message or the lookup throws
	 * (federated rooms return null from `Room::getLastMessage()` by design).
	 *
	 * @param object $room Talk Room.
	 *
	 * @return array{actor: array{type: string, id: string}, text: string, timestamp: ?int}|null
	 */
	private function buildLastMessage(object $room): ?array {
		$comment = $this->lastComment(room: $room);
		if ($comment === null) {
			return null;
		}

		return [
			'actor' => [
				'type' => (string)$this->optionalCall(target: $comment, method: 'getActorType'),
				'id' => (string)$this->optionalCall(target: $comment, method: 'getActorId'),
			],
			'text' => (string)$this->optionalCall(target: $comment, method: 'getMessage'),
			'timestamp' => $this->creationTimestamp(comment: $comment),
		];
	}//end buildLastMessage()

	/**
	 * A room's last comment; null when it has none or the lookup throws.
	 *
	 * @param object $room Talk Room.
	 *
	 * @return object|null
	 */
	private function lastComment(object $room): ?object {
		try {
			return $this->optionalCall(target: $room, method: 'getLastMessage');
		} catch (Throwable $e) {
			return null;
		}
	}//end lastComment()

	/**
	 * When a comment was created, as a unix timestamp; null when unknown.
	 *
	 * @param object $comment Talk comment.
	 *
	 * @return int|null
	 */
	private function creationTimestamp(object $comment): ?int {
		$created = $this->optionalCall(target: $comment, method: 'getCreationDateTime');
		if ($created instanceof \DateTimeInterface) {
			return $created->getTimestamp();
		}

		return null;
	}//end creationTimestamp()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing IAppManager::isInstalled — no standalone health
	 *              behaviour; the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->appManager->isInstalled(self::REQUIRED_APP) === true, missing: 'NC Talk (spreed) is not installed');
	}//end health()
}//end class
