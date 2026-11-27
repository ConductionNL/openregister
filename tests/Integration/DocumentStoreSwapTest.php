<?php

/**
 * The document store is swapped on a real Nextcloud, and nothing a reader holds breaks.
 *
 * REQ-RDS-005: against a real Nextcloud with the External storage app, create an
 * object with a file in a register on the default store, publish the file
 * through a link share, tag it and give it an earlier version; mount a second
 * store with the `local` backend for the `openregister` account; run
 * `occ openregister:document-store:move`; then assert that the object's file
 * property and the register and object folders point at nodes on the new
 * storage, that the bytes read through OpenRegister's file API equal the
 * original, that the public link with the same token serves the same bytes,
 * that the tag and the earlier version are present, that status names the new
 * store as separate, and that the next upload lands on the new storage.
 *
 * This test FAILS, never skips, without a Nextcloud: the requirement is that the
 * swap is proven, and a skipped proof reads as a passed one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\DocumentMove;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Controller\FilesController;
use OCA\OpenRegister\Service\File\DocumentMoveOutcome;
use OCA\OpenRegister\Service\File\DocumentStore;
use OCA\OpenRegister\Service\File\DocumentStoreMove;
use OCA\OpenRegister\Service\File\DocumentVersions;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Http\StreamResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The swap scenario of REQ-RDS-005, end to end.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md#requirement-swapping-the-store-is-proven-end-to-end-req-rds-005
 */
class DocumentStoreSwapTest extends TestCase {

	/**
	 * The bytes of the earlier version.
	 */
	private const EARLIER = "the earlier version of the swapped document\n";

	/**
	 * The bytes of the current version.
	 */
	private const CURRENT = "the current version of the swapped document\n";

	/**
	 * The bytes of the upload after the swap.
	 */
	private const AFTER = "uploaded after the store was swapped\n";

	/**
	 * The file-typed property of the fixture schema.
	 */
	private const PROPERTY = 'document';

	/**
	 * Short random suffix that keeps this run's names apart from any other.
	 *
	 * @var string
	 */
	private string $suffix = '';

	/**
	 * The External storage mount id, for the clean-up.
	 *
	 * @var integer|null
	 */
	private ?int $mountId = null;

	/**
	 * The local directory behind the mount.
	 *
	 * @var string|null
	 */
	private ?string $mountDir = null;

	/**
	 * The document store root before the test.
	 *
	 * @var string|null
	 */
	private ?string $rootBefore = null;

	/**
	 * The highest document move row before the move ran; null until it runs.
	 *
	 * @var integer|null
	 */
	private ?int $movesBefore = null;

	/**
	 * The signed-in officer.
	 *
	 * @var IUser|null
	 */
	private ?IUser $officer = null;

	/**
	 * Rows to delete afterwards: table => ids.
	 *
	 * @var array<string, list<int>>
	 */
	private array $created = ['openregister_schemas' => [], 'openregister_registers' => []];

	/**
	 * Magic tables to drop afterwards.
	 *
	 * @var list<string>
	 */
	private array $tables = [];

