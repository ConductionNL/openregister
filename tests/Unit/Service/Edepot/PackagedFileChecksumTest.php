<?php

declare(strict_types=1);

/**
 * PackagedFileChecksum Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\PackagedFileChecksum;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The checksum a package carries is computed and dated at packaging, and a
 * stored SHA-256 that no longer matches the bytes is refused.
 */
class PackagedFileChecksumTest extends TestCase {

	private string $path;

	private PackagedFileChecksum $checksum;

	protected function setUp(): void {
		parent::setUp();

		$this->path = (string)tempnam(sys_get_temp_dir(), 'edepot');
		file_put_contents($this->path, 'archival bytes');
		$this->checksum = new PackagedFileChecksum();
	}

	protected function tearDown(): void {
		@unlink($this->path);
		parent::tearDown();
	}

	/**
	 * With no stored checksum, one is computed and dated as an xsd:dateTime.
	 */
	public function testTheChecksumIsComputedAndDated(): void {
		$result = $this->checksum->compute(path: $this->path, stored: null);

		$this->assertSame(hash('sha256', 'archival bytes'), $result['checksum']);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			$result['checksumDate']
		);
	}

	/**
	 * A stored SHA-256 that matches, in either case, is accepted.
	 */
	public function testAMatchingStoredChecksumIsAccepted(): void {
		$result = $this->checksum->compute(path: $this->path, stored: strtoupper(hash('sha256', 'archival bytes')));

		$this->assertSame(hash('sha256', 'archival bytes'), $result['checksum']);
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

		$this->checksum->compute(path: $this->path, stored: hash('sha256', 'other bytes'));
	}

	/**
	 * A stored value that is not SHA-256-shaped cannot be compared, and is not used.
	 */
	public function testAStoredChecksumOfAnotherShapeIsIgnored(): void {
		$result = $this->checksum->compute(path: $this->path, stored: md5('archival bytes'));

		$this->assertSame(hash('sha256', 'archival bytes'), $result['checksum']);
	}

	/**
	 * A file that cannot be read is refused, not given an empty checksum.
	 */
	public function testAnUnreadableFileIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Cannot read/');

		@$this->checksum->compute(path: $this->path . '.missing', stored: null);
	}
}
