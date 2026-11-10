<?php

/**
 * TalkGateway
 *
 * Late-bound access to Talk's Manager, RoomService and ParticipantService for TalkLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\TalkLink
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

use OCA\OpenRegister\Service\LinkServiceRuntime;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Resolves Talk's services from the server container and makes the
 * best-effort Talk calls, degrading to null or a debug log when Talk (or a
 * method on an older Talk release) is missing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TalkLink
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
 */
final class TalkGateway {

	/**
	 * Talk app id.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'spreed';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Container for late-bound Talk classes.
	 * @param LinkServiceRuntime $runtime   The app manager (is Talk enabled) and the logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LinkServiceRuntime $runtime,
	) {
	}//end __construct()

	/**
	 * Whether the Talk (spreed) app is enabled for the current user.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function isAvailable(): bool {
		return $this->runtime->appManager()->isEnabledForUser(self::REQUIRED_APP);
	}//end isAvailable()

	/**
	 * Resolve Talk's `Manager` from the server container.
	 *
	 * @return object|null Returns null when Talk is unavailable.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function manager(): ?object {
		if ($this->isAvailable() === false) {
			return null;
		}

		return $this->resolve(className: 'OCA\\Talk\\Manager', label: 'Manager');
	}//end manager()

	/**
	 * Resolve Talk's `ParticipantService`.
	 *
	 * @return object|null Returns null when Talk is unavailable.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function participantService(): ?object {
		return $this->resolve(className: 'OCA\\Talk\\Service\\ParticipantService', label: 'ParticipantService');
	}//end participantService()

	/**
	 * Look up a Talk Room by token via the Manager.
	 *
	 * Tries `getRoomForUserByToken(token, userUid)` first (the
	 * user-scoped variant), then `getRoomByToken(token)` (raw).
	 * Returns null when both throw or are missing.
	 *
	 * @param TalkSession $session   Talk's Manager and the user the room is read as.
	 * @param string      $roomToken Talk room token.
	 *
	 * @return object|null Talk Room or null.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function findRoom(TalkSession $session, string $roomToken): ?object {
		$manager = $session->manager;
		if (method_exists($manager, 'getRoomForUserByToken') === true) {
			try {
				return $manager->getRoomForUserByToken($roomToken, $session->userUid);
			} catch (Throwable $e) {
				// Fall through.
			}
		}

		return $this->roomByToken(manager: $manager, roomToken: $roomToken);
	}//end findRoom()

	/**
	 * Set a room's description, best-effort (older Talk versions may not support it).
	 *
	 * @param object      $room        The Talk room.
	 * @param string|null $description The description; null or empty sets nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function setDescription(object $room, ?string $description): void {
		if (in_array($description, [null, ''], true) === true) {
			return;
		}

		try {
			$roomService = $this->resolve(className: 'OCA\\Talk\\Service\\RoomService', label: 'RoomService');
			if ($roomService !== null && method_exists($roomService, 'setDescription') === true) {
				$roomService->setDescription($room, $description);
			}
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Failed to set Talk room description: ' . $e->getMessage());
		}
	}//end setDescription()

	/**
	 * Invite a user into a room so it appears in their Talk UI, best-effort.
	 *
	 * The owner is implicitly a participant in many Talk versions, so a
	 * failure here is non-fatal.
	 *
	 * @param object $room    The Talk room.
	 * @param string $userUid The user to add.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function addOwner(object $room, string $userUid): void {
		try {
			$participantService = $this->participantService();
			if ($participantService !== null && method_exists($participantService, 'addUsers') === true) {
				$participantService->addUsers($room, [['actorType' => 'users', 'actorId' => $userUid]]);
			}
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Failed to add owner as Talk participant: ' . $e->getMessage());
		}
	}//end addOwner()

	/**
	 * The raw token lookup of older Talk releases.
	 *
	 * @param object $manager   Talk Manager.
	 * @param string $roomToken Talk room token.
	 *
	 * @return object|null Talk Room or null.
	 */
	private function roomByToken(object $manager, string $roomToken): ?object {
		if (method_exists($manager, 'getRoomByToken') === false) {
			return null;
		}

		try {
			return $manager->getRoomByToken($roomToken);
		} catch (Throwable $e) {
			return null;
		}
	}//end roomByToken()

	/**
	 * Resolve a Talk class from the container, or null when it is missing or fails.
	 *
	 * @param string $className The Talk class.
	 * @param string $label     Its short name for the debug log.
	 *
	 * @return object|null The service.
	 */
	private function resolve(string $className, string $label): ?object {
		if (class_exists($className) === false) {
			return null;
		}

		try {
			return $this->container->get($className);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('Talk ' . $label . ' not resolvable: ' . $e->getMessage());
			return null;
		}
	}//end resolve()
}//end class
