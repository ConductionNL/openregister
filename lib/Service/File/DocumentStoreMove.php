<?php

/**
 * Moves the managed document tree to a root on another storage.
 *
 * Register by register: hold uploads off, copy every folder and file with its
 * earlier versions oldest first, verify each copy by SHA-256, recreate the
 * shares with the same token and copy the system tags, rewrite every reference
 * in DocumentReferenceMap from the old id to the new one, and only then mark the
 * register switched. A register where any of that fails is refused: its
 * references stay on the old ids and nothing is deleted. When every register is
 * switched the root follows, and the old tree is kept, renamed, until a purge
 * finds every file in it mapped to a verified copy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use OCA\OpenRegister\Db\DocumentMove;
use OCA\OpenRegister\Db\DocumentMoveMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Share\IShare;
use RuntimeException;

/**
 * The move of an existing corpus into a new store.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One move, kept in one place so its fail-closed order is readable.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)          Each step of the fail-closed order is its own small method.
 * @SuppressWarnings(PHPMD.StaticAccess)            DocumentCopyRun::key() is a pure key builder.
 */
class DocumentStoreMove {

	/**
	 * App config key holding the renamed old root, until it is purged.
	 */
	public const MOVED_FROM_KEY = 'document_store_moved_from';

	/**
	 * The share types the move recreates.
	 */
	private const SHARE_TYPES = [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK];

	/**
	 * The system tag object type of a file.
	 */
	private const TAG_TYPE = 'files';