	/**
	 * Require a real, installed Nextcloud; fail when there is none.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$serverRoot = '';
		if (class_exists(\OC::class, false) === true && isset(\OC::$SERVERROOT) === true) {
			$serverRoot = (string)\OC::$SERVERROOT;
		}

		if ($serverRoot === '' || is_file($serverRoot . '/occ') === false || class_exists(\OC\Files\SetupManager::class) === false) {
			$this->fail('DocumentStoreSwapTest needs a real, installed Nextcloud (occ and the server tree); it fails rather than skips without one.');
		}

		// The versions, sharing and tag apps listen to file writes only once they are loaded,
		// and a PHPUnit process loads no app by itself: without this the earlier version is never kept.
		\OC::$server->get(\OCP\App\IAppManager::class)->loadApps();

		$this->suffix = substr(str_replace('-', '', Uuid::v4()->toRfc4122()), 0, 8);
		$this->rootBefore = $this->appConfig()->getValueString('openregister', DocumentStore::ROOT_KEY, '');

		$users = \OC::$server->get(IUserManager::class);
		if ($users->userExists(DocumentStore::ACCOUNT) === false) {
			$users->createUser(DocumentStore::ACCOUNT, 'Swap-' . $this->suffix . '-Pass-9!');
		}

		$officer = $users->createUser('swap-officer-' . $this->suffix, 'Swap-' . $this->suffix . '-Pass-9!');
		$this->assertInstanceOf(IUser::class, $officer, 'the officer account could not be created');
		$this->officer = $officer;

		// The reader's own access is not what this test is about (the file guard has its own
		// tests); an administrator reads the object whatever organisation it landed in.
		\OC::$server->get(\OCP\IGroupManager::class)->get('admin')?->addUser($officer);
		\OC::$server->get(IUserSession::class)->setUser($officer);
	}//end setUp()

	/**
	 * Put the instance back: root, renamed old root, mount, fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\OC::$server->get(IUserSession::class)->setUser(null);

		$this->undoTheMove();
		$this->restoreRoot();

		if ($this->mountId !== null) {
			$this->occ(['files_external:delete', '--yes', (string)$this->mountId]);
		}

		if ($this->mountDir !== null && is_dir($this->mountDir) === true) {
			$this->removeDir(dir: $this->mountDir);
		}

		$db = \OC::$server->get(IDBConnection::class);
		foreach ($this->tables as $table) {
			try {
				$db->prepare('DROP TABLE IF EXISTS ' . $table)->execute();
			} catch (\Throwable $e) {
				// Already gone.
			}
		}

		foreach ($this->created as $table => $ids) {
			foreach ($ids as $id) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			}
		}

		$this->officer?->delete();
		$this->resetFilesystem();

		parent::tearDown();
	}//end tearDown()

	/**
	 * The scenario: the store is swapped and nothing a reader holds breaks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md#requirement-swapping-the-store-is-proven-end-to-end-req-rds-005
	 */
	public function testTheStoreIsSwappedAndNothingAReaderHoldsBreaks(): void {
		// GIVEN a published file with an earlier version and a tag in a register on the default store.
		[$register, $schema, $object] = $this->objectInANewRegister();
		$files = $this->fileService();
		$tagName = 'swap-tag-' . $this->suffix;
		$file = $files->addFile(objectEntity: $object, fileName: 'swap-' . $this->suffix . '.txt', content: self::EARLIER, share: true, tags: [$tagName]);
		$this->overwrite(file: $file, bytes: self::CURRENT);
		$oldFileId = (int)$file->getId();
		$object = $this->storeFileProperty(register: $register, schema: $schema, object: $object, fileId: $oldFileId);

		$token = $this->linkToken(file: $file);
		$this->assertSame(self::CURRENT, $this->publicBytes(token: $token), 'fixture: the public link does not serve the file');
		$this->assertContains(self::EARLIER, $this->versionBytes(file: $file), 'fixture: the file has no earlier version to carry');
		$this->assertContains($tagName, $this->tagNames(fileId: $oldFileId), 'fixture: the file is not tagged');
		$this->assertSame(self::CURRENT, $this->apiBytes(register: $register, schema: $schema, object: $object, fileId: $oldFileId), 'fixture: the file API does not serve the file');
		$oldStorage = (string)$file->getStorage()->getId();

		// WHEN a second store is mounted, the move runs, and status is read.
		$target = $this->mountSecondStore();
		$this->movesBefore = $this->highestMoveRow();
		$move = $this->occ(['openregister:document-store:move', '--to', $target]);
		$this->assertSame(0, $move['code'], "the move did not succeed:\n" . $move['output']);
		$this->resetFilesystem();

		$status = $this->occ(['openregister:document-store:status']);
		$this->assertSame(0, $status['code'], "status did not answer:\n" . $status['output']);
		$this->assertStringContainsString('Root:        ' . $target, $status['output'], 'status does not name the new root');
		$this->assertMatchesRegularExpression('/^Separate:\s+yes/m', $status['output'], "status does not name the new store as separate:\n" . $status['output']);
		$described = $this->store()->describe();
		$this->assertSame($target, $described['root'], 'the root did not switch to the new store');
		$this->assertTrue($described['separate'], 'status does not name the new store as separate');
		$newStorage = $described['storageId'];
		$this->assertNotSame($oldStorage, $newStorage, 'the new root is on the old storage');

		// THEN the object's file property and the register and object folders point at the new storage.
		$register = \OC::$server->get(RegisterMapper::class)->find($register->getId(), _multitenancy: false, _rbac: false);
		$object = $this->mapper()->find($object->getUuid(), $register, $schema, _rbac: false, _multitenancy: false);
		$newFileId = (int)($object->getObject()[self::PROPERTY] ?? 0);
		$this->assertNotSame($oldFileId, $newFileId, 'the file property still names the old file');
		$this->assertSame($newStorage, $this->storageOf(nodeId: $newFileId), 'the file property does not point at the new storage');
		$this->assertSame($newStorage, $this->storageOf(nodeId: $this->storedRegisterFolder(registerId: (int)$register->getId())), 'the register folder is not on the new storage');
		$this->assertSame($newStorage, $this->storageOf(nodeId: (int)$object->getFolder()), 'the object folder is not on the new storage');

		// The same OpenRegister reference returns identical bytes through the file API.
		$this->assertSame(self::CURRENT, $this->apiBytes(register: $register, schema: $schema, object: $object, fileId: $newFileId), 'the file API does not return the original bytes');

		// The same public link returns identical bytes.
		$this->resetFilesystem();
		$this->assertSame(self::CURRENT, $this->publicBytes(token: $token), 'the public link with the same token does not serve the same bytes');

		// The tag and the earlier version are present.
		$copy = $this->nodeById(nodeId: $newFileId);
		$this->assertInstanceOf(File::class, $copy);
		$this->assertContains($tagName, $this->tagNames(fileId: $newFileId), 'the tag did not follow the file');
		$this->assertContains(self::EARLIER, $this->versionBytes(file: $copy), 'the earlier version did not follow the file');

		// The next upload lands on the new store.
		$object = $this->mapper()->find($object->getUuid(), $register, $schema, _rbac: false, _multitenancy: false);
		$after = $this->fileService()->addFile(objectEntity: $object, fileName: 'after-' . $this->suffix . '.txt', content: self::AFTER);
		$this->assertSame($newStorage, (string)$after->getStorage()->getId(), 'the next upload did not land on the new store');
		$this->assertSame(self::AFTER, $after->getContent());
	}//end testTheStoreIsSwappedAndNothingAReaderHoldsBreaks()

