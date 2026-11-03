<?php

/**
 * Moves managed folders from people's homes into the openregister account's home.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\Encryption\IManager as IEncryptionManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The move behind the upgrade step and its `occ` command.
 *
 * Before object-files-follow-object-access, an object's folder was made in the
 * home of whoever saved first, so nobody else could reach its files. This
 * moves every `Open Registers` tree found in a person's home into the
 * openregister account's home.
 *
 * - The move is a Nextcloud rename. On local storage it keeps every file id
 *   and folder id, so the ids stored on registers and objects keep resolving.
 * - Two homes may hold a folder for the same register. The trees are merged,
 *   and a file name that clashes gets a numeric suffix rather than replacing
 *   the other file.
 * - Published link shares on moved files are re-owned to the openregister
 *   account, so the public link keeps working.
 * - Nothing is ever deleted except a folder this step has just emptied.
 * - Anything it cannot move stays where it is and is counted. A later run
 *   moves only what is left.
 * - Local storage only. On any other storage, or with server-side encryption
 *   on, it moves nothing and says why.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
 */
class ObjectFileMigration {

	/**
	 * The managed root folder name.
	 *
	 * @var string
	 */
	public const ROOT_FOLDER = 'Open Registers';

	/**
	 * The app config key the refusal message is kept under, for the admin.
	 *
	 * @var string
	 */
	public const REFUSAL_KEY = 'object_files_move_refused';

	/**
	 * What the admin is told when the storage is not local.
	 *
	 * @var string
	 */
	public const REFUSAL_MESSAGE = 'OpenRegister did not move object files on this storage. '
		. 'Files stay where they are. Only local storage is supported for this move.';

	/**
	 * Counts of the current run.
	 *
	 * @var array{foldersMoved: int, filesMoved: int, sharesReowned: int, foldersLeft: int, refused: bool}
	 */
	private array $tally = ['foldersMoved' => 0, 'filesMoved' => 0, 'sharesReowned' => 0, 'foldersLeft' => 0, 'refused' => false];

	/**
	 * Constructor.
	 *
	 * @param IRootFolder          $rootFolder        The file tree.
	 * @param IUserManager         $userManager       Walks the people whose homes may hold managed folders.
	 * @param FileOwnershipHandler $ownership         Resolves the openregister account.
	 * @param ObjectFileShareReowner $shareReowner    Re-owns published link shares.
	 * @param IAppConfig           $appConfig         Keeps the refusal message for the admin.
	 * @param IEncryptionManager   $encryptionManager Tells whether server-side encryption is on.
	 * @param LoggerInterface      $logger            Records every folder left behind.
	 * @param IConfig              $config            Reads whether an admin set the account's quota.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IUserManager $userManager,
		private readonly FileOwnershipHandler $ownership,
		private readonly ObjectFileShareReowner $shareReowner,
		private readonly IAppConfig $appConfig,
		private readonly IEncryptionManager $encryptionManager,
		private readonly LoggerInterface $logger,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Move every managed tree out of people's homes.
	 *
	 * @return array{foldersMoved: int, filesMoved: int, sharesReowned: int, foldersLeft: int, refused: bool}
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
	 */
	public function run(): array {
		$this->tally = ['foldersMoved' => 0, 'filesMoved' => 0, 'sharesReowned' => 0, 'foldersLeft' => 0, 'refused' => false];

		$account = $this->ownership->getUser();
		$this->ensureUnlimitedQuota(account: $account);
		$systemUid = $account->getUID();

		if ($this->encryptionManager->isEnabled() === true) {
			return $this->refuse(reason: 'server-side encryption is on');
		}

		$systemHome = $this->rootFolder->getUserFolder($systemUid);
		if ($this->isLocal(node: $systemHome) === false) {
			return $this->refuse(reason: 'the openregister home is not on local storage');
		}

		$this->userManager->callForSeenUsers(
			function (IUser $user) use ($systemUid, $systemHome): bool {
				if ($user->getUID() !== $systemUid) {
					$this->moveHome(uid: $user->getUID(), systemHome: $systemHome);
				}

				return true;
			}
		);

		if ($this->tally['refused'] === false) {
			$this->appConfig->deleteKey('openregister', self::REFUSAL_KEY);
		}

		return $this->tally;
	}//end run()