	/**
	 * Constructor.
	 *
	 * @param DocumentStore             $store     The current root.
	 * @param DocumentMoveMapper        $moves     The recorded mapping, which makes a run resumable.
	 * @param DocumentStoreMoveGuard    $guard     Holds uploads off a moving register.
	 * @param DocumentReferenceMap      $map       Every column holding a node id.
	 * @param DocumentVersions          $versions  Earlier versions.
	 * @param DocumentStoreObjects      $objects   Rewrites the ids objects hold.
	 * @param RegisterMapperInterface   $registers The registers to move.
	 * @param DocumentStoreMovePlatform $platform  Files, app config, database, shares and tags.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		private readonly DocumentStore $store,
		private readonly DocumentMoveMapper $moves,
		private readonly DocumentStoreMoveGuard $guard,
		private readonly DocumentReferenceMap $map,
		private readonly DocumentVersions $versions,
		private readonly DocumentStoreObjects $objects,
		private readonly RegisterMapperInterface $registers,
		private readonly DocumentStoreMovePlatform $platform,
	) {
	}//end __construct()

	/**
	 * Run the move.
	 *
	 * @param DocumentStoreMoveOptions $options Target, register, dry run, without earlier versions.
	 *
	 * @return array{outcomes: list<DocumentMoveOutcome>, rootSwitched: bool}
	 *
	 * @throws RuntimeException When the target is missing, not a folder, or on the current root's storage.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function move(DocumentStoreMoveOptions $options): array {
		$target = $this->targetRoot(options: $options);
		$keepsVersions = $this->versions->keepsVersions(folder: $target);

		$outcomes = [];
		foreach ($this->allRegisters() as $register) {
			if ($options->registerId !== null && (int)$register->getId() !== $options->registerId) {
				continue;
			}

			$outcomes[] = $this->moveRegister(register: $register, target: $target, keepsVersions: $keepsVersions, options: $options);
		}

		$switched = false;
		if ($options->dryRun === false) {
			$switched = $this->switchRoot(target: $options->targetPath());
		}

		return ['outcomes' => $outcomes, 'rootSwitched' => $switched];
	}//end move()

	/**
	 * Move one register: copy, shares and tags, references, switch.
	 *
	 * @param Register                 $register      The register.
	 * @param Folder                   $target        The target root.
	 * @param bool                     $keepsVersions Whether the target keeps earlier versions.
	 * @param DocumentStoreMoveOptions $options       The run's switches.
	 *
	 * @return DocumentMoveOutcome
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function moveRegister(Register $register, Folder $target, bool $keepsVersions, DocumentStoreMoveOptions $options): DocumentMoveOutcome {
		$registerId = (int)$register->getId();
		$state = DocumentMoveOutcome::SWITCHED;
		if ($options->dryRun === true) {
			$state = DocumentMoveOutcome::DRY_RUN;
		}

		$outcome = new DocumentMoveOutcome(registerId: $registerId, name: (string)$register->getTitle(), state: $state);
		$source = $this->registerFolder(register: $register);
		if ($source === null) {
			$outcome->notes[] = 'The register holds no documents.';
			return $outcome;
		}

		if ($this->isSwitched(registerId: $registerId, folderId: (int)$source->getId()) === true) {
			$outcome->state = DocumentMoveOutcome::ALREADY_SWITCHED;
			return $outcome;
		}

		if ($options->dryRun === true) {
			$this->survey(folder: $source, keepsVersions: $keepsVersions, options: $options, outcome: $outcome);
			return $outcome;
		}

		$this->guard->begin(registerId: $registerId);
		try {
			$copy = $this->copyRegister(
				register: $register,
				target: $target,
				keepsVersions: $keepsVersions,
				withoutVersions: $options->withoutVersions,
				outcome: $outcome
			);
			if ($outcome->isRefused() === false) {
				$outcome->shares = $this->recreateShares(pairs: $copy['pairs'], outcome: $outcome);
			}

			if ($outcome->isRefused() === false) {
				$outcome->objects = $this->rewriteReferences(register: $register, copy: $copy, outcome: $outcome);
			}

			if ($outcome->isRefused() === false) {
				$this->moves->switchRegister(registerId: $registerId, at: new DateTime());
			}
		} finally {
			$this->guard->end(registerId: $registerId);
		}

		return $outcome;
	}//end moveRegister()

	/**
	 * Copy a register's folder tree into the target root and verify every copy.
	 *
	 * Earlier versions are written oldest first, then the current bytes, so the
	 * copy's own versions follow the source's order; the original version times
	 * are recorded in the mapping. A file already verified by an earlier run is
	 * not copied again.
	 *
	 * @param Register            $register        The register.
	 * @param Folder              $target          The target root.
	 * @param bool                $keepsVersions   Whether the target keeps earlier versions.
	 * @param bool                $withoutVersions Copy versioned files without their versions to a target that keeps none.
	 * @param DocumentMoveOutcome $outcome         Counts and refusals.
	 *
	 * @return array{moved: array<int, int>, kinds: array<int, string>, pairs: list<array{0: Node, 1: Node}>, withoutVersions: array<int, int>}
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function copyRegister(
		Register $register,
		Folder $target,
		bool $keepsVersions,
		bool $withoutVersions,
		DocumentMoveOutcome $outcome
	): array {
		$copy = ['moved' => [], 'kinds' => [], 'pairs' => [], 'withoutVersions' => []];
		$source = $this->registerFolder(register: $register);
		if ($source === null) {
			return $copy;
		}

		$run = new DocumentCopyRun(
			registerId: (int)$register->getId(),
			rows: $this->indexRows(registerId: (int)$register->getId()),
			keepsVersions: $keepsVersions,
			allowWithoutVersions: $withoutVersions,
			outcome: $outcome,
		);
		$this->copyFolder(folder: $source, parent: $target, run: $run);

		return ['moved' => $run->moved, 'kinds' => $run->kinds, 'pairs' => $run->pairs, 'withoutVersions' => $run->withoutVersions];
	}//end copyRegister()

	/**
	 * Recreate every user, group and link share and copy the system tags.
	 *
	 * Every share is read first: a password-protected link share refuses the
	 * register before anything is created. A link share keeps its token, so the
	 * public link a reader holds serves the copy.
	 *
	 * @param list<array{0: Node, 1: Node}> $pairs   Old node => its verified copy.
	 * @param DocumentMoveOutcome           $outcome Counts and refusals.
	 *
	 * @return int The shares recreated.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function recreateShares(array $pairs, DocumentMoveOutcome $outcome): int {
		$plans = [];
		foreach ($pairs as [$old, $new]) {
			foreach ($this->sharesOf(node: $old) as $share) {
				if ((int)$share->getShareType() === IShare::TYPE_LINK && (string)$share->getPassword() !== '') {
					$outcome->refuse(reason: sprintf(
						'%s: published through a password-protected link, whose password cannot be carried over.',
						$old->getPath()
					));
					continue;
				}

				$plans[] = [$share, $new, $old];
			}
		}

		if ($outcome->isRefused() === true) {
			return 0;
		}

		$created = 0;
		foreach ($plans as [$share, $new, $old]) {
			try {
				$this->recreateShare(share: $share, node: $new);
				$created++;
			} catch (\Throwable $e) {
				$outcome->refuse(reason: sprintf('%s: the share could not be recreated: %s', $old->getPath(), $e->getMessage()));
			}
		}

		foreach ($pairs as [$old, $new]) {
			$this->copyTags(oldId: (int)$old->getId(), newId: (int)$new->getId());
		}

		return $created;
	}//end recreateShares()

	/**
	 * Rewrite every reference to a moved node, in one transaction for the register.
	 *
	 * @param Register                                                                             $register The register.
	 * @param array{moved: array<int, int>, kinds: array<int, string>, withoutVersions: array<int, int>} $copy     What copyRegister() returned.
	 * @param DocumentMoveOutcome                                                                  $outcome  Refused when the rewrite fails.
	 *
	 * @return int The objects changed; 0 when the rewrite was rolled back.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function rewriteReferences(Register $register, array $copy, DocumentMoveOutcome $outcome): int {
		$db = $this->platform->db;
		$db->beginTransaction();
		try {
			foreach ($this->map->columns() as $column) {
				if ($db->tableExists($column['table']) === false) {
					continue;
				}

				foreach ($copy['moved'] as $oldId => $newId) {
					if (($copy['kinds'][$oldId] ?? null) === $column['kind']) {
						$this->rewriteColumn(column: $column, oldId: $oldId, newId: $newId);
					}
				}
			}

			$changed = $this->objects->rewrite(register: $register, moved: $copy['moved'], withoutVersions: $copy['withoutVersions']);
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			$outcome->refuse(reason: sprintf('The references could not be rewritten: %s', $e->getMessage()));
			return 0;
		}

		return $changed;
	}//end rewriteReferences()

	/**
	 * Point the root at the target once every register is switched, and rename the old root.
	 *
	 * @param string $target The target root's path in the openregister account's home.
	 *
	 * @return bool Whether the root switched.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function switchRoot(string $target): bool {
		foreach ($this->allRegisters() as $register) {
			$folder = $this->registerFolder(register: $register);
			if ($folder !== null && $this->isSwitched(registerId: (int)$register->getId(), folderId: (int)$folder->getId()) === false) {
				return false;
			}
		}

		$oldRoot = $this->store->rootFolder();
		$oldName = $this->store->root();
		$parent = '';
		if (str_contains($oldName, '/') === true) {
			$parent = dirname($oldName) . '/';
		}

		$renamed = $parent . $this->freeName(name: sprintf('%s (moved %s)', basename($oldName), (new DateTime())->format('Y-m-d')), parent: $parent);
		$this->platform->appConfig->setValueString('openregister', DocumentStore::ROOT_KEY, trim($target, " \t/"));
		$oldRoot->move($this->home()->getPath() . '/' . $renamed);
		$this->platform->appConfig->setValueString('openregister', self::MOVED_FROM_KEY, $renamed);

		return true;
	}//end switchRoot()

	/**
	 * Delete the renamed old root, only when every file and folder in it has a switched copy.
	 *
	 * @return array{deleted: bool, path: string|null, unmapped: list<string>}
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function purge(): array {
		$path = $this->platform->appConfig->getValueString('openregister', self::MOVED_FROM_KEY, '');
		if ($path === '' || $this->home()->nodeExists($path) === false) {
			return ['deleted' => false, 'path' => null, 'unmapped' => []];
		}

		$tree = $this->home()->get($path);
		$switched = $this->moves->switchedOldIds();
		$unmapped = [];
		if ($tree instanceof Folder === true) {
			$this->collectUnmapped(folder: $tree, switched: $switched, unmapped: $unmapped);
		}

		if ($unmapped !== []) {
			return ['deleted' => false, 'path' => $path, 'unmapped' => $unmapped];
		}

		$tree->delete();
		$this->platform->appConfig->deleteKey('openregister', self::MOVED_FROM_KEY);
		return ['deleted' => true, 'path' => $path, 'unmapped' => []];
	}//end purge()

	/**
	 * The target root, refused when missing, not a folder, or on the current root's storage.
	 *
	 * @param DocumentStoreMoveOptions $options The run's switches.
	 *
	 * @return Folder
	 *
	 * @throws RuntimeException When the target cannot take the documents.
	 */
	private function targetRoot(DocumentStoreMoveOptions $options): Folder {
		$path = $options->targetPath();
		if ($path === '' || in_array('..', explode('/', $path), true) === true) {
			throw new RuntimeException('The target must be a folder path in the openregister account\'s home.');
		}

		if ($path === $this->store->root() || str_starts_with($path . '/', $this->store->root() . '/') === true) {
			throw new RuntimeException('The target must not be the current root or a folder inside it.');
		}

		$home = $this->home();
		if ($home->nodeExists($path) === false) {
			throw new RuntimeException(sprintf('The target "%s" does not exist: mount the new store for the openregister account first.', $path));
		}

		$target = $home->get($path);
		if ($target instanceof Folder === false) {
			throw new RuntimeException(sprintf('The target "%s" is not a folder.', $path));
		}

		$current = $this->store->rootFolder();
		if ((string)$target->getStorage()->getId() === (string)$current->getStorage()->getId()) {
			throw new RuntimeException(sprintf('The target "%s" is on the same storage as the current root; mount a different store.', $path));
		}

		return $target;
	}//end targetRoot()

