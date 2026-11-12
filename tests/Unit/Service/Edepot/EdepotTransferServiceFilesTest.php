<?php

declare(strict_types=1);

/**
 * EdepotTransferService file-gathering tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Edepot;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\PackagedFileChecksum;
use OCA\OpenRegister\Service\Edepot\TransferObjectGatherer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * File gathering takes its checksum and date from PackagedFileChecksum.
 *
 * The rule itself is tested in PackagedFileChecksumTest. This proves the
 * transfer path uses it: that the SIP's file entries carry the computed
 * checksum and its date, and that a fixity failure propagates to the
 * per-object catch instead of being swallowed here.
 *
 * The file metadata is read by TransferObjectGatherer::filesOf(), which
 * touches no other collaborator than the checksum.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\TransferObjectGatherer
 */
class EdepotTransferServiceFilesTest extends TestCase {

	private string $path;

	protected function setUp(): void {
		parent::setUp();

		$this->path = (string)tempnam(sys_get_temp_dir(), 'edepot');
		file_put_contents($this->path, 'archival bytes');
	}

	protected function tearDown(): void {
		@unlink($this->path);
		parent::tearDown();
	}

	/**
	 * Each gathered file carries the computed checksum and its date.
	 */
	public function testGatheredFilesCarryTheComputedChecksumAndDate(): void {
		$files = $this->gather(fileRef: ['path' => $this->path, 'name' => 'a.txt']);

		$this->assertSame(hash('sha256', 'archival bytes'), $files[0]['checksum']);
		$this->assertArrayHasKey('checksumDate', $files[0]);
		$this->assertNotSame('', $files[0]['checksumDate']);
	}

	/**
	 * A fixity failure propagates, so the per-object catch can exclude the object.
	 */
	public function testAFixityFailurePropagates(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Fixity failure/');

		$this->gather(fileRef: ['path' => $this->path, 'checksum' => hash('sha256', 'other bytes')]);
	}

	/**
	 * Run the file gathering for one file reference.
	 *
	 * @param array<string, mixed> $fileRef The file reference on the object.
	 *
	 * @return array<int, array<string, mixed>> The gathered files.
	 */
	private function gather(array $fileRef): array {
		$object = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObject'])
			->getMock();
		$object->method('getObject')->willReturn(['_files' => [$fileRef]]);

		$gatherer = new TransferObjectGatherer(
			stores: new \OCA\OpenRegister\Service\Edepot\TransferStores(objects: $this->createMock(originalClassName: MagicMapperInterface::class), records: $this->createMock(\OCA\OpenRegister\Service\Edepot\TransferRecordService::class), settings: $this->createMock(\OCP\IAppConfig::class), logger: new NullLogger()),
			packagedFileChecksum: new PackagedFileChecksum()
		);

		return $gatherer->filesOf(object: $object);
	}
}
