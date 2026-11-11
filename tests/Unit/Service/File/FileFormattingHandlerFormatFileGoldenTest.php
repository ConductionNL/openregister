<?php

/**
 * Golden master for FileFormattingHandler::formatFile().
 *
 * Every case formats one file node and records the metadata array together
 * with the warnings logged on the way. The recording lives in
 * `fixtures/file-formatting-format-file-golden.json`; set `OR_GOLDEN_WRITE=1`
 * to rewrite it. The creation and upload timestamps depend on the PHP time
 * zone, so they are recorded as present rather than by value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCP\IGroupManager;
use OCA\OpenRegister\Service\File\FileSession;
use DateTime;
use OCA\OpenRegister\Files\Db\File;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Service\File\FileFormattingHandler;
use OCA\OpenRegister\Files\Service\File\FileSharingHandler;
use OCA\OpenRegister\Files\Service\File\TaggingHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\File\FileFormattingHandler
 * @uses   \OCA\OpenRegister\Files\Db\File
 */
class FileFormattingHandlerFormatFileGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/file-formatting-format-file-golden.json';

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::scenarios()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$colon = ['plain', 'colour:red', 'colour:blue', 'title:other', 'accessUrl:x', ' spaced : value ', 'size:9', 'a:b:c'];
		return [
			'anonymous-bare' => [],
			'anonymous-shared-labels' => ['shares' => true, 'tags' => $colon],
			'anonymous-unshared-labels' => ['tags' => $colon],
			'auth-no-provider' => ['user' => true],
			'auth-lock' => ['user' => true, 'lock' => true],
			'auth-lock-lookup-fails' => ['user' => true, 'lock' => 'throw'],
			'mapper-no-row' => ['mapper' => 'none', 'user' => true],
			'mapper-throws' => ['mapper' => 'throw', 'tags' => ['k:v']],
			'row-anonymous' => ['mapper' => 'full', 'tags' => ['tag', 'k:v']],
			'row-auth' => ['mapper' => 'full', 'user' => true, 'tags' => ['tag', 'k:v']],
			'row-auth-shared' => ['mapper' => 'full', 'user' => true, 'shares' => true, 'tags' => ['shared', 'category:z']],
			'row-auth-bare' => ['mapper' => 'bare', 'user' => true, 'tags' => ['tag']],
			'row-auth-overlapping-labels' => ['mapper' => 'full', 'user' => true, 'tags' => ['or-one', 'dept:x']],
			'row-depublished' => ['mapper' => 'past', 'user' => true],
		];
	}//end scenarios()

	/**
	 * @dataProvider cases
	 */
	public function testFormatFileMatchesGolden(string $name): void {
		$recorded = $this->record(scenario: self::scenarios()[$name]);

		$all = [];
		if (file_exists(self::FIXTURE) === true) {
			$all = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$all[$name] = $recorded;
			ksort($all);
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $recorded);
	}//end testFormatFileMatchesGolden()

	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $scenario): array {
		$warnings = [];
		$logger = new class($warnings) extends AbstractLogger {
			/**
			 * @param array<int, string> $warnings
			 */
			public function __construct(private array &$warnings) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->warnings[] = $level . ': ' . $message;
			}
		};

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (($scenario['user'] ?? false) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		$lockManager = $this->createMock(ILockManager::class);
		$lockManager->method('isLockProviderAvailable')->willReturn(isset($scenario['lock']));
		if (($scenario['lock'] ?? null) === 'throw') {
			$lockManager->method('getLocks')->willThrowException(new \Exception('lock store down'));
		} else {
			$lock = $this->createMock(ILock::class);
			$lock->method('getType')->willReturn(ILock::TYPE_USER);
			$lock->method('getScope')->willReturn(ILock::LOCK_EXCLUSIVE);
			$lock->method('getOwner')->willReturn('alice');
			$lock->method('getCreatedAt')->willReturn(1700000000);
			$lock->method('getTimeout')->willReturn(0);
			$lockManager->method('getLocks')->willReturn([$lock]);
		}

		$handler = new FileFormattingHandler(
			lockManager: $lockManager,
			session: new FileSession($session, $this->createMock(IGroupManager::class), $logger),
			fileMapper: $this->mapperFor(mode: ($scenario['mapper'] ?? null))
		);

		$fileService = $this->createMock(FileService::class);
		$shares = [];
		if (($scenario['shares'] ?? false) === true) {
			$shares = [$this->createMock(IShare::class)];
		}

		$fileService->method('findShares')->willReturn($shares);
		$fileService->method('getShareLink')->willReturn('https://cloud.example/s/abc');
		$fileService->method('getFileTags')->willReturn($scenario['tags'] ?? []);
		$handler->setFileService($fileService);

		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(42);
		$node->method('getName')->willReturn('file.pdf');
		$node->method('getPath')->willReturn('/user/files/file.pdf');
		$node->method('getMimetype')->willReturn('application/pdf');
		$node->method('getExtension')->willReturn('pdf');
		$node->method('getSize')->willReturn(1024);
		$node->method('getEtag')->willReturn('etag-42');
		$node->method('getCreationTime')->willReturn(1700000000);
		$node->method('getUploadTime')->willReturn(1700000100);

		$metadata = $handler->formatFile(file: $node);
		foreach (['created', 'modified'] as $key) {
			$this->assertIsString($metadata[$key]);
			$metadata[$key] = '~';
		}

		return ['metadata' => $metadata, 'keys' => array_keys($metadata), 'log' => $warnings];
	}//end record()

	private function mapperFor(?string $mode): ?FileMapper {
		if ($mode === null) {
			return null;
		}

		$mapper = $this->createMock(FileMapper::class);
		if ($mode === 'throw') {
			$mapper->method('findByFileId')->willThrowException(new RuntimeException('table missing'));
			return $mapper;
		}

		if ($mode === 'none') {
			$mapper->method('findByFileId')->willReturn(null);
			return $mapper;
		}

		$row = new File();
		$row->setDescription('A description');
		$row->setCategory('reports');
		$row->setDownloadCount(7);
		if ($mode === 'full' || $mode === 'past') {
			$row->setPublished(new DateTime('2001-02-03T04:05:06+00:00'));
			$row->setLabels(['or-one', 'tag', 'or-two']);
		}

		if ($mode === 'full') {
			$row->setDepublished(new DateTime('9999-12-31T00:00:00+00:00'));
			$row->setLockedBy('bob');
			$row->setLockedAt(new DateTime('2026-01-02T03:04:05+00:00'));
		}

		if ($mode === 'past') {
			$row->setDepublished(new DateTime('2002-01-01T00:00:00+00:00'));
			$row->setLockedBy('carol');
		}

		$mapper->method('findByFileId')->willReturn($row);
		return $mapper;
	}//end mapperFor()
}//end class
