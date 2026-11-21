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

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Event\FileRestrictionAdmitEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Node;
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
class FileRestrictionGuard implements FileRestrictionGuardInterface {

	/**
	 * Verdicts already given in this request, keyed by "uid:fileId".
	 *
	 * @var array<string, bool>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @param FileRestrictionStore  $store      The rows.
	 * @param FileRestrictionCaller $caller     The session and group manager of the caller.
	 * @param IEventDispatcher      $dispatcher Asks apps to admit a caller who is not listed.
	 * @param LoggerInterface       $logger     Where a failed lookup is noted.
	 */
	public function __construct(
		private readonly FileRestrictionStore $store,
		private readonly FileRestrictionCaller $caller,
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

		$check = new FileReadCheck(userId: $this->caller->userSession->getUser()?->getUID());
		$this->recall(check: $check, fileIds: $fileIds);

		if ($check->unknown !== [] && $this->loadRestrictions(check: $check) === true) {
			$this->decideUnknown(check: $check);
		}

		return $check->verdicts;
	}//end readable()

	/**
	 * Take the memoised verdicts; queue the other files as unknown.
	 *
	 * @param FileReadCheck $check   The read check; receives verdicts and unknown ids.
	 * @param list<int>     $fileIds The normalised file ids.
	 *
	 * @return void
	 */
	private function recall(FileReadCheck $check, array $fileIds): void {
		foreach ($fileIds as $fileId) {
			if (array_key_exists($check->memoKey(fileId: $fileId), $this->memo) === true) {
				$check->verdicts[$fileId] = $this->memo[$check->memoKey(fileId: $fileId)];
				continue;
			}

			$check->unknown[] = $fileId;
		}
	}//end recall()

	/**
	 * Read the unknown files' restrictions in one lookup; on failure refuse them all (not memoised).
	 *
	 * @param FileReadCheck $check The read check; receives the restrictions, or refusals.
	 *
	 * @return bool False when the lookup failed.
	 */
	private function loadRestrictions(FileReadCheck $check): bool {
		try {
			$check->restrictions = $this->store->findMany(fileIds: $check->unknown);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FileRestrictionGuard] Could not read file restrictions, refusing the files',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			foreach ($check->unknown as $fileId) {
				$check->verdicts[$fileId] = false;
			}

			return false;
		}
	}//end loadRestrictions()

	/**
	 * Decide and memoise the verdict of every unknown file.
	 *
	 * @param FileReadCheck $check The read check with the restrictions loaded.
	 *
	 * @return void
	 */
	private function decideUnknown(FileReadCheck $check): void {
		foreach ($check->unknown as $fileId) {
			$verdict = $this->verdictFor(check: $check, fileId: $fileId);
			$check->verdicts[$fileId] = $verdict;
			$this->memo[$check->memoKey(fileId: $fileId)] = $verdict;
		}
	}//end decideUnknown()

	/**
	 * The verdict on one file: open when unrestricted, else whether the named caller is admitted.
	 *
	 * @param FileReadCheck $check  The read check.
	 * @param int           $fileId The file id.
	 *
	 * @return bool
	 */
	private function verdictFor(FileReadCheck $check, int $fileId): bool {
		$restriction = ($check->restrictions[$fileId] ?? null);
		if ($restriction === null) {
			return true;
		}

		$check->groups ??= $this->callerGroups();
		// An anonymous caller is never admitted to a restricted file.
		if ($check->callerIsNamed() === false) {
			return false;
		}

		return $this->admits(
			question: new FileRestrictionAdmitEvent(fileId: $fileId, userId: $check->userId, restriction: $restriction),
			groups: $check->groups
		);
	}//end verdictFor()

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
	 * Whether a named caller is admitted to one restricted file.
	 *
	 * Listed users and groups and administrators are admitted here; anyone
	 * else only when an app's listener admits them on the same question.
	 *
	 * @param FileRestrictionAdmitEvent $question The file, the caller and the stored restriction.
	 * @param list<string>              $groups   The caller's groups.
	 *
	 * @return bool True when admitted.
	 */
	private function admits(FileRestrictionAdmitEvent $question, array $groups): bool {
		$userId = $question->getUserId();
		$restriction = $question->getRestriction();
		if (in_array('admin', $groups, true) === true
			|| in_array($userId, (array)($restriction['users'] ?? []), true) === true
			|| array_intersect($groups, (array)($restriction['groups'] ?? [])) !== []
		) {
			return true;
		}

		return $this->admittedByListener(question: $question);
	}//end admits()

	/**
	 * Whether an app's listener admits the caller; a failing listener refuses.
	 *
	 * @param FileRestrictionAdmitEvent $question The file, the caller and the stored restriction.
	 *
	 * @return bool True when admitted.
	 */
	private function admittedByListener(FileRestrictionAdmitEvent $question): bool {
		try {
			$this->dispatcher->dispatchTyped($question);

			return $question->isAdmitted();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FileRestrictionGuard] An admit listener failed, refusing the file',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $question->getFileId(), 'error' => $e->getMessage()]
			);

			return false;
		}
	}//end admittedByListener()

	/**
	 * The caller's group ids, empty when anonymous.
	 *
	 * @return list<string> The groups.
	 */
	private function callerGroups(): array {
		$user = $this->caller->userSession->getUser();
		if ($user === null) {
			return [];
		}

		return array_values(array_map('strval', $this->caller->groupManager->getUserGroupIds($user)));
	}//end callerGroups()
}//end class
