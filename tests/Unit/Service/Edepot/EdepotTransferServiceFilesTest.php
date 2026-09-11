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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The checksum the SIP carries is computed and dated at packaging, and a
 * stored SHA-256 that no longer matches the bytes is refused.
 *
 * `getObjectFiles()` touches none of the service's collaborators, so the
 * service is built without its constructor and the method reached by
 * reflection. That keeps the test about this one behaviour.
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
	 * With no stored checksum, one is computed and dated as an xsd:dateTime.
	 */
	public function testTheChecksumIsComputedAndDated(): void {
		$files = $this->gather(fileRef: ['path' => $this->path, 'name' => 'a.txt']);

		$this->assertSame(hash('sha256', 'archival bytes'), $files[0]['checksum']);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			$files[0]['checksumDate']
		);
	}

	/**
	 * A stored SHA-256 that matches is accepted, and the fresh one is used.
	 */
	public function testAMatchingStoredChecksumIsAccepted(): void {
		$stored = strtoupper(hash('sha256', 'archival bytes'));

		$files = $this->gather(fileRef: ['path' => $this->path, 'checksum' => $stored]);

		$this->assertSame(hash('sha256', 'archival bytes'), $files[0]['checksum']);
	}

	/**
	 * A stored SHA-256 that does NOT match the bytes is a fixity failure.
	 *
	 * Recomputing silently would launder the changed file into a package
	 * that looks intact, so the file is refused.
	 */
	public function testAStaleStoredChecksumIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Fixity failure/');

		$this->gather(fileRef: ['path' => $this->path, 'checksum' => hash('sha256', 'other bytes')]);
	}

	/**
	 * A stored value that is not SHA-256-shaped cannot be compared, and is not used.
	 */
	public function testAStoredChecksumOfAnotherShapeIsIgnored(): void {
		$files = $this->gather(fileRef: ['path' => $this->path, 'checksum' => md5('archival bytes')]);

		$this->assertSame(hash('sha256', 'archival bytes'), $files[0]['checksum']);
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
		$method = $reflection->getMethod('getObjectFiles');

		return $method->invoke($service, $object);
	}
}