	/**
	 * Copy one folder into a parent and walk its children.
	 *
	 * @param Folder          $folder The source folder.
	 * @param Folder          $parent Where its copy goes.
	 * @param DocumentCopyRun $run    The register's run.
	 *
	 * @return void
	 */
	private function copyFolder(Folder $folder, Folder $parent, DocumentCopyRun $run): void {
		$name = $folder->getName();
		$copy = null;
		if ($parent->nodeExists($name) === true) {
			$copy = $parent->get($name);
		}

		if ($copy instanceof Folder === false) {
			$copy = $parent->newFolder($name);
		}

		$this->record(
			run: $run,
			entry: [
				'kind' => DocumentMove::KIND_FOLDER,
				'old' => (int)$folder->getId(),
				'new' => (int)$copy->getId(),
				'state' => DocumentMove::STATE_VERIFIED,
			]
		);
		$run->map(old: $folder, new: $copy, kind: DocumentReferenceMap::KIND_FOLDER);
		$run->outcome->folders++;

		foreach ($folder->getDirectoryListing() as $child) {
			if ($child instanceof Folder === true) {
				$this->copyFolder(folder: $child, parent: $copy, run: $run);
				continue;
			}

			if ($child instanceof File === true) {
				$this->copyFile(file: $child, parent: $copy, run: $run);
			}
		}
	}//end copyFolder()

