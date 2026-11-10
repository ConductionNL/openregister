<?php

/**
 * Golden behaviour of FolderManagementHandler's stored-folder lookups.
 *
 * Pins, per stored folder value, whether the register or object folder is
 * found or (re)created and which record is logged; the user-folder then
 * root-folder node lookup with its debug and error records; and the
 * stored-folder probe used before creating a folder.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Files\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\ObjectFolderOptions;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use ReflectionProperty;
use Stringable;

/**
 * Stored-folder lookups, golden.
 *
 * @covers \OCA\OpenRegister\Service\File\FolderManagementHandler
 */
final class FolderManagementHandlerFolderLookupGoldenTest extends TestCase {

	/**
	 * Every log record, as [level, message].
	 *
	 * @var array<int, array{0: mixed, 1: string}>
	 */
	private array $records = [];

	/**
	 * Every folder creation, as [method, subject, registerId option].
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: mixed}>
	 */
	private array $creations = [];

	/**
	 * The folder a creation hands back.
	 *
	 * @var Folder&MockObject
	 */
	private Folder $created;

	/**
	 * A handler whose user folder and root folder answer node 42 as given, and whose creations only record.
	 *
	 * @param array<int, mixed>|Exception $userNodes What the user folder answers, or throws.
	 * @param array<int, mixed>|Exception $rootNodes What the root folder answers, or throws.
	 * @param bool                        $failNode  Whether getNodeById itself throws.
	 *
	 * @return FolderManagementHandler&MockObject The handler.
	 */
	private function handler(array|Exception $userNodes = [], array|Exception $rootNodes = [], bool $failNode = false): FolderManagementHandler {
		$root = $this->createMock(IRootFolder::class);
		self::answer(mock: $root, nodes: $rootNodes);
		$userFolder = $this->createMock(Folder::class);
		self::answer(mock: $userFolder, nodes: $userNodes);
		$logger = new class ($this->records) extends AbstractLogger {
			/**
			 * @param array $records The test's record list.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->records[] = [$level, (string) $message];
			}
		};

		$methods = ['createRegisterFolderById', 'createObjectFolderById', 'getOpenRegisterUserFolder'];
		if ($failNode === true) {
			$methods[] = 'getNodeById';
		}

		$handler = $this->getMockBuilder(FolderManagementHandler::class)
			->setConstructorArgs(
				[
					$root,
					$this->createMock(MagicMapper::class),
					$this->createMock(RegisterMapper::class),
					$this->createMock(IUserSession::class),
					$this->createMock(IGroupManager::class),
					$logger,
					$this->createMock(AuditTrailMapper::class),
					$this->createMock(IUserMountCache::class),
					$this->createMock(RegisterFolderRecorder::class),
				]
			)
			->onlyMethods($methods)
			->getMock();
		$this->created = $this->createMock(Folder::class);
		$handler->method('getOpenRegisterUserFolder')->willReturn($userFolder);
		$handler->method('createRegisterFolderById')->willReturnCallback(
			function (Register $register): Folder {
				$this->creations[] = ['register', $register->getId(), null];

				return $this->created;
			}
		);
		$handler->method('createObjectFolderById')->willReturnCallback(
			function (ObjectEntity|string $object, ?ObjectFolderOptions $options = null): Folder {
				$this->creations[] = ['object', ($object instanceof ObjectEntity) ? $object->getId() : $object, $options?->registerId];

				return $this->created;
			}
		);
		if ($failNode === true) {
			$handler->method('getNodeById')->willThrowException(new Exception('node lookup broke'));
		}

		return $handler;
	}//end handler()

	/**
	 * Make a folder-like mock answer getById() with the nodes, or throw.
	 *
	 * @param MockObject                  $mock  The root or user folder.
	 * @param array<int, mixed>|Exception $nodes What it answers.
	 *
	 * @return void
	 */
	private static function answer(MockObject $mock, array|Exception $nodes): void {
		if ($nodes instanceof Exception) {
			$mock->method('getById')->willThrowException($nodes);
			return;
		}

		$mock->method('getById')->willReturn($nodes);
	}//end answer()

	/**
	 * A register with id 3 and the given stored folder.
	 *
	 * @param string|null $folder The stored folder.
	 *
	 * @return Register The register.
	 */
	private static function register(?string $folder): Register {
		$register = new Register();
		(new ReflectionProperty($register, 'id'))->setValue($register, 3);
		if ($folder !== null) {
			$register->setFolder($folder);
		}

		return $register;
	}//end register()

	/**
	 * An object with id 9 and the given stored folder.
	 *
	 * @param string|null $folder The stored folder.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(?string $folder): ObjectEntity {
		$object = new ObjectEntity();
		(new ReflectionProperty($object, 'id'))->setValue($object, 9);
		if ($folder !== null) {
			$object->setFolder($folder);
		}

		return $object;
	}//end object()

	/**
	 * Each stored register folder value: found, or created with the record that says why.
	 *
	 * @return void
	 */
	public function testTheRegisterFolderIsFoundOrCreated(): void {
		$found = $this->createMock(Folder::class);
		$cases = [
			'unset' => [null, [], 'created', [['debug', '[FolderManagementHandler] Register 3 has legacy folder property, creating new folder']]],
			'empty' => ['', [], 'created', [['debug', '[FolderManagementHandler] Register 3 has legacy folder property, creating new folder']]],
			'path' => ['Open Registers/Intake', [], 'created', [['warning', '[FolderManagementHandler] Invalid folder ID type for register 3, creating new folder']]],
			'found' => ['42', [$found], 'found', []],
			'not a folder' => ['42', [$this->createMock(File::class)], 'created', [['warning', '[FolderManagementHandler] Register 3 has invalid folder ID, recreating folder']]],
		];
		foreach ($cases as $name => [$stored, $nodes, $outcome, $records]) {
			$this->records = [];
			$this->creations = [];
			$folder = $this->handler(userNodes: $nodes)->getRegisterFolderById(self::register(folder: $stored));

			$this->assertSame($outcome === 'found' ? $found : $this->created, $folder, $name);
			$this->assertSame($outcome === 'found' ? [] : [['register', 3, null]], $this->creations, $name);
			$this->assertSame($records, $this->records, $name);
		}
	}//end testTheRegisterFolderIsFoundOrCreated()

	/**
	 * Each stored object folder value: found, or created with the record that says why.
	 *
	 * @return void
	 */
	public function testTheObjectFolderIsFoundOrCreated(): void {
		$found = $this->createMock(Folder::class);
		$legacy = '[FolderManagementHandler] Object 9 has legacy folder property, creating new folder';
		$cases = [
			'bare uuid' => ['uuid-1', [], [['object', 'uuid-1', 5]], [['debug', '[FolderManagementHandler] Object uuid-1 has legacy folder property, creating new folder']]],
			'unset' => [self::object(folder: null), [], [['object', 9, 5]], [['debug', $legacy]]],
			'empty' => [self::object(folder: ''), [], [['object', 9, 5]], [['debug', $legacy]]],
			'path' => [self::object(folder: 'Open Registers/Intake/9'), [], [['object', 9, 5]], [['debug', $legacy]]],
			'found' => [self::object(folder: '42'), [$found], [], []],
			'not a folder' => [self::object(folder: '42'), [$this->createMock(File::class)], [['object', 9, null]], [['warning', '[FolderManagementHandler] Object 9 has invalid folder ID, recreating folder']]],
		];
		foreach ($cases as $name => [$object, $nodes, $creations, $records]) {
			$this->records = [];
			$this->creations = [];
			$folder = $this->handler(userNodes: $nodes)->getObjectFolder($object, 5);

			$this->assertSame($creations === [] ? $found : $this->created, $folder, $name);
			$this->assertSame($creations, $this->creations, $name);
			$this->assertSame($records, $this->records, $name);
		}
	}//end testTheObjectFolderIsFoundOrCreated()

	/**
	 * The user folder is asked first; a failure there is a debug record and the root folder is asked.
	 *
	 * @return void
	 */
	public function testANodeIsLookedUpInTheUserFolderThenTheRoot(): void {
		$inUser = $this->createMock(Node::class);
		$inRoot = $this->createMock(Node::class);

		$this->assertSame($inUser, $this->handler(userNodes: [$inUser], rootNodes: [$inRoot])->getNodeById(42));
		$this->assertSame($inRoot, $this->handler(userNodes: [], rootNodes: [$inRoot])->getNodeById(42));
		$this->assertSame([], $this->records);

		$this->assertSame($inRoot, $this->handler(userNodes: new Exception('no home'), rootNodes: [$inRoot])->getNodeById(42));
		$this->assertSame([['debug', '[FolderManagementHandler] User folder lookup failed for node 42: no home']], $this->records);

		$this->records = [];
		$this->assertNull($this->handler(userNodes: [], rootNodes: new Exception('root down'))->getNodeById(42));
		$this->assertSame([['error', '[FolderManagementHandler] Root folder lookup also failed for node 42: root down']], $this->records);

		$this->records = [];
		$this->assertNull($this->handler(userNodes: [], rootNodes: [])->getNodeById(42));
		$this->assertSame([], $this->records);
	}//end testANodeIsLookedUpInTheUserFolderThenTheRoot()

	/**
	 * The stored-folder probe answers only a numeric id naming a folder; a failing lookup is a warning.
	 *
	 * @return void
	 */
	public function testTheStoredFolderProbe(): void {
		$probe = new ReflectionMethod(FolderManagementHandler::class, 'getExistingFolderFromProperty');
		$found = $this->createMock(Folder::class);

		foreach ([null, '', 'Open Registers/Intake'] as $stored) {
			$this->assertNull($probe->invoke($this->handler(userNodes: [$found]), $stored), var_export($stored, true));
		}

		$this->assertSame($found, $probe->invoke($this->handler(userNodes: [$found]), '42'));
		$this->assertNull($probe->invoke($this->handler(userNodes: [$this->createMock(File::class)]), '42'));
		$this->assertNull($probe->invoke($this->handler(userNodes: [], rootNodes: []), '42'));
		$this->assertSame([], $this->records);

		$this->assertNull($probe->invoke($this->handler(failNode: true), '42'));
		$this->assertSame([['warning', '[FolderManagementHandler] Stored folder ID invalid: node lookup broke']], $this->records);
	}//end testTheStoredFolderProbe()
}//end class