	/**
	 * A register with one schema that has a file-typed property, and one object in it.
	 *
	 * @return array{0: Register, 1: Schema, 2: ObjectEntity}
	 */
	private function objectInANewRegister(): array {
		$schema = \OC::$server->get(SchemaMapper::class)->createFromArray(
			[
				'title' => 'Swap schema ' . $this->suffix,
				'description' => 'DocumentStoreSwapTest',
				'properties' => [
					'title' => ['type' => 'string', 'title' => 'Title', 'maxLength' => 255],
					self::PROPERTY => ['type' => 'file', 'title' => 'Document'],
				],
			]
		);
		$this->created['openregister_schemas'][] = (int)$schema->getId();

		$register = \OC::$server->get(RegisterMapper::class)->createFromArray(
			[
				'title' => 'Swap register ' . $this->suffix,
				'description' => 'DocumentStoreSwapTest',
				'schemas' => [(int)$schema->getId()],
			]
		);
		$this->created['openregister_registers'][] = (int)$register->getId();

		$mapper = $this->mapper();
		$mapper->ensureTableForRegisterSchema($register, $schema);
		$this->tables[] = 'oc_' . $mapper->getTableNameForRegisterSchema($register, $schema);

		$entity = new ObjectEntity();
		$entity->setUuid(Uuid::v4()->toRfc4122());
		$entity->setRegister((string)$register->getId());
		$entity->setSchema((string)$schema->getId());
		$entity->setObject(['title' => 'Swapped ' . $this->suffix]);
		$entity->setOwner($this->officer?->getUID());
		$object = $mapper->insertObjectEntity($entity, $register, $schema, false);

		return [$register, $schema, $object];
	}//end objectInANewRegister()

	/**
	 * Store the file's id in the object's file-typed property.
	 *
	 * @param Register     $register The register.
	 * @param Schema       $schema   The schema.
	 * @param ObjectEntity $object   The object.
	 * @param int          $fileId   The file id.
	 *
	 * @return ObjectEntity The object as stored.
	 */
	private function storeFileProperty(Register $register, Schema $schema, ObjectEntity $object, int $fileId): ObjectEntity {
		$mapper = $this->mapper();
		$stored = $mapper->find($object->getUuid(), $register, $schema, _rbac: false, _multitenancy: false);
		$old = clone $stored;
		$stored->setObject(array_merge($stored->getObject(), [self::PROPERTY => $fileId]));
		$updated = $mapper->updateObjectEntity($stored, $register, $schema, $old);
		$this->assertSame($fileId, (int)($updated->getObject()[self::PROPERTY] ?? 0), 'fixture: the file property was not stored');
		$this->assertNotEmpty($updated->getFolder(), 'fixture: the object has no folder');

		return $updated;
	}//end storeFileProperty()