	/**
	 * Copy one file with its earlier versions and verify it.
	 *
	 * @param File            $file   The source file.
	 * @param Folder          $parent Where its copy goes.
	 * @param DocumentCopyRun $run    The register's run.
	 *
	 * @return void
	 */
	private function copyFile(File $file, Folder $parent, DocumentCopyRun $run): void {
		$fileId = (int)$file->getId();
		$done = $this->verifiedCopy(file: $file, parent: $parent, run: $run);
		if ($done !== null) {
			$run->map(old: $file, new: $done, kind: DocumentReferenceMap::KIND_FILE);
			return;
		}

		try {
			$versions = $this->versionsToCarry(file: $file, run: $run);
			if ($versions === null) {
				$this->record(
					run: $run,
					entry: [
						'kind' => DocumentMove::KIND_FILE,
						'old' => $fileId,
						'state' => DocumentMove::STATE_REFUSED,
						'reason' => 'earlier versions and a target that keeps none',
					]
				);
				return;
			}

			$copy = $this->writeCopy(file: $file, parent: $parent, versions: $versions, run: $run);
			$sha = self::sha256(stream: $file->fopen('r'));
			if ($sha !== self::sha256(stream: $copy->fopen('r'))) {
				throw new RuntimeException('the copy does not match its source (SHA-256)');
			}

			$this->verifyVersions(source: $versions, copy: $copy);
		} catch (\Throwable $e) {
			$run->outcome->refuse(reason: sprintf('%s: %s', $file->getPath(), $e->getMessage()));
			$this->record(
				run: $run,
				entry: ['kind' => DocumentMove::KIND_FILE, 'old' => $fileId, 'state' => DocumentMove::STATE_REFUSED, 'reason' => $e->getMessage()]
			);
			return;
		}

		$reason = null;
		if (isset($run->withoutVersions[$fileId]) === true) {
			$reason = 'moved without its earlier versions';
		}

		$this->record(
			run: $run,
			entry: [
				'kind' => DocumentMove::KIND_FILE,
				'old' => $fileId,
				'new' => (int)$copy->getId(),
				'sha' => $sha,
				'state' => DocumentMove::STATE_VERIFIED,
				'reason' => $reason,
			]
		);
		$run->map(old: $file, new: $copy, kind: DocumentReferenceMap::KIND_FILE);
		$run->outcome->files++;
	}//end copyFile()

