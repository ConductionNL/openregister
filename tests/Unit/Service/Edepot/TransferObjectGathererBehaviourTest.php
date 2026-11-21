<?php

/**
 * Behaviour tests for TransferObjectGatherer: which references are outstanding,
 * when a transfer is terminal, and which files travel with an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\PackagedFileChecksum;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferObjectGatherer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behaviour of the object gatherer of an e-Depot transfer.
 */
class TransferObjectGathererBehaviourTest extends TestCase {

	/** @var MagicMapperInterface&MockObject */
	private MagicMapperInterface $mapper;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var string[] Temporary files to remove. */
	private array $tmpFiles = [];

	protected function setUp(): void {
		$this->mapper = $this->createMock(MagicMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $file) {
			if (file_exists($file) === true) {
				unlink($file);
			}
		}
	}//end tearDown()

	private function gatherer(): TransferObjectGatherer {
		return new TransferObjectGatherer(new \OCA\OpenRegister\Service\Edepot\TransferStores(objects: $this->mapper, records: $this->createMock(\OCA\OpenRegister\Service\Edepot\TransferRecordService::class), settings: $this->createMock(\OCP\IAppConfig::class), logger: $this->logger), new PackagedFileChecksum());
	}//end gatherer()

	/**
	 * Let the mapper resolve uuid => archiefstatus; a missing uuid throws.
	 *
	 * @param array<string, string> $states The states.
	 *
	 * @return void
	 */
	private function withStates(array $states): void {
		$this->mapper->method('find')->willReturnCallback(
			static function (string|int $uuid) use ($states): ObjectEntity {
				if (isset($states[$uuid]) === false) {
					throw new \RuntimeException('not found '.$uuid);
				}

				$object = new ObjectEntity();
				$object->setUuid((string)$uuid);
				$object->setRetention(['archiefstatus' => $states[$uuid]]);
				return $object;
			}
		);
	}//end withStates()

	private function tmpFile(string $content): string {
		$path = (string)tempnam(sys_get_temp_dir(), 't12gather');
		file_put_contents($path, $content);
		$this->tmpFiles[] = $path;
		return $path;
	}//end tmpFile()

	private static function objectWith(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setObject($data);
		return $object;
	}//end objectWith()

	public function testOutstandingIsEmptyWhenReferencesAreNotAList(): void {
		$this->mapper->expects($this->never())->method('find');

		$this->assertSame([], $this->gatherer()->outstanding(['objectReferences' => 'not-a-list']));
	}

	public function testOutstandingIsEmptyWithoutReferencesKey(): void {
		$this->mapper->expects($this->never())->method('find');

		$this->assertSame([], $this->gatherer()->outstanding([]));
	}

	public function testOutstandingKeepsActiveAndUnloadableButDropsTransferredAndInvalid(): void {
		$this->withStates(['done' => 'transferred', 'open' => 'active']);
		$this->logger->expects($this->once())->method('debug')
			->with($this->stringContains('outstanding-check could not load object ghost'));

		$refs = [['uuid' => 'done'], ['uuid' => 'open'], ['uuid' => 'ghost'], ['uuid' => ''], 'junk'];

		$this->assertSame(
			[['uuid' => 'open'], ['uuid' => 'ghost']],
			$this->gatherer()->outstanding(['objectReferences' => $refs])
		);
	}

	public function testTerminalStatusFailsOnAnEmptyOrNonListReference(): void {
		$this->assertSame(TransferListService::STATUS_FAILED, $this->gatherer()->terminalStatus(['objectReferences' => []]));
		$this->assertSame(TransferListService::STATUS_FAILED, $this->gatherer()->terminalStatus(['objectReferences' => 'x']));
		$this->assertSame(TransferListService::STATUS_FAILED, $this->gatherer()->terminalStatus([]));
	}

	public function testTerminalStatusCompletesWhenEveryValidReferenceIsTransferred(): void {
		$this->withStates(['a' => 'transferred', 'b' => 'overgebracht']);

		$status = $this->gatherer()->terminalStatus(['objectReferences' => [['uuid' => 'a'], ['uuid' => 'b'], 'junk']]);

		$this->assertSame(TransferListService::STATUS_COMPLETED, $status);
	}

