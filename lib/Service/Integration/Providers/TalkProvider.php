<?php

/**
 * TalkProvider — exposes NC Talk (spreed) conversations linked to an OR
 * object via the IntegrationProvider contract.
 *
 * `link-table` storage (a future `openregister_talk_links` pairs
 * object ↔ conversation token); the wrapping TalkService lands in a
 * follow-up — this provider registers the registry surface today.
 *
 * NB: NC Talk's internal app id is `spreed`, not `talk` — that's what
 * IAppManager resolves against.
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
 * @spec openspec/changes/integration-talk/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

class TalkProvider extends AbstractIntegrationProvider
{

    private const REQUIRED_APP = 'spreed';
    private const ROOM_TAG     = '[or:';

    public function __construct(
        private ContainerInterface $container,
        private IAppManager $appManager,
        private IUserSession $userSession,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'talk';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Chat');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'ChatOutline';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'comms';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
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
     * @param string              $register Register slug or numeric id (unused).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional filters (unused).
     *
     * @return array<int,array<string,mixed>>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Method composes a handful of
     *     optional Talk API calls behind method_exists guards; splitting would
     *     hide the leaf-row contract.
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        $marker = self::ROOM_TAG.$objectId.']';

        try {
            $manager = $this->container->get('OCA\\Talk\\Manager');
            // `includeLastMessage=true` populates Room::getLastMessage()
            // eagerly so we don't issue an extra DB round-trip per row.
            $rooms = $manager->getRoomsForUser($user->getUID(), [], true);
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
            $name = method_exists($room, 'getName') === true ? (string) ($room->getName() ?? '') : '';
            if (method_exists($room, 'getDisplayName') === true) {
                $displayName = (string) $room->getDisplayName($user->getUID());
                if ($displayName !== '') {
                    $name = $displayName;
                }
            }

            if (str_contains($name, $marker) === false) {
                continue;
            }

            $lastActivity = null;
            if (method_exists($room, 'getLastActivity') === true && $room->getLastActivity() !== null) {
                $lastActivity = $room->getLastActivity()->getTimestamp();
            }

            $type             = method_exists($room, 'getType') === true ? (int) $room->getType() : null;
            $token            = method_exists($room, 'getToken') === true ? (string) $room->getToken() : '';
            $title            = $this->stripMarker($name, $marker);
            $subtitle         = $this->buildSubtitle($room, $type);
            $participantCount = $this->resolveParticipantCount($participantService, $room);
            $lastMessage      = $this->buildLastMessage($room);

            $out[] = [
                'id'               => $token,
                'title'            => $title,
                'type'             => $type,
                'subtitle'         => $subtitle,
                'participantCount' => $participantCount,
                'lastMessage'      => $lastMessage,
                // `unreadMessages` is left null on purpose — see method docblock.
                'unreadMessages'   => null,
                'lastActivity'     => $lastActivity,
                'url'              => '/index.php/call/'.$token,
            ];
        }//end foreach

        return $out;
    }//end list()

    /**
     * Strip the OR linking marker `[or:{uuid}]` from a room title so the
     * UI doesn't expose plumbing strings.
     *
     * @param string $name   Raw room name / display name.
     * @param string $marker Marker substring to strip (already includes
     *                       trailing `]`).
     *
     * @return string
     */
    private function stripMarker(string $name, string $marker): string
    {
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
     * @param object   $room The Talk Room (loose-typed because the
     *                       provider stays usable when Talk is missing).
     * @param int|null $type Numeric room type.
     *
     * @return string|null
     */
    private function buildSubtitle(object $room, ?int $type): ?string
    {
        $description = '';
        if (method_exists($room, 'getDescription') === true) {
            $description = trim((string) $room->getDescription());
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
     * @param object      $room               Talk Room.
     *
     * @return int|null
     */
    private function resolveParticipantCount(?object $participantService, object $room): ?int
    {
        if ($participantService === null) {
            return null;
        }

        if (method_exists($participantService, 'getNumberOfActors') === false) {
            return null;
        }

        try {
            return (int) $participantService->getNumberOfActors($room);
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

        if ($comment === null) {
            return null;
        }

        $text      = method_exists($comment, 'getMessage') === true ? (string) $comment->getMessage() : '';
        $actorType = method_exists($comment, 'getActorType') === true ? (string) $comment->getActorType() : '';
        $actorId   = method_exists($comment, 'getActorId') === true ? (string) $comment->getActorId() : '';

        $timestamp = null;
        if (method_exists($comment, 'getCreationDateTime') === true) {
            $created = $comment->getCreationDateTime();
            if ($created instanceof \DateTimeInterface) {
                $timestamp = $created->getTimestamp();
            }
        }

        return [
            'actor'     => ['type' => $actorType, 'id' => $actorId],
            'text'      => $text,
            'timestamp' => $timestamp,
        ];
    }//end buildLastMessage()

    public function health(): array
    {
        $installed = $this->appManager->isInstalled(self::REQUIRED_APP);
        return [
            'status'     => $installed === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $installed === true ? null : 'NC Talk (spreed) is not installed',
        ];
    }//end health()
}//end class