	/**
	 * The copy an earlier run verified, when it is still there.
	 *
	 * @param File            $file   The source file.
	 * @param Folder          $parent Where its copy goes.
	 * @param DocumentCopyRun $run    The register's run.
	 *
	 * @return File|null
	 */
	private function verifiedCopy(File $file, Folder $parent, DocumentCopyRun $run): ?File {
		$row = ($run->rows[DocumentCopyRun::key(kind: DocumentMove::KIND_FILE, oldId: (int)$file->getId())] ?? null);
		if ($row === null || $row->getState() !== DocumentMove::STATE_VERIFIED || $row->getNewId() === null) {
			return null;
		}

		$copy = $parent->getFirstNodeById((int)$row->getNewId());
		if ($copy instanceof File === false) {
			return null;
		}

		return $copy;
	}//end verifiedCopy()

	/**
	 * The earlier versions to carry, oldest first; null when they stop the register.
	 *
	 * @param File            $file The source file.
	 * @param DocumentCopyRun $run  The register's run.
	 *
	 * @return list<object>|null
	 */
	private function versionsToCarry(File $file, DocumentCopyRun $run): ?array {
		$versions = $this->versions->versionsOf(file: $file);
		if ($versions === [] || $run->keepsVersions === true) {
			return $versions;
		}

		if ($run->allowWithoutVersions === false) {
			$run->outcome->refuse(reason: sprintf(
				'%s: has %d earlier versions and the target keeps none; pass --without-earlier-versions to move without them.',
				$file->getPath(),
				count($versions)
			));
			return null;
		}

		$run->withoutVersions[(int)$file->getId()] = (int)$file->getId();
		$run->outcome->notes[] = sprintf('%s: moved without its %d earlier versions.', $file->getPath(), count($versions));
		return [];
	}//end versionsToCarry()

	/**
	 * Write the earlier versions oldest first, then the current bytes.
	 *
	 * @param File            $file     The source file.
	 * @param Folder          $parent   Where the copy goes.
	 * @param list<object>    $versions The earlier versions, oldest first.
	 * @param DocumentCopyRun $run      The register's run.
	 *
	 * @return File The copy.
	 */
	private function writeCopy(File $file, Folder $parent, array $versions, DocumentCopyRun $run): File {
		$copy = null;
		if ($parent->nodeExists($file->getName()) === true) {
			$copy = $parent->get($file->getName());
		}

		$versionRows = [];
		foreach ($versions as $version) {
			$copy = $this->writeInto(parent: $parent, name: $file->getName(), copy: $copy, stream: $this->versions->read(version: $version));
			$versionRows[] = ['time' => (int)$version->getTimestamp(), 'sha' => self::sha256(stream: $this->versions->read(version: $version))];
		}

		$copy = $this->writeInto(parent: $parent, name: $file->getName(), copy: $copy, stream: $file->fopen('r'));
		foreach ($versionRows as $versionRow) {
			$this->record(
				run: $run,
				entry: [
					'kind' => DocumentMove::KIND_VERSION,
					'old' => (int)$file->getId(),
					'new' => (int)$copy->getId(),
					'sha' => $versionRow['sha'],
					'state' => DocumentMove::STATE_VERIFIED,
					'time' => $versionRow['time'],
				]
			);
			$run->outcome->versions++;
		}

		return $copy;
	}//end writeCopy()

