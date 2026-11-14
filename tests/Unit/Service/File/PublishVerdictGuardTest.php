<?php

/**
 * PublishVerdictGuardTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.4
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\RedactionNotPublishableException;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface;
use OCA\OpenRegister\Service\Anonymisation\RedactionVerdictStore;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IServerContainer;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `publishFile()` refuses an anonymised copy whose stored verdict is not clean.
 *
 * @covers \OCA\OpenRegister\Service\File\FilePublishingHandler
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionVerdictStore
 * @covers \OCA\OpenRegister\Exception\RedactionNotPublishableException
 * @uses   \OCA\OpenRegister\Service\File\ObjectFileFinder
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class PublishVerdictGuardTest extends TestCase {
	use PassThroughDigestsTrait;

	/**
	 * The shares created, by file id.
	 *
	 * @var list<int>
	 */
	private array $shares = [];

	/**
	 * Publish file 7, an `_anonymized` copy whose metadata holds $stored (null: no verdict).
	 */
	private function publish(?string $stored, string $name = 'besluit_anonymized.pdf'): File {
		$metadata = $this->createMock(IFilesMetadata::class);
		$metadata->method('hasKey')->willReturn($stored !== null);
		$metadata->method('getString')->willReturn((string) $stored);
		$manager = $this->createMock(IFilesMetadataManager::class);
		$manager->method('getMetadata')->with(7, true)->willReturn($metadata);
		$verdicts = new RedactionVerdictStore(metadataManager: $manager, logger: $this->createMock(LoggerInterface::class));

		$restrictions = $this->createMock(FileRestrictionGuard::class);
		$restrictions->method('isRestricted')->willReturn(false);
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				FileRestrictionGuardInterface::class => $restrictions,
				RedactionVerdictStore::class => $verdicts,
				default => $this->createMock(PartyIndicatorGuard::class),
			}
		);

		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('publishFile')->willReturnCallback(function (int $fileId): array {
			$this->shares[] = $fileId;
			return ['id' => 3, 'token' => 'tok', 'accessUrl' => 'https://x/s/tok'];
		});

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/objects/42/'.$name);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('openregister');
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFile')->willReturn($file);
		$fileService->method('getUser')->willReturn($user);

		$handler = new FilePublishingHandler($this->createMock(MagicMapper::class), $fileMapper, $this->createMock(LoggerInterface::class), $container, $this->passThroughDigests());
		$handler->setFileService($fileService);

		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');

		return $handler->publishFile($object, 7);
	}//end publish()

	private static function verdict(string $verdict, array $findings = []): string {
		return (string) json_encode(['verdict' => $verdict, 'mayBePublished' => $verdict === 'clean', 'outputMode' => 'pdf', 'routesChecked' => [], 'findings' => $findings]);
	}//end verdict()

	public function testALeakingOutputIsNotPublished(): void {
		try {
			$this->publish(stored: self::verdict(verdict: 'leaking', findings: [['route' => 'incremental_update', 'count' => 1]]));
			$this->fail('A leaking copy was published');
		} catch (RedactionNotPublishableException $e) {
			$this->assertSame('leaking', $e->verdict);
			$this->assertSame(['incremental_update'], $e->routes);
			$this->assertStringContainsString('verdict is leaking (routes: incremental_update)', $e->getMessage());
		}

		$this->assertSame([], $this->shares, 'no public share is created');
	}//end testALeakingOutputIsNotPublished()

	public function testAnUnverifiedOutputIsNotPublished(): void {
		try {
			$this->publish(stored: self::verdict(verdict: 'unverifiable'));
			$this->fail('An unverifiable copy was published');
		} catch (RedactionNotPublishableException $e) {
			$this->assertSame('unverifiable', $e->verdict);
			$this->assertStringContainsString('verdict is unverifiable', $e->getMessage());
		}

		$this->assertSame([], $this->shares);
	}//end testAnUnverifiedOutputIsNotPublished()

	public function testAnUnreadableVerdictIsNotPublished(): void {
		try {
			$this->publish(stored: 'not json');
			$this->fail('A copy with an unreadable verdict was published');
		} catch (RedactionNotPublishableException $e) {
			$this->assertSame('unverifiable', $e->verdict);
		}

		$this->assertSame([], $this->shares);
	}//end testAnUnreadableVerdictIsNotPublished()

	public function testACleanOutputIsPublished(): void {
		$this->publish(stored: self::verdict(verdict: 'clean'));

		$this->assertSame([7], $this->shares);
	}//end testACleanOutputIsPublished()

	public function testAFileThatIsNotAnAnonymisedCopyIsNotAsked(): void {
		$this->publish(stored: self::verdict(verdict: 'leaking'), name: 'besluit.pdf');

		$this->assertSame([7], $this->shares);
	}//end testAFileThatIsNotAnAnonymisedCopyIsNotAsked()
}//end class