	/**
	 * Move one person's `Open Registers` tree.
	 *
	 * @param string $uid        The person.
	 * @param Folder $systemHome The openregister account's home.
	 *
	 * @return void
	 */
	private function moveHome(string $uid, Folder $systemHome): void {
		try {
			$home = $this->rootFolder->getUserFolder($uid);
			if ($home->nodeExists(self::ROOT_FOLDER) === false) {
				return;
			}

			$source = $home->get(self::ROOT_FOLDER);
		} catch (Throwable $e) {
			return;
		}

		if ($source instanceof Folder === false) {
			return;
		}

		if ($this->isLocal(node: $source) === false) {
			$this->tally['foldersLeft']++;
			$this->refuse(reason: 'the home of ' . $uid . ' is not on local storage');
			return;
		}

		$target = $this->folderIn(parent: $systemHome, name: self::ROOT_FOLDER);
		$this->mergeInto(source: $source, target: $target);
		$this->removeIfEmpty(folder: $source);
	}//end moveHome()

	/**
	 * Move a folder into a target parent, merging with a folder of the same name.
	 *
	 * @param Folder $node         The folder to move.
	 * @param Folder $targetParent Where it goes.
	 *
	 * @return void
	 */
	private function moveFolder(Folder $node, Folder $targetParent): void {
		$name = $node->getName();

		try {
			if ($targetParent->nodeExists($name) === false) {
				$fileIds = $this->fileIdsIn(node: $node);
				$node->move($targetParent->getPath() . '/' . $name);
				$this->tally['foldersMoved']++;
				$this->tally['filesMoved'] += count($fileIds);
				$this->reownShares(fileIds: $fileIds);
				return;
			}

			$existing = $targetParent->get($name);
		} catch (Throwable $e) {
			$this->leave(node: $node, error: $e);
			return;
		}

		if ($existing instanceof Folder === false) {
			$this->leave(node: $node, error: null);
			return;
		}

		$this->mergeInto(source: $node, target: $existing);
		$this->removeIfEmpty(folder: $node);
	}//end moveFolder()

	/**
	 * Move every child of a folder into a target folder.
	 *
	 * @param Folder $source The folder whose children move.
	 * @param Folder $target The folder they move into.
	 *
	 * @return void
	 */
	private function mergeInto(Folder $source, Folder $target): void {
		try {
			$children = $source->getDirectoryListing();
		} catch (Throwable $e) {
			$this->leave(node: $source, error: $e);
			return;
		}

		foreach ($children as $child) {
			if ($child instanceof Folder) {
				$this->moveFolder(node: $child, targetParent: $target);
				continue;
			}

			$this->moveFile(file: $child, target: $target);
		}
	}//end mergeInto()

	/**
	 * Move one file into a folder, with a numeric suffix when the name is taken.
	 *
	 * @param Node   $file   The file.
	 * @param Folder $target The folder it moves into.
	 *
	 * @return void
	 */
	private function moveFile(Node $file, Folder $target): void {
		try {
			$name = $this->freeName(folder: $target, desired: $file->getName());
			$fileId = (int)$file->getId();
			$file->move($target->getPath() . '/' . $name);
			$this->tally['filesMoved']++;
			$this->reownShares(fileIds: [$fileId]);
		} catch (Throwable $e) {
			$this->leave(node: $file, error: $e);
		}
	}//end moveFile()

	/**
	 * A name not yet taken in a folder: `name.ext`, then `name (1).ext`, and so on.
	 *
	 * @param Folder $folder  The folder.
	 * @param string $desired The name wanted.
	 *
	 * @return string A free name.
	 */
	private function freeName(Folder $folder, string $desired): string {
		if ($folder->nodeExists($desired) === false) {
			return $desired;
		}

		$dot = strrpos($desired, '.');
		$base = $desired;
		$extension = '';
		if ($dot !== false && $dot > 0) {
			$base = substr($desired, 0, $dot);
			$extension = substr($desired, $dot);
		}

		for ($i = 1; $i < 1000; $i++) {
			$candidate = $base . ' (' . $i . ')' . $extension;
			if ($folder->nodeExists($candidate) === false) {
				return $candidate;
			}
		}

		return $base . ' (' . bin2hex(random_bytes(4)) . ')' . $extension;
	}//end freeName()