	/**
	 * Write bytes into the copy, creating it on the first write.
	 *
	 * @param Folder    $parent Where the copy goes.
	 * @param string    $name   Its name.
	 * @param Node|null $copy   The copy so far, or a node of that name an earlier run left.
	 * @param resource  $stream The bytes.
	 *
	 * @return File
	 */
	private function writeInto(Folder $parent, string $name, ?Node $copy, $stream): File {
		if ($copy instanceof File === true) {
			$copy->putContent($stream);
			return $copy;
		}

		$created = $parent->newFile($name, $stream);
		if ($created instanceof File === false) {
			throw new RuntimeException('the copy could not be created');
		}

		return $created;
	}//end writeInto()

	/**
	 * Check the copy's earlier versions against the source's, oldest first.
	 *
	 * @param list<object> $source The source's earlier versions, oldest first.
	 * @param File         $copy   The copy.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a version is missing or differs.
	 */
	private function verifyVersions(array $source, File $copy): void {
		if ($source === []) {
			return;
		}

		$copied = $this->versions->versionsOf(file: $copy);
		if (count($copied) < count($source)) {
			throw new RuntimeException(sprintf('%d of its %d earlier versions were kept by the target', count($copied), count($source)));
		}

		foreach ($source as $index => $version) {
			if (self::sha256(stream: $this->versions->read(version: $version)) !== self::sha256(stream: $this->versions->read(version: $copied[$index]))) {
				throw new RuntimeException(sprintf(
					'the earlier version of %s does not match its source (SHA-256)',
					(new DateTime())->setTimestamp((int)$version->getTimestamp())->format('c')
				));
			}
		}
	}//end verifyVersions()

	/**
	 * Count what a dry run would move and name what would stop it.
	 *
	 * @param Folder                   $folder        A source folder.
	 * @param bool                     $keepsVersions Whether the target keeps earlier versions.
	 * @param DocumentStoreMoveOptions $options       The run's switches.
	 * @param DocumentMoveOutcome      $outcome       Counts and refusals.
	 *
	 * @return void
	 */
	private function survey(Folder $folder, bool $keepsVersions, DocumentStoreMoveOptions $options, DocumentMoveOutcome $outcome): void {
		$outcome->folders++;
		$this->surveyShares(node: $folder, outcome: $outcome);
		foreach ($folder->getDirectoryListing() as $child) {
			if ($child instanceof Folder === true) {
				$this->survey(folder: $child, keepsVersions: $keepsVersions, options: $options, outcome: $outcome);
				continue;
			}

			if ($child instanceof File === false) {
				continue;
			}

			$outcome->files++;
			$count = count($this->versions->versionsOf(file: $child));
			$outcome->versions += $count;
			if ($count > 0 && $keepsVersions === false && $options->withoutVersions === false) {
				$outcome->refuse(reason: sprintf('%s: has %d earlier versions and the target keeps none.', $child->getPath(), $count));
			}

			$this->surveyShares(node: $child, outcome: $outcome);
		}
	}//end survey()

	/**
	 * Count a node's shares in a dry run and name a password-protected link.
	 *
	 * @param Node                $node    The node.
	 * @param DocumentMoveOutcome $outcome Counts and refusals.
	 *
	 * @return void
	 */
	private function surveyShares(Node $node, DocumentMoveOutcome $outcome): void {
		foreach ($this->sharesOf(node: $node) as $share) {
			$outcome->shares++;
			if ((int)$share->getShareType() === IShare::TYPE_LINK && (string)$share->getPassword() !== '') {
				$outcome->refuse(reason: sprintf('%s: published through a password-protected link.', $node->getPath()));
			}
		}
	}//end surveyShares()

	/**
	 * The user, group and link shares of a node.
	 *
	 * @param Node $node The node.
	 *
	 * @return list<IShare>
	 */
	private function sharesOf(Node $node): array {
		$found = [];
		foreach (self::SHARE_TYPES as $type) {
			foreach ($this->platform->shares->getSharesBy(DocumentStore::ACCOUNT, $type, $node, true, -1) as $share) {
				$found[] = $share;
			}
		}

		return $found;
	}//end sharesOf()