	/**
	 * Mount a second store with the `local` backend for the openregister account.
	 *
	 * @return string The mount point in the openregister account's home.
	 */
	private function mountSecondStore(): string {
		foreach ([
			['app:enable', 'files_external'],
			['config:system:set', 'files_external_allow_create_new_local', '--value=true', '--type=boolean'],
		] as $command) {
			$result = $this->occ($command);
			$this->assertSame(0, $result['code'], implode(' ', $command) . " failed:\n" . $result['output']);
		}

		$this->mountDir = rtrim(sys_get_temp_dir(), '/') . '/or-document-store-' . $this->suffix;
		$this->assertTrue(mkdir($this->mountDir, 0770, true), 'the mount directory could not be created');

		$mountPoint = 'Document store ' . $this->suffix;
		$created = $this->occ(['files_external:create', '/' . $mountPoint, 'local', 'null::null', '-c', 'datadir=' . $this->mountDir, '--output=json']);
		$this->assertSame(0, $created['code'], "files_external:create failed:\n" . $created['output']);
		$this->mountId = (int)trim($created['output']);
		$this->assertGreaterThan(0, $this->mountId, "files_external:create named no mount:\n" . $created['output']);

		$applicable = $this->occ(['files_external:applicable', '--add-user=' . DocumentStore::ACCOUNT, (string)$this->mountId]);
		$this->assertSame(0, $applicable['code'], "files_external:applicable failed:\n" . $applicable['output']);

		// An administrator's mount allows no sharing unless it is switched on, and
		// without it the move refuses the register rather than drop its public link.
		$sharing = $this->occ(['files_external:option', (string)$this->mountId, 'enable_sharing', 'true']);
		$this->assertSame(0, $sharing['code'], "files_external:option enable_sharing failed:\n" . $sharing['output']);

		$this->resetFilesystem();
		$this->assertTrue(
			\OC::$server->get(IRootFolder::class)->getUserFolder(DocumentStore::ACCOUNT)->nodeExists($mountPoint),
			'the new store is not mounted in the openregister account\'s home'
		);

		return $mountPoint;
	}//end mountSecondStore()

	/**
	 * The token of the file's link share.
	 *
	 * @param File $file The file.
	 *
	 * @return string
	 */
	private function linkToken(File $file): string {
		$shares = \OC::$server->get(IShareManager::class)->getSharesBy(DocumentStore::ACCOUNT, IShare::TYPE_LINK, $file, false, -1);
		$this->assertNotEmpty($shares, 'fixture: the file has no link share');

		return (string)$shares[0]->getToken();
	}//end linkToken()

	/**
	 * The bytes the public link serves: the share resolved by its token, as the public share page resolves it.
	 *
	 * @param string $token The share token.
	 *
	 * @return string
	 */
	private function publicBytes(string $token): string {
		$session = \OC::$server->get(IUserSession::class);
		$signedIn = $session->getUser();
		$session->setUser(null);
		try {
			$node = \OC::$server->get(IShareManager::class)->getShareByToken($token)->getNode();
			$this->assertInstanceOf(File::class, $node, 'the link does not resolve to a file');
			return $node->getContent();
		} finally {
			$session->setUser($signedIn);
		}
	}//end publicBytes()

	/**
	 * Overwrite a file the way a person's later save does, so the earlier bytes become a version.
	 *
	 * The write runs through `occ files:put` in its own process: the versions
	 * app keeps an earlier version through the default filesystem view of the
	 * owner, which a PHPUnit process does not set up for the openregister
	 * account. The pause keeps the new modification time apart from the old
	 * one, which names the version.
	 *
	 * @param File   $file  The file.
	 * @param string $bytes The new bytes.
	 *
	 * @return void
	 */
	private function overwrite(File $file, string $bytes): void {
		sleep(1);
		$local = tempnam(sys_get_temp_dir(), 'or-swap-');
		file_put_contents($local, $bytes);
		try {
			$put = $this->occ(['files:put', $local, $file->getPath()]);
			$this->assertSame(0, $put['code'], "fixture: files:put failed:\n" . $put['output']);
		} finally {
			unlink($local);
		}

		$this->resetFilesystem();
	}//end overwrite()