	public function testTerminalStatusFailsWhenOneObjectIsNotTransferred(): void {
		$this->withStates(['a' => 'transferred', 'b' => 'active']);

		$status = $this->gatherer()->terminalStatus(['objectReferences' => [['uuid' => 'a'], ['uuid' => 'b']]]);

		$this->assertSame(TransferListService::STATUS_FAILED, $status);
	}

	public function testTerminalStatusFailsWhenAnObjectCannotBeLoaded(): void {
		$this->withStates(['a' => 'transferred']);

		$status = $this->gatherer()->terminalStatus(['objectReferences' => [['uuid' => 'a'], ['uuid' => 'gone']]]);

		$this->assertSame(TransferListService::STATUS_FAILED, $status);
	}

	public function testGatherLoadsObjectsAndSkipsUnloadableOnesWithAWarning(): void {
		$object = self::objectWith([]);
		$this->mapper->method('find')->willReturnCallback(
			static function (string|int $uuid) use ($object): ObjectEntity {
				if ($uuid !== 'obj-1') {
					throw new \RuntimeException('boom');
				}

				return $object;
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'[EdepotTransferService] Could not load object for transfer',
			['uuid' => 'missing', 'error' => 'boom']
		);

		$result = $this->gatherer()->gather([['uuid' => 'obj-1'], ['uuid' => 'missing']]);

		$this->assertSame([['object' => $object, 'files' => []]], $result);
	}

	public function testGatherOfNothingIsNothing(): void {
		$this->mapper->expects($this->never())->method('find');

		$this->assertSame([], $this->gatherer()->gather([]));
	}

	public function testFilesOfDescribesAnExistingFileWithDefaults(): void {
		$path = $this->tmpFile('hello');

		$files = $this->gatherer()->filesOf(self::objectWith(['_files' => [['path' => $path]]]));

		$this->assertCount(1, $files);
		$this->assertSame(basename($path), $files[0]['name']);
		$this->assertSame(5, $files[0]['size']);
		$this->assertSame('application/octet-stream', $files[0]['format']);
		$this->assertSame(hash('sha256', 'hello'), $files[0]['checksum']);
		$this->assertSame($path, $files[0]['path']);
		$this->assertFalse($files[0]['isRendition']);
	}

	public function testFilesOfPrefersDeclaredNameSizeMimeTypeAndRendition(): void {
		$path = $this->tmpFile('hello');

		$files = $this->gatherer()->filesOf(
			self::objectWith(
				[
					'_files' => [
						['path' => $path, 'name' => 'a.pdf', 'size' => 42, 'mimeType' => 'application/pdf', 'format' => 'x/y', 'isRendition' => 1],
					],
				]
			)
		);

		$this->assertSame('a.pdf', $files[0]['name']);
		$this->assertSame(42, $files[0]['size']);
		$this->assertSame('application/pdf', $files[0]['format']);
		$this->assertTrue($files[0]['isRendition']);
	}

	public function testFilesOfFallsBackToFormatAndBijlagen(): void {
		$path = $this->tmpFile('x');

		$files = $this->gatherer()->filesOf(self::objectWith(['bijlagen' => [['path' => $path, 'format' => 'text/plain']]]));

		$this->assertSame('text/plain', $files[0]['format']);
	}

	public function testFilesOfSkipsNonArrayMissingAndPathlessReferences(): void {
		$files = $this->gatherer()->filesOf(
			self::objectWith(['_files' => ['junk', ['path' => ''], ['name' => 'nopath'], ['path' => '/nonexistent/t12/file']]])
		);

		$this->assertSame([], $files);
	}

	public function testFilesOfIsEmptyWhenTheFileListIsNotAList(): void {
		$this->assertSame([], $this->gatherer()->filesOf(self::objectWith(['_files' => 'scalar'])));
	}

	public function testFilesOfIsEmptyForAnObjectWithoutData(): void {
		$object = new ObjectEntity();

		$this->assertSame([], $this->gatherer()->filesOf($object));
	}
}//end class
