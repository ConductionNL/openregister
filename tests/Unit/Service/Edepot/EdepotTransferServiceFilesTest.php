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

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\PackagedFileChecksum;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * File gathering takes its checksum and date from PackagedFileChecksum.
 *
 * The rule itself is tested in PackagedFileChecksumTest. This proves the
 * transfer path uses it: that the SIP's file entries carry the computed
 * checksum and its date, and that a fixity failure propagates to the
 * per-object catch instead of being swallowed here.
 *
 * `getObjectFiles()` touches no other collaborator, so the service is built
 * without its constructor and only the checksum dependency is initialised.
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
	 * Run the private file gathering for one file reference.
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

		$reflection = new ReflectionClass(EdepotTransferService::class);
		$service = $reflection->newInstanceWithoutConstructor();

		// A readonly property may be initialised once, from inside its class.
		Closure::bind(
			function (): void {
				$this->packagedFileChecksum = new PackagedFileChecksum();
			},
			$service,
			EdepotTransferService::class
		)();

		return $reflection->getMethod('getObjectFiles')->invoke($service, $object);
	}
}