	/**
	 * Recreate one share on the copy, then remove the old one; a link keeps its token.
	 *
	 * @param IShare $share The old share.
	 * @param Node   $node  The copy.
	 *
	 * @return IShare The new share.
	 *
	 * @throws RuntimeException When a link share cannot keep its token.
	 */
	private function recreateShare(IShare $share, Node $node): IShare {
		$new = $this->platform->shares->newShare();
		$new->setNode($node);
		$new->setShareType($share->getShareType());
		$new->setSharedBy($share->getSharedBy());
		$new->setShareOwner($share->getShareOwner());
		$new->setPermissions($share->getPermissions());
		if ($share->getSharedWith() !== null) {
			$new->setSharedWith($share->getSharedWith());
		}

		$new->setExpirationDate($share->getExpirationDate());
		$new->setLabel($share->getLabel());
		$new->setNote($share->getNote());
		$new->setHideDownload($share->getHideDownload());

		$created = $this->platform->shares->createShare($new);
		$this->platform->shares->deleteShare($share);
		if ((int)$share->getShareType() !== IShare::TYPE_LINK) {
			return $created;
		}

		$token = (string)$share->getToken();
		if ((string)$created->getToken() !== $token) {
			$created->setToken($token);
			$created = $this->platform->shares->updateShare($created);
		}

		if ((string)$created->getToken() !== $token) {
			throw new RuntimeException(sprintf('the link could not keep its token; it is now %s', (string)$created->getToken()));
		}

		return $created;
	}//end recreateShare()

	/**
	 * Copy a node's system tags to its copy.
	 *
	 * @param int $oldId The node.
	 * @param int $newId Its copy.
	 *
	 * @return void
	 */
	private function copyTags(int $oldId, int $newId): void {
		$tags = $this->platform->tags->getTagIdsForObjects([(string)$oldId], self::TAG_TYPE);
		$tagIds = ($tags[(string)$oldId] ?? ($tags[$oldId] ?? []));
		if ($tagIds !== []) {
			$this->platform->tags->assignTags((string)$newId, self::TAG_TYPE, $tagIds);
		}
	}//end copyTags()

