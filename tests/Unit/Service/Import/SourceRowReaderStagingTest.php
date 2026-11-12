<?php

/**
 * Unit tests for hashing, staging and unreadable sources of the import source reader.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use PHPUnit\Framework\TestCase;

/**
 * Hash and stage, success and failure, and which paths count as staged.
 *
 * The failure cases name a path that does not exist. PHP raises a warning
 * there before returning false; the test silences that warning for the one
 * call so the refusal the reader turns it into is what is asserted.
 *
 * @covers \OCA\OpenRegister\Service\Import\SourceRowReader
 */
class SourceRowReaderStagingTest extends TestCase {

	/**
	 * Files to remove after a test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	/**
	 * Remove the files a test wrote.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}

	}//end tearDown()

	/**
	 * Run a call with PHP warnings silenced, rethrowing what it throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return mixed What it returned.
	 */
	private function quietly(callable $call): mixed {
		set_error_handler(static fn (): bool => true);
		try {
			return $call();
		} finally {
			restore_error_handler();
		}

	}//end quietly()

	/**
	 * A path that does not exist.
	 *
	 * @return string
	 */
	private function missing(): string {
		return sys_get_temp_dir() . '/or-no-such-source-' . bin2hex(random_bytes(6));

	}//end missing()

	/**
	 * A readable file is hashed with sha256.
	 *
	 * @return void
	 */
	public function testAFileIsHashed(): void {
		$path = tempnam(sys_get_temp_dir(), 'or-hash-');
		$this->files[] = $path;
		file_put_contents($path, 'abc');

		$this->assertSame(hash('sha256', 'abc'), (new SourceRowReader())->hash($path));

	}//end testAFileIsHashed()

	/**
	 * An unreadable file cannot be hashed.
	 *
	 * @return void
	 */
	public function testAnUnreadableFileIsRefusedForHashing(): void {
		$path = $this->missing();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The source file could not be read: ' . $path);

		$this->quietly(static fn () => (new SourceRowReader())->hash($path));

	}//end testAnUnreadableFileIsRefusedForHashing()

	/**
	 * Staging copies the file under the stage prefix, and the copy counts as staged.
	 *
	 * @return void
	 */
	public function testStagingCopiesUnderThePrefix(): void {
		$source = tempnam(sys_get_temp_dir(), 'or-upload-');
		$this->files[] = $source;
		file_put_contents($source, "bsn\n1\n");
		$reader = new SourceRowReader();

		$staged = $reader->stage($source, 'tok-' . bin2hex(random_bytes(4)));
		$this->files[] = $staged;

		$this->assertStringStartsWith(rtrim(sys_get_temp_dir(), '/') . '/' . SourceRowReader::STAGE_PREFIX . 'tok-', $staged);
		$this->assertStringEqualsFile($staged, "bsn\n1\n");
		$this->assertTrue($reader->isStaged($staged));
		$this->assertFalse($reader->isStaged($source), 'the caller\'s own upload is not a staged copy');

	}//end testStagingCopiesUnderThePrefix()

	/**
	 * A source that cannot be copied is refused for staging.
	 *
	 * @return void
	 */
	public function testAnUncopyableSourceIsRefusedForStaging(): void {
		$path = $this->missing();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The source file could not be staged for a background preview.');

		$this->quietly(static fn () => (new SourceRowReader())->stage($path, 'x'));

	}//end testAnUncopyableSourceIsRefusedForStaging()

	/**
	 * No path is never staged.
	 *
	 * @return void
	 */
	public function testNoPathIsNotStaged(): void {
		$reader = new SourceRowReader();

		$this->assertFalse($reader->isStaged(null));
		$this->assertFalse($reader->isStaged(''));

	}//end testNoPathIsNotStaged()

	/**
	 * An unreadable JSON source is refused by path.
	 *
	 * @return void
	 */
	public function testAnUnreadableJsonSourceIsRefused(): void {
		$path = $this->missing();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The source file could not be read: ' . $path);

		$this->quietly(static fn () => (new SourceRowReader())->read($path, 'json'));

	}//end testAnUnreadableJsonSourceIsRefused()
}//end class
