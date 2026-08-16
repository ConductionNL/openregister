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
use OCA\OpenRegister\Db\TalkLinkMapper;
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

	private const REQUIRED_APP = 'spreed';
	private const ROOM_TAG = '[or:';

	public function __construct(
		private ContainerInterface $container,
		private IAppManager $appManager,
		private IUserSession $userSession,
		private IL10N $l10n,
		private ?TalkLinkMapper $talkLinkMapper = null,
	) {
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

	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

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
	 * @param string $register Register slug or numeric id (unused).
	 * @param string $schema Schema slug or numeric id (unused).
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Optional filters (unused).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  method_exists guards for Talk API calls form one leaf-row shape; splitting obscures the contract
	 * @SuppressWarnings(PHPMD.NPathComplexity)       method_exists guards for optional Talk features combined with room filtering multiply NPath
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $register, $schema, and
	 *     $filters are required by the IntegrationProvider interface contract
	 *     (lib/Service/Integration/IntegrationProvider.php:209) but Talk room
	 *     lookup is keyed solely on $objectId.
	 *
	 * @spec openspec/specs/integration-talk/spec.md
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		// Tier-2 preferred path — query the link table directly. Each row
		// already carries the widened Phase B-1 payload (subtitle,
		// participantCount, lastMessage, lastActivity) so we don't need
		// to hit Talk for the leaf-row shape.
		if ($this->talkLinkMapper !== null) {
			try {
				$links = $this->talkLinkMapper->findByObjectUuid($objectId);
				if (count($links) > 0) {
					return $this->mapLinks(links: $links);
				}
			} catch (Throwable $e) {
				// Fall through to legacy marker scan.
			}
		}

		return $this->listByMarkerScan(objectId: $objectId, userId: $user->getUID());
	}//end list()

	/**
	 * Legacy fallback: scan the current user's Talk rooms for the
	 * `[or:{objectId}]` marker in the display name.
	 *
	 * Used when the Tier-2 link table has no rows for the object (pre-Tier-2
	 * linked rooms). Degrades to an empty array when Talk is unavailable.
	 *
	 * @param string $objectId Object uuid used to build the marker string.
	 * @param string $userId Current user's id for display-name resolution.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function listByMarkerScan(string $objectId, string $userId): array {
		$marker = self::ROOM_TAG . $objectId . ']';

		try {
			$manager = $this->container->get('OCA\\Talk\\Manager');
			// `includeLastMessage=true` populates Room::getLastMessage()
			// eagerly so we don't issue an extra DB round-trip per row.
			$rooms = $manager->getRoomsForUser($userId, [], true);
		} catch (Throwable $e) {
			// Talk app schema mismatch / un-installed-during-runtime
			// degrades to empty list — AD-23.
			return [];
		}

		// ParticipantService is resolved lazily inside the loop so a
		// missing Talk service class doesn't crash the whole leaf —
		// the participantCount field just falls back to null.
		$participantService = null;
		try {
			$participantService = $this->container->get('OCA\\Talk\\Service\\ParticipantService');
		} catch (Throwable $e) {
			// Stays null — count surfaces as null and the UI hides the
			// "{n} participants" sub-meta.
		}

		$out = [];
		foreach ($rooms as $room) {
			$row = $this->buildRoomRow(
				room: $room,
				userId: $userId,
				marker: $marker,
				participantService: $participantService
			);
			if ($row !== null) {
				$out[] = $row;
			}
		}//end foreach

		return $out;
	}//end listByMarkerScan()

	/**
	 * Build a leaf-row array from a single Talk Room, or return null when
	 * the room does not carry the OR marker.
	 *
	 * @param object $room Talk Room object.
	 * @param string $userId Current user id (for display name).
	 * @param string $marker Marker string to match.
	 * @param object|null $participantService Talk ParticipantService (may be null).
	 *
	 * @return array<string,mixed>|null
	 */
	private function buildRoomRow(object $room, string $userId, string $marker, ?object $participantService): ?array {
		$name = '';
		if (method_exists($room, 'getName') === true) {
			$name = (string)($room->getName() ?? '');
		}

		if (method_exists($room, 'getDisplayName') === true) {
			$displayName = (string)$room->getDisplayName($userId);
			if ($displayName !== '') {
				$name = $displayName;
			}
		}

		if (str_contains($name, $marker) === false) {
			return null;
		}

		$lastActivity = null;
		if (method_exists($room, 'getLastActivity') === true && $room->getLastActivity() !== null) {
			$lastActivity = $room->getLastActivity()->getTimestamp();
		}

		$type = null;
		if (method_exists($room, 'getType') === true) {
			$type = (int)$room->getType();
		}

		$token = '';
		if (method_exists($room, 'getToken') === true) {
			$token = (string)$room->getToken();
		}

		$title = $this->stripMarker(name: $name, marker: $marker);
		$subtitle = $this->buildSubtitle(room: $room, type: $type);
		$participantCount = $this->resolveParticipantCount(participantService: $participantService, room: $room);
		$lastMessage = $this->buildLastMessage(room: $room);

		return [
			'id' => $token,
			'title' => $title,
			'type' => $type,
			'subtitle' => $subtitle,
			'participantCount' => $participantCount,
			'lastMessage' => $lastMessage,
			// `unreadMessages` is left null on purpose — see list() docblock.
			'unreadMessages' => null,
			'lastActivity' => $lastActivity,
			'url' => '/index.php/call/' . $token,
		];
	}//end buildRoomRow()

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
	 * Build a human-readable subtitle from the room type, falling back
	 * to the room description when type alone is too generic.
	 *
	 * Mirrors the labels in NC Talk's UI (see
	 * `OCA\Talk\Room::TYPE_*` constants).
	 *
	 * @param object $room The Talk Room (loose-typed because the
	 *                     provider stays usable when Talk is missing).
	 * @param int|null $type Numeric room type.
	 *
	 * @return string|null
	 */
	private function buildSubtitle(object $room, ?int $type): ?string {
		$description = '';
		if (method_exists($room, 'getDescription') === true) {
			$description = trim((string)$room->getDescription());
		}

		if ($description !== '') {
			return $description;
		}

		// Talk Room::TYPE_* constants.
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
		if ($participantService === null) {
			return null;
		}

		if (method_exists($participantService, 'getNumberOfActors') === false) {
			return null;
		}

		try {
			return (int)$participantService->getNumberOfActors($room);
		} catch (Throwable $e) {
			return null;
		}
	}//end resolveParticipantCount()

	/**
	 * Build the `{actor, text, timestamp}` shape from a Talk Room's
	 * last comment.
	 *
	 * Returns `null` when the room has no last message yet, or when
	 * the lookup throws (federated rooms return `null` from
	 * `Room::getLastMessage()` by design).
	 *
	 * @param object $room Talk Room.
	 *
	 * @return array{actor: array{type: string, id: string}, text: string, timestamp: ?int}|null
	 */
	private function buildLastMessage(object $room): ?array {
		if (method_exists($room, 'getLastMessage') === false) {
			return null;
		}

		try {
			$comment = $room->getLastMessage();
		} catch (Throwable $e) {
			return null;
		}

		if ($comment === null) {
			return null;
		}

		$text = '';
		if (method_exists($comment, 'getMessage') === true) {
			$text = (string)$comment->getMessage();
		}

		$actorType = '';
		if (method_exists($comment, 'getActorType') === true) {
			$actorType = (string)$comment->getActorType();
		}

		$actorId = '';
		if (method_exists($comment, 'getActorId') === true) {
			$actorId = (string)$comment->getActorId();
		}

		$timestamp = null;
		if (method_exists($comment, 'getCreationDateTime') === true) {
			$created = $comment->getCreationDateTime();
			if ($created instanceof \DateTimeInterface) {
				$timestamp = $created->getTimestamp();
			}
		}

		return [
			'actor' => ['type' => $actorType, 'id' => $actorId],
			'text' => $text,
			'timestamp' => $timestamp,
		];
	}//end buildLastMessage()

	/**
	 * Map a list of {@see \OCA\OpenRegister\Db\TalkLink} entities to the
	 * registry leaf-row contract (same shape as the legacy marker-scan
	 * path so callers don't see a regression).
	 *
	 * @param array<int, \OCA\OpenRegister\Db\TalkLink> $links Persisted rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function mapLinks(array $links): array {
		$out = [];
		foreach ($links as $link) {
			$serialized = $link->jsonSerialize();

			$lastMessage = $serialized['lastMessage'] ?? null;
			$lastActivityIso = $serialized['lastActivity'] ?? null;
			$lastActivityTs = null;
			if ($lastActivityIso !== null) {
				try {
					$lastActivityTs = (new DateTime($lastActivityIso))->getTimestamp();
				} catch (Throwable $e) {
					$lastActivityTs = null;
				}
			}

			$token = (string)($serialized['roomToken'] ?? '');
			$linkUrl = $serialized['url'] ?? null;
			if ($linkUrl === null && $token !== '') {
				$linkUrl = '/index.php/call/' . $token;
			}

			$out[] = [
				'id' => $token,
				'title' => $serialized['roomName'] ?? $token,
				'type' => $serialized['roomType'] ?? null,
				'subtitle' => $serialized['subtitle'] ?? null,
				'participantCount' => $serialized['participantCount'] ?? null,
				'lastMessage' => $lastMessage,
				// `unreadMessages` left null — see legacy list() docblock.
				'unreadMessages' => null,
				'lastActivity' => $lastActivityTs,
				'url' => $linkUrl,
			];
		}//end foreach

		return $out;
	}//end mapLinks()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing IAppManager::isInstalled — no standalone health
	 *              behaviour; the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		$installed = $this->appManager->isInstalled(self::REQUIRED_APP);
		$status = 'unavailable';
		$message = 'NC Talk (spreed) is not installed';
		if ($installed === true) {
			$status = 'ok';
			$message = null;
		}

		return [
			'status' => $status,
			'authStatus' => 'configured',
			'message' => $message,
		];
	}//end health()
}//end class