	/**
	 * The bytes OpenRegister's file API streams for the object's file, as the officer.
	 *
	 * @param Register     $register The register.
	 * @param Schema       $schema   The schema.
	 * @param ObjectEntity $object   The object.
	 * @param int          $fileId   The file id.
	 *
	 * @return string
	 */
	private function apiBytes(Register $register, Schema $schema, ObjectEntity $object, int $fileId): string {
		$response = \OC::$server->get(FilesController::class)->show(
			(string)$register->getId(),
			(string)$schema->getId(),
			(string)$object->getUuid(),
			$fileId
		);
		$this->assertInstanceOf(StreamResponse::class, $response, 'the file API refused the read: ' . json_encode(method_exists($response, 'getData') === true ? $response->getData() : null));

		$stream = (new \ReflectionProperty(StreamResponse::class, 'filePath'))->getValue($response);
		if (is_resource($stream) === true) {
			return (string)stream_get_contents($stream);
		}

		return (string)file_get_contents((string)$stream);
	}//end apiBytes()

	/**
	 * The bytes of every earlier version of a file.
	 *
	 * @param File $file The file.
	 *
	 * @return list<string>
	 */
	private function versionBytes(File $file): array {
		$versions = \OC::$server->get(DocumentVersions::class);
		$bytes = [];
		foreach ($versions->versionsOf($file) as $version) {
			$bytes[] = (string)stream_get_contents($versions->read($version));
		}

		return $bytes;
	}//end versionBytes()

	/**
	 * The names of the system tags on a file.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return list<string>
	 */
	private function tagNames(int $fileId): array {
		$tagIds = \OC::$server->get(ISystemTagObjectMapper::class)->getTagIdsForObjects([(string)$fileId], 'files')[(string)$fileId] ?? [];
		if ($tagIds === []) {
			return [];
		}

		return array_values(array_map(static fn ($tag): string => $tag->getName(), \OC::$server->get(\OCP\SystemTag\ISystemTagManager::class)->getTagsByIds($tagIds)));
	}//end tagNames()

	/**
	 * The storage id of a node in the openregister account's home.
	 *
	 * @param int $nodeId The node id.
	 *
	 * @return string
	 */
	private function storageOf(int $nodeId): string {
		$node = $this->nodeById(nodeId: $nodeId);
		$this->assertNotNull($node, sprintf('node %d is not in the openregister account\'s home', $nodeId));

		return (string)$node->getStorage()->getId();
	}//end storageOf()

	/**
	 * A node in the openregister account's home by id.
	 *
	 * @param int $nodeId The node id.
	 *
	 * @return Node|null
	 */
	private function nodeById(int $nodeId): ?Node {
		return \OC::$server->get(IRootFolder::class)->getUserFolder(DocumentStore::ACCOUNT)->getFirstNodeById($nodeId);
	}//end nodeById()

	/**
	 * Run an occ command in its own process, as the move's operator does.
	 *
	 * @param list<string> $arguments The command and its arguments.
	 *
	 * @return array{code: int, output: string}
	 */
	private function occ(array $arguments): array {
		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(\OC::$SERVERROOT . '/occ') . ' --no-interaction --no-ansi';
		foreach ($arguments as $argument) {
			$command .= ' ' . escapeshellarg($argument);
		}

		$output = [];
		$code = 1;
		exec($command . ' 2>&1', $output, $code);

		return ['code' => $code, 'output' => implode("\n", $output)];
	}//end occ()

	/**
	 * Forget this process's mounts and cached app config, which the occ processes changed.
	 *
	 * A lazy setup after the tear-down would read the mounts this process
	 * already cached, so the openregister account is set up in full again.
	 *
	 * @return void
	 */
	private function resetFilesystem(): void {
		$setup = \OC::$server->get(\OC\Files\SetupManager::class);
		$setup->tearDown();
		$this->appConfig()->clearCache();
		$account = \OC::$server->get(IUserManager::class)->get(DocumentStore::ACCOUNT);
		if ($account !== null) {
			$setup->setupForUser($account);
		}
	}//end resetFilesystem()