	/**
	 * A child folder, made when it is missing.
	 *
	 * @param Folder $parent The parent.
	 * @param string $name   The child name.
	 *
	 * @return Folder The child folder.
	 */
	private function folderIn(Folder $parent, string $name): Folder {
		if ($parent->nodeExists($name) === true) {
			$node = $parent->get($name);
			if ($node instanceof Folder) {
				return $node;
			}
		}

		return $parent->newFolder($name);
	}//end folderIn()

	/**
	 * The ids of every file under a folder.
	 *
	 * @param Folder $node The folder.
	 *
	 * @return array<int, int> File ids.
	 */
	private function fileIdsIn(Folder $node): array {
		$ids = [];
		foreach ($node->getDirectoryListing() as $child) {
			if ($child instanceof Folder) {
				$ids = array_merge($ids, $this->fileIdsIn(node: $child));
				continue;
			}

			$ids[] = (int)$child->getId();
		}

		return $ids;
	}//end fileIdsIn()

	/**
	 * Point the published link shares of moved files at the openregister account.
	 *
	 * @param array<int, int> $fileIds The moved files.
	 *
	 * @return void
	 */
	private function reownShares(array $fileIds): void {
		if ($fileIds === []) {
			return;
		}

		$owner = $this->ownership->getUser()->getUID();
		$this->tally['sharesReowned'] += $this->shareReowner->reown(fileIds: $fileIds, owner: $owner);
	}//end reownShares()

	/**
	 * Remove a folder this step has just emptied.
	 *
	 * @param Folder $folder The folder.
	 *
	 * @return void
	 */
	private function removeIfEmpty(Folder $folder): void {
		try {
			if ($folder->getDirectoryListing() === []) {
				$folder->delete();
			}
		} catch (Throwable $e) {
			$this->logger->info(
				message: '[ObjectFileMigration] Kept an emptied folder: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end removeIfEmpty()

	/**
	 * Count and log a node left where it is.
	 *
	 * @param Node           $node  The node.
	 * @param Throwable|null $error Why, when known.
	 *
	 * @return void
	 */
	private function leave(Node $node, ?Throwable $error): void {
		$this->tally['foldersLeft']++;

		$path = '';
		try {
			$path = $node->getPath();
		} catch (Throwable $e) {
			$path = '(unknown path)';
		}

		$this->logger->warning(
			message: '[ObjectFileMigration] Left ' . $path . ' where it is: ' . ($error?->getMessage() ?? 'a node of another kind holds its name'),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end leave()

	/**
	 * Whether a node is on local storage.
	 *
	 * @param Node $node The node.
	 *
	 * @return bool True for local storage.
	 */
	private function isLocal(Node $node): bool {
		try {
			return $node->getStorage()->isLocal() === true;
		} catch (Throwable $e) {
			return false;
		}
	}//end isLocal()

	/**
	 * Refuse the move, and tell the admin why.
	 *
	 * @param string $reason The reason, for the log.
	 *
	 * @return array{foldersMoved: int, filesMoved: int, sharesReowned: int, foldersLeft: int, refused: bool}
	 */
	private function refuse(string $reason): array {
		$this->tally['refused'] = true;
		$this->appConfig->setValueString('openregister', self::REFUSAL_KEY, self::REFUSAL_MESSAGE);
		$this->logger->warning(
			message: '[ObjectFileMigration] ' . self::REFUSAL_MESSAGE . ' Reason: ' . $reason,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $this->tally;
	}//end refuse()

	/**
	 * Give the openregister account an unlimited quota, unless an admin set one.
	 *
	 * @param IUser $account The openregister account.
	 *
	 * @return void
	 */
	private function ensureUnlimitedQuota(IUser $account): void {
		try {
			// The raw value: IUser::getQuota() resolves `default` to the
			// instance default, which would hide whether an admin set one.
			if ($this->config->getUserValue($account->getUID(), 'files', 'quota', 'default') === 'default') {
				$account->setQuota('none');
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectFileMigration] Could not set the openregister quota: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end ensureUnlimitedQuota()
}//end class
