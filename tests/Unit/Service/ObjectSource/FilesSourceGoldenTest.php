<?php

/**
 * Golden record of FilesObjectSourceProvider.
 *
 * find(), findAll() and count() with no user, a user folder that cannot be
 * resolved, non-numeric ids, getById() throwing or answering folders before
 * files, a folder tree with nested folders, non-file/non-folder nodes, a
 * listing that throws, the scan cap, relative paths that resolve or fall
 * back to the absolute path, and paging. Every folder listing, lookup and
 * log line is recorded. The recording lives in fixtures/files-source-golden.json;
 * set FILES_SOURCE_GOLDEN_RECORD=1 to write it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pins the projection of the files object source.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\FilesObjectSourceProvider;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * The files object source, recorded.
 *
 * @covers \OCA\OpenRegister\Service\ObjectSource\FilesObjectSourceProvider
 */
class FilesSourceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/files-source-golden.json';

	/**
	 * Everything observed: listings, lookups and log lines.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * A file node.
	 *
	 * @param int    $id   The file id.
	 * @param string $name The file name.
	 *
	 * @return File The file.
	 */
	private function file(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/alice/files/'.$name);
		$file->method('getMimetype')->willReturn('text/plain');
		$file->method('getSize')->willReturn((float)($id * 10));
		$file->method('getMTime')->willReturn(1700000000 + $id);
		return $file;
	}//end file()

	/**
	 * A folder node whose listing is $children (a Throwable is thrown).
	 *
	 * @param string $name     The folder name, recorded on listing.
	 * @param mixed  $children The children, or a Throwable.
	 *
	 * @return Folder The folder.
	 */
	private function folder(string $name, mixed $children): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturnCallback(
			function () use ($name, $children): array {
				$this->log[] = ['list', $name];
				if ($children instanceof \Throwable) {
					throw $children;
				}
				return $children;
			}
		);
		return $folder;
	}//end folder()

	/**
	 * The user's home folder over a tree, answering getById() from $byId.
	 *
	 * @param array<int, mixed> $children The top-level children.
	 * @param mixed             $byId     The getById() answer, or a Throwable.
	 *
	 * @return Folder The home folder.
	 */
	private function home(array $children, mixed $byId = []): Folder {
		$home = $this->createMock(Folder::class);
		$home->method('getDirectoryListing')->willReturnCallback(
			function () use ($children): array {
				$this->log[] = ['list', 'home'];
				return $children;
			}
		);
		$home->method('getById')->willReturnCallback(
			function (int $id) use ($byId): mixed {
				$this->log[] = ['getById', $id];
				if ($byId instanceof \Throwable) {
					throw $byId;
				}
				return $byId;
			}
		);
		$home->method('getRelativePath')->willReturnCallback(
			static fn (string $path): ?string => str_contains($path, 'odd') === true ? null : '/'.basename($path)
		);
		return $home;
	}//end home()

	/**
	 * A provider for a logged-in (or not) user whose home is $home (a Throwable is thrown).
	 *
	 * @param bool  $loggedIn Whether a user is logged in.
	 * @param mixed $home     The home folder, or a Throwable.
	 *
	 * @return FilesObjectSourceProvider The provider.
	 */
	private function provider(bool $loggedIn, mixed $home): FilesObjectSourceProvider {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}
		$session->method('getUser')->willReturn($user);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($home): Folder {
				$this->log[] = ['getUserFolder', $uid];
				if ($home instanceof \Throwable) {
					throw $home;
				}
				return $home;
			}
		);
		$log = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			public function __construct(private array &$log) {
			}
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log', $level, (string)$message];
			}
		};
		return new FilesObjectSourceProvider(rootFolder: $root, session: new \OCA\OpenRegister\Service\LinkSessionRuntime(userSession: $session, logger: $logger));
	}//end provider()

	/**
	 * Flatten what a call returned.
	 *
	 * @param mixed $value An entity, a list of entities, or a scalar.
	 *
	 * @return mixed The flattened value.
	 */
	private function flat(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return [$value->getUuid(), $value->getRegister(), $value->getSchema(), $value->getObject()];
		}
		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->flat($item), $value);
		}
		return $value;
	}//end flat()

	/**
	 * Run every scenario and record it.
	 *
	 * @return array<string, mixed> The recording.
	 */
	private function recordAll(): array {
		$register = new Register();
		$register->setId(8);
		$schema = new Schema();
		$schema->setId(80);
		$other = $this->createMock(Node::class);
		$tree = fn (): array => [
			$this->file(1, 'a.txt'),
			$this->folder('docs', [$this->file(2, 'b.txt'), $this->folder('deep', [$this->file(3, 'odd.txt')]), $other]),
			$this->folder('broken', new RuntimeException('no listing')),
			$other,
			$this->file(4, 'c.txt'),
			$this->folder('empty', []),
		];
		$capped = fn (int $fill): array => array_merge(
			array_fill(0, $fill, $other),
			[$this->folder('late', [$this->file(9, 'late.txt')]), $this->file(5, 'top.txt')]
		);

		$scenarios = [
			'find no user' => fn () => $this->provider(false, $this->home($tree()))->find($register, $schema, '1'),
			'find user folder throws' => fn () => $this->provider(true, new RuntimeException('no home'))->find($register, $schema, '1'),
			'find non-numeric id' => fn () => $this->provider(true, $this->home($tree()))->find($register, $schema, 'abc'),
			'find getById throws' => fn () => $this->provider(true, $this->home([], new RuntimeException('lookup down')))->find($register, $schema, '7'),
			'find folder then file' => fn () => $this->provider(true, $this->home([], [$this->folder('x', []), $this->file(3, 'odd.txt'), $this->file(4, 'c.txt')]))->find($register, $schema, '3.0'),
			'find only folders' => fn () => $this->provider(true, $this->home([], [$this->folder('x', [])]))->find($register, $schema, '3'),
			'find nothing' => fn () => $this->provider(true, $this->home([], []))->find($register, $schema, '3'),
			'findAll no user' => fn () => $this->provider(false, $this->home($tree()))->findAll($register, $schema),
			'findAll user folder throws' => fn () => $this->provider(true, new RuntimeException('no home'))->findAll($register, $schema),
			'findAll tree' => fn () => $this->provider(true, $this->home($tree()))->findAll($register, $schema),
			'findAll paged' => fn () => $this->provider(true, $this->home($tree()))->findAll($register, $schema, ['limit' => '2', 'offset' => 1]),
			'findAll cap reached' => fn () => $this->provider(true, $this->home($capped(9998)))->findAll($register, $schema),
			'findAll cap not reached' => fn () => $this->provider(true, $this->home($capped(9997)))->findAll($register, $schema),
			'count' => fn () => $this->provider(true, $this->home($tree()))->count($register, $schema, ['limit' => 3]),
		];

		$recording = [];
		foreach ($scenarios as $name => $run) {
			$this->log = [];
			$result = $this->flat($run());
			$recording[$name] = ['result' => $result, 'observed' => $this->log];
		}
		return $recording;
	}//end recordAll()

	/**
	 * Every scenario matches the recording.
	 *
	 * @return void
	 */
	public function testMatchesTheGoldenRecord(): void {
		$recording = $this->recordAll();
		if (getenv('FILES_SOURCE_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($recording, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
		}
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($recording), true));
	}//end testMatchesTheGoldenRecord()
}//end class