	/**
	 * The register's folder id as stored, past any entity cache of this process.
	 *
	 * @param int $registerId The register id.
	 *
	 * @return int
	 */
	private function storedRegisterFolder(int $registerId): int {
		$qb = \OC::$server->get(IDBConnection::class)->getQueryBuilder();
		$qb->select('folder')->from('openregister_registers')->where($qb->expr()->eq('id', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
		$folder = $qb->executeQuery()->fetchOne();

		return (int)$folder;
	}//end storedRegisterFolder()

	/**
	 * The highest id in the document move mapping.
	 *
	 * @return int
	 */
	private function highestMoveRow(): int {
		$qb = \OC::$server->get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from('openregister_document_moves');

		return (int)$qb->executeQuery()->fetchOne();
	}//end highestMoveRow()

	/**
	 * Point every reference the move rewrote back at the original node, and forget the run's mapping.
	 *
	 * The instance outlives this test: other registers moved with the fixture,
	 * and their references would otherwise name nodes on a store the clean-up
	 * unmounts. The original tree is still there (the move copies, and only
	 * renames it), so the move's own rewrite is run with the mapping reversed.
	 *
	 * @return void
	 */
	private function undoTheMove(): void {
		if ($this->movesBefore === null) {
			return;
		}

		$db = \OC::$server->get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select('register_id', 'old_id', 'new_id', 'kind')
			->from('openregister_document_moves')
			->where($qb->expr()->gt('id', $qb->createNamedParameter($this->movesBefore, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(DocumentMove::STATE_SWITCHED)))
			->andWhere($qb->expr()->in('kind', $qb->createNamedParameter([DocumentMove::KIND_FILE, DocumentMove::KIND_FOLDER], IQueryBuilder::PARAM_STR_ARRAY)));
		$back = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$registerId = (int)$row['register_id'];
			$back[$registerId]['moved'][(int)$row['new_id']] = (int)$row['old_id'];
			$back[$registerId]['kinds'][(int)$row['new_id']] = (string)$row['kind'];
		}

		$move = \OC::$server->get(DocumentStoreMove::class);
		$registers = \OC::$server->get(RegisterMapper::class);
		foreach ($back as $registerId => $copy) {
			try {
				$register = $registers->find($registerId, _multitenancy: false, _rbac: false);
			} catch (\Throwable $e) {
				continue;
			}

			$outcome = new DocumentMoveOutcome(registerId: $registerId, name: (string)$register->getTitle(), state: DocumentMoveOutcome::SWITCHED);
			$move->rewriteReferences(register: $register, copy: ['moved' => $copy['moved'], 'kinds' => $copy['kinds'], 'withoutVersions' => []], outcome: $outcome);
		}

		$delete = $db->getQueryBuilder();
		$delete->delete('openregister_document_moves')
			->where($delete->expr()->gt('id', $delete->createNamedParameter($this->movesBefore, IQueryBuilder::PARAM_INT)));
		$delete->executeStatement();
	}//end undoTheMove()

	/**
	 * Point the root back where it was and give the renamed old root its name again.
	 *
	 * @return void
	 */
	private function restoreRoot(): void {
		$config = $this->appConfig();
		$config->clearCache();
		$movedFrom = $config->getValueString('openregister', DocumentStoreMove::MOVED_FROM_KEY, '');
		if ($movedFrom === '') {
			return;
		}

		$this->resetFilesystem();
		$home = \OC::$server->get(IRootFolder::class)->getUserFolder(DocumentStore::ACCOUNT);
		$original = DocumentStore::DEFAULT_ROOT;
		if ($this->rootBefore !== null && $this->rootBefore !== '') {
			$original = $this->rootBefore;
		}

		if ($home->nodeExists($movedFrom) === true && $home->nodeExists($original) === false) {
			$home->get($movedFrom)->move($home->getPath() . '/' . $original);
		}

		if ($this->rootBefore === null || $this->rootBefore === '') {
			$config->deleteKey('openregister', DocumentStore::ROOT_KEY);
		} else {
			$config->setValueString('openregister', DocumentStore::ROOT_KEY, $this->rootBefore);
		}

		$config->deleteKey('openregister', DocumentStoreMove::MOVED_FROM_KEY);
	}//end restoreRoot()

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir The directory.
	 *
	 * @return void
	 */
	private function removeDir(string $dir): void {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			if ($item->isDir() === true) {
				rmdir($item->getPathname());
				continue;
			}

			unlink($item->getPathname());
		}

		rmdir($dir);
	}//end removeDir()

	/**
	 * The magic mapper.
	 *
	 * @return MagicMapper
	 */
	private function mapper(): MagicMapper {
		return \OC::$server->get(MagicMapper::class);
	}//end mapper()

	/**
	 * The file service.
	 *
	 * @return FileService
	 */
	private function fileService(): FileService {
		return \OC::$server->get(FileService::class);
	}//end fileService()

	/**
	 * The document store.
	 *
	 * @return DocumentStore
	 */
	private function store(): DocumentStore {
		return \OC::$server->get(DocumentStore::class);
	}//end store()

	/**
	 * The app config.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		return \OC::$server->get(IAppConfig::class);
	}//end appConfig()
}//end class
