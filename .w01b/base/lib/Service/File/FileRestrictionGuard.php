<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one verdict on a restricted file: may the caller read it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Event\FileRestrictionAdmitEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asked by every OpenRegister path that lists or returns an object's file.
 *
 * A file without a restriction is readable (the object's rule has already
 * decided). A restricted file is readable by a listed user, a member of a
 * listed group, an administrator, or a caller an app admits through
 * {@see FileRestrictionAdmitEvent}. Anonymous callers and lookup failures are
 * refused.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
 */
class FileRestrictionGuard {

	/**
	 * Verdicts already given in this request, keyed by "uid:fileId".
	 *
	 * @var array<string, bool>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @param FileRestrictionStore $store        The rows.
	 * @param IUserSession         $userSession  The caller.
	 * @param IGroupManager        $groupManager The caller's groups.
	 * @param IEventDispatcher     $dispatcher   Asks apps to admit a caller who is not listed.
	 * @param LoggerInterface      $logger       Where a failed lookup is noted.
	 */
	public function __construct(
		private readonly FileRestrictionStore $store,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the caller may read one file.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return bool True when the file carries no restriction or the caller is admitted.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function mayRead(int $fileId): bool {
		return ($this->readable(fileIds: [$fileId])[$fileId] ?? false);
	}//end mayRead()

	/**
	 * The verdict on several files at once, with one lookup.
	 *
	 * @param list<int> $fileIds The Nextcloud file ids.
	 *
	 * @return array<int, bool> The verdict per file id.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function readable(array $fileIds): array {
		$fileIds = array_values(array_unique(array_map('intval', $fileIds)));
		if ($fileIds === []) {
			return [];
		}

		$userId = $this->userSession->getUser()?->getUID();
		$memoKey = static fn (int $fileId): string => ($userId ?? '') . ':' . $fileId;

		$verdicts = [];
		$unknown = [];
		foreach ($fileIds as $fileId) {
			if (array_key_exists($memoKey($fileId), $this->memo) === true) {
				$verdicts[$fileId] = $this->memo[$memoKey($fileId)];
				continue;
			}

			$unknown[] = $fileId;
		}

		if ($unknown === []) {
			return $verdicts;
		}

		try {
			$restrictions = $this->store->findMany(fileIds: $unknown);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FileRestrictionGuard] Could not read file restrictions, refusing the files',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			foreach ($unknown as $fileId) {
				$verdicts[$fileId] = false;
			}

			return $verdicts;
		}

		$groups = null;
		foreach ($unknown as $fileId) {
			$restriction = ($restrictions[$fileId] ?? null);
			$verdict = true;
			if ($restriction !== null) {
				$groups ??= $this->callerGroups();
				$verdict = $this->admits(fileId: $fileId, restriction: $restriction, userId: $userId, groups: $groups);
			}

			$verdicts[$fileId] = $verdict;
			$this->memo[$memoKey($fileId)] = $verdict;
		}

		return $verdicts;
	}//end readable()

	/**
	 * Keep only the nodes the caller may read, in their order.
	 *
	 * @param array<int|string, Node> $nodes The nodes.
	 *
	 * @return list<Node> The readable nodes.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
	 */
	public function readableNodes(array $nodes): array {
		$ids = [];
		foreach ($nodes as $node) {
			if ($node instanceof Node) {
				$ids[] = (int)$node->getId();
			}
		}

		$verdicts = $this->readable(fileIds: $ids);

		return array_values(
			array_filter(
				$nodes,
				static fn ($node): bool => $node instanceof Node && ($verdicts[(int)$node->getId()] ?? false) === true
			)
		);
	}//end readableNodes()

	/**
	 * Whether a file carries a restriction at all, whoever asks. A failed lookup answers true.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return bool True when restricted, or when that cannot be told.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-has-no-public-link-req-pfrr-004
	 */
	public function isRestricted(int $fileId): bool {
		try {
			return $this->store->find(fileId: $fileId) !== null;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FileRestrictionGuard] Could not read a file restriction, treating the file as restricted',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);

			return true;
		}
	}//end isRestricted()

	/**
	 * Drop the verdicts of this request, after a restriction changed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-restricted-file-is-readable-only-by-its-listed-principals-administrators-and-admitted-callers-req-pfrr-002
	 */
	public function forget(): void {
		$this->memo = [];
	}//end forget()

	/**
	 * Whether the caller is admitted to one restricted file.
	 *
	 * @param int                  $fileId      The file.
	 * @param array<string, mixed> $restriction The stored restriction.
	 * @param string|null          $userId      The caller, null when anonymous.
	 * @param list<string>         $groups      The caller's groups.
	 *
	 * @return bool True when admitted.
	 */
	private function admits(int $fileId, array $restriction, ?string $userId, array $groups): bool {
		if ($userId === null || $userId === '') {
			return false;
		}

		if (in_array('admin', $groups, true) === true
			|| in_array($userId, (array)($restriction['users'] ?? []), true) === true
			|| array_intersect($groups, (array)($restriction['groups'] ?? [])) !== []
		) {
			return true;
		}

		try {
			$event = new FileRestrictionAdmitEvent(fileId: $fileId, userId: $userId, restriction: $restriction);
			$this->dispatcher->dispatchTyped($event);

			return $event->isAdmitted();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FileRestrictionGuard] An admit listener failed, refusing the file',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'error' => $e->getMessage()]
			);

			return false;
		}
	}//end admits()

	/**
	 * The caller's group ids, empty when anonymous.
	 *
	 * @return list<string> The groups.
	 */
	private function callerGroups(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		return array_values(array_map('strval', $this->groupManager->getUserGroupIds($user)));
	}//end callerGroups()
}//end class