	/**
	 * Replace one old id with its new id in one column.
	 *
	 * @param array{table: string, column: string, kind: string, where: array<string, string>} $column The column.
	 * @param int                                                                            $oldId  The old node id.
	 * @param int                                                                            $newId  The new node id.
	 *
	 * @return void
	 */
	private function rewriteColumn(array $column, int $oldId, int $newId): void {
		$qb = $this->platform->db->getQueryBuilder();
		$type = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT;
		$old = $oldId;
		$new = $newId;
		if ($column['kind'] === DocumentReferenceMap::KIND_FOLDER) {
			$type = \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR;
			$old = (string)$oldId;
			$new = (string)$newId;
		}

		$qb->update($column['table'])
			->set($column['column'], $qb->createNamedParameter($new, $type))
			->where($qb->expr()->eq($column['column'], $qb->createNamedParameter($old, $type)));
		foreach ($column['where'] as $field => $value) {
			$qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));
		}

		$qb->executeStatement();
	}//end rewriteColumn()

	/**
	 * Record or update one mapping row.
	 *
	 * @param DocumentCopyRun                                                                                          $run   The register's run.
	 * @param array{kind: string, old: int, new?: int, sha?: string, state: string, reason?: string|null, time?: int} $entry The row.
	 *
	 * @return void
	 */
	private function record(DocumentCopyRun $run, array $entry): void {
		$key = DocumentCopyRun::key(kind: $entry['kind'], oldId: $entry['old'], time: ($entry['time'] ?? null));
		$row = ($run->rows[$key] ?? new DocumentMove());
		$row->setRegisterId($run->registerId);
		$row->setKind($entry['kind']);
		$row->setOldId($entry['old']);
		$row->setNewId(($entry['new'] ?? null));
		$row->setSha256(($entry['sha'] ?? null));
		$row->setState($entry['state']);
		$row->setReason(($entry['reason'] ?? null));
		$row->setVersionTime(($entry['time'] ?? null));
		$row->setMovedAt(new DateTime());
		if ($row->getId() === null) {
			$run->rows[$key] = $this->moves->insert($row);
			return;
		}

		$run->rows[$key] = $this->moves->update($row);
	}//end record()

	/**
	 * A register's mapping rows by key.
	 *
	 * @param int $registerId The register.
	 *
	 * @return array<string, DocumentMove>
	 */
	private function indexRows(int $registerId): array {
		$rows = [];
		foreach ($this->moves->forRegister(registerId: $registerId) as $row) {
			$rows[DocumentCopyRun::key(kind: (string)$row->getKind(), oldId: (int)$row->getOldId(), time: $row->getVersionTime())] = $row;
		}

		return $rows;
	}//end indexRows()

	/**
	 * Whether an earlier run switched the register (its folder row is switched).
	 *
	 * @param int $registerId The register.
	 * @param int $folderId   The id its folder column holds: the old folder, or the copy after a switch.
	 *
	 * @return bool
	 */
	private function isSwitched(int $registerId, int $folderId): bool {
		// The register's folder column follows the switch, so a rerun finds the
		// copy's id there: a switched folder row matches on either side.
		foreach ($this->moves->forRegister(registerId: $registerId) as $row) {
			if ($row->getKind() !== DocumentMove::KIND_FOLDER || $row->getState() !== DocumentMove::STATE_SWITCHED) {
				continue;
			}

			if ((int)$row->getOldId() === $folderId || (int)$row->getNewId() === $folderId) {
				return true;
			}
		}

		return false;
	}//end isSwitched()

	/**
	 * Collect the paths of the nodes in a tree that have no switched copy.
	 *
	 * @param Folder           $folder   The tree.
	 * @param array<int, true> $switched Old node ids with a switched copy.
	 * @param list<string>     $unmapped The paths found so far.
	 *
	 * @return void
	 */
	private function collectUnmapped(Folder $folder, array $switched, array &$unmapped): void {
		foreach ($folder->getDirectoryListing() as $child) {
			if (isset($switched[(int)$child->getId()]) === false) {
				$unmapped[] = $child->getPath();
			}

			if ($child instanceof Folder === true) {
				$this->collectUnmapped(folder: $child, switched: $switched, unmapped: $unmapped);
			}
		}
	}//end collectUnmapped()

	/**
	 * A register's folder, or null when it has none on the current root.
	 *
	 * @param Register $register The register.
	 *
	 * @return Folder|null
	 */
	private function registerFolder(Register $register): ?Folder {
		$folder = $register->getFolder();
		if (is_string($folder) === false || ctype_digit($folder) === false) {
			return null;
		}

		$node = $this->home()->getFirstNodeById((int)$folder);
		if ($node instanceof Folder === false) {
			return null;
		}

		return $node;
	}//end registerFolder()

	/**
	 * Every register, in system context.
	 *
	 * @return list<Register>
	 */
	private function allRegisters(): array {
		return array_values($this->registers->findAll(_rbac: false, _multitenancy: false));
	}//end allRegisters()

	/**
	 * A name in the home, suffixed when it is taken.
	 *
	 * @param string $name   The wanted name.
	 * @param string $parent The parent path in the home, with trailing slash, or empty.
	 *
	 * @return string
	 */
	private function freeName(string $name, string $parent): string {
		$candidate = $name;
		$suffix = 2;
		while ($this->home()->nodeExists($parent . $candidate) === true) {
			$candidate = sprintf('%s %d', $name, $suffix);
			$suffix++;
		}

		return $candidate;
	}//end freeName()

	/**
	 * The openregister account's home.
	 *
	 * @return Folder
	 */
	private function home(): Folder {
		return $this->platform->rootFolder->getUserFolder(DocumentStore::ACCOUNT);
	}//end home()

	/**
	 * The SHA-256 of a stream, read to its end and closed.
	 *
	 * @param resource|false $stream The stream.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the stream cannot be read.
	 */
	private static function sha256($stream): string {
		if (is_resource($stream) === false) {
			throw new RuntimeException('the bytes cannot be read');
		}

		$context = hash_init('sha256');
		hash_update_stream($context, $stream);
		fclose($stream);
		return hash_final($context);
	}//end sha256()
}//end class
