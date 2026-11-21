<?php

/**
 * ArchiveMemberEnumerator: members listed under bounds, over generated zip fixtures.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\File\ArchiveBounds;
use OCA\OpenRegister\Service\File\ArchiveListing;
use OCA\OpenRegister\Service\File\ArchiveMemberEnumerator;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * @covers \OCA\OpenRegister\Service\File\ArchiveMemberEnumerator
 * @covers \OCA\OpenRegister\Service\File\ArchiveListing
 * @covers \OCA\OpenRegister\Service\File\ArchiveWalk
 * @covers \OCA\OpenRegister\Service\File\ArchiveBounds
 */
class ArchiveMemberEnumeratorTest extends TestCase {

	/**
	 * Temporary files made during a test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	private FileSettingsHandler&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->settings = $this->createMock(FileSettingsHandler::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}
	}

	private function tempFile(): string {
		$file          = (string) tempnam(sys_get_temp_dir(), 'or-zip-');
		$this->files[] = $file;
		return $file;
	}

	private function enumerator(): ArchiveMemberEnumerator {
		$mimes = $this->createMock(IMimeTypeDetector::class);
		$mimes->method('detectPath')->willReturnCallback(
			static fn (string $path): string => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
				'zip' => 'application/zip',
				'txt' => 'text/plain',
				'pdf' => 'application/pdf',
				default => 'application/octet-stream',
			}
		);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn (): string => $this->tempFile());

		return new ArchiveMemberEnumerator($mimes, $temp, $this->settings, $this->logger);
	}

	/**
	 * A zip with these members, built on disk.
	 *
	 * @param array<string, string> $members Member name => contents.
	 * @param list<string>          $encrypt Members to encrypt.
	 *
	 * @return string The zip's path.
	 */
	private function zip(array $members, array $encrypt=[]): string {
		$path = $this->tempFile();
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($members as $name => $contents) {
			$zip->addFromString($name, $contents);
			if (in_array($name, $encrypt, true) === true) {
				$zip->setEncryptionName($name, ZipArchive::EM_AES_256, 'secret');
			}
		}

		$zip->close();
		return $path;
	}

	public function testMembersAreListedWithTypeAndSize(): void {
		$listing = $this->enumerator()->enumeratePath($this->zip(['besluit.txt' => 'Het besluit.', 'docs/' => '', 'docs/bijlage.pdf' => '%PDF-1.4']), new ArchiveBounds());

		$this->assertTrue($listing->isComplete());
		$members = $listing->members();
		$this->assertSame(['besluit.txt', 'docs/bijlage.pdf'], array_column($members, 'path'));
		$this->assertSame(['text/plain', 'application/pdf'], array_column($members, 'mimeType'));
		$this->assertSame([12, 8], array_column($members, 'size'));
		$this->assertSame([ArchiveListing::LISTED, ArchiveListing::LISTED], array_column($members, 'status'));
		$this->assertSame([1, 1], array_column($members, 'depth'));
		$this->assertGreaterThan(0, $members[0]['compressedSize']);
	}

	public function testARatioOverTheBoundStopsEnumeration(): void {
		$listing = $this->enumerator()->enumeratePath(
			$this->zip(['a.txt' => 'eerst', 'bomb.txt' => str_repeat("\0", 2000000), 'z.txt' => 'daarna']),
			new ArchiveBounds()
		);

		$this->assertSame('maxRatio', $listing->stoppedAt());
		$this->assertSame('bomb.txt', $listing->stoppedMember());
		$this->assertSame(['a.txt'], array_column($listing->members(), 'path'));
		$this->assertFalse($listing->isComplete());
	}

	public function testTooManyMembersStops(): void {
		$listing = $this->enumerator()->enumeratePath(
			$this->zip(['1.txt' => 'een', '2.txt' => 'twee', '3.txt' => 'drie']),
			new ArchiveBounds(maxMembers: 2)
		);

		$this->assertSame('maxMembers', $listing->stoppedAt());
		$this->assertSame('3.txt', $listing->stoppedMember());
		$this->assertCount(2, $listing->members());
	}

	public function testTooManyBytesStops(): void {
		$listing = $this->enumerator()->enumeratePath(
			$this->zip(['1.txt' => 'abcdefghij', '2.txt' => 'klmnopqrst']),
			new ArchiveBounds(maxTotalBytes: 15)
		);

		$this->assertSame('maxTotalBytes', $listing->stoppedAt());
		$this->assertSame('2.txt', $listing->stoppedMember());
	}

	public function testATraversalPathIsRefused(): void {
		$listing = $this->enumerator()->enumeratePath(
			$this->zip(['../../config/config.php' => '<?php $x = 1;', 'ok.txt' => 'goed']),
			new ArchiveBounds()
		);

		$this->assertTrue($listing->isComplete());
		$this->assertSame(
			[['../../config/config.php', ArchiveListing::REFUSED, ''], ['ok.txt', ArchiveListing::LISTED, 'text/plain']],
			array_map(static fn (array $m): array => [$m['path'], $m['status'], $m['mimeType']], $listing->members())
		);
	}

	public function testPathsThatLeaveTheArchiveAreRecognised(): void {
		foreach (['../x', 'a/../../x', '/etc/passwd', '\\windows\\x', 'C:\\x', "a\0b", 'a\\..\\x', ''] as $name) {
			$this->assertTrue(ArchiveMemberEnumerator::leavesTheArchive($name), $name);
		}

		foreach (['a/b.txt', 'a..b.txt', '..hidden', 'map/...'] as $name) {
			$this->assertFalse(ArchiveMemberEnumerator::leavesTheArchive($name), $name);
		}
	}

	public function testANestedZipIsListedOneLevelDeep(): void {
		$inner = $this->zip(['binnen.txt' => 'binnen', 'diep.zip' => file_get_contents($this->zip(['diepst.txt' => 'x']))]);
		$listing = $this->enumerator()->enumeratePath(
			$this->zip(['buiten.txt' => 'buiten', 'pakket.zip' => file_get_contents($inner)]),
			new ArchiveBounds()
		);

		$this->assertSame(
			[['buiten.txt', 1], ['pakket.zip', 1], ['pakket.zip/binnen.txt', 2], ['pakket.zip/diep.zip', 2]],
			array_map(static fn (array $m): array => [$m['path'], $m['depth']], array_slice($listing->members(), 0, 4))
		);
		$this->assertSame('maxDepth', $listing->stoppedAt());
		$this->assertSame('pakket.zip/diep.zip', $listing->stoppedMember());
	}

	public function testNestedMembersCountTowardsTheBounds(): void {
		$inner   = $this->zip(['1.txt' => 'een', '2.txt' => 'twee']);
		$listing = $this->enumerator()->enumeratePath($this->zip(['pakket.zip' => file_get_contents($inner)]), new ArchiveBounds(maxMembers: 2));

		$this->assertSame('maxMembers', $listing->stoppedAt());
		$this->assertSame('pakket.zip/2.txt', $listing->stoppedMember());
	}

	public function testAnEncryptedMemberIsListedAsEncrypted(): void {
		$listing = $this->enumerator()->enumeratePath(
			$this->zip(['geheim.txt' => 'geheim', 'open.txt' => 'open'], ['geheim.txt']),
			new ArchiveBounds()
		);

		$this->assertSame(
			[['geheim.txt', ArchiveListing::ENCRYPTED], ['open.txt', ArchiveListing::LISTED]],
			array_map(static fn (array $m): array => [$m['path'], $m['status']], $listing->members())
		);
	}

	public function testANonZipGivesAnEmptyListing(): void {
		$path = $this->tempFile();
		file_put_contents($path, 'not a zip');

		$listing = $this->enumerator()->enumeratePath($path, new ArchiveBounds());

		$this->assertSame([], $listing->members());
		$this->assertTrue($listing->isComplete());
	}

	public function testTheBoundsComeFromTheFileSettings(): void {
		$this->settings->method('getFileSettingsOnly')->willReturn(['archive' => ['maxMembers' => 1]]);

		$listing = $this->enumerator()->enumeratePath($this->zip(['1.txt' => 'een', '2.txt' => 'twee']));

		$this->assertSame('maxMembers', $listing->stoppedAt());
	}

	public function testUnreadableSettingsUseTheDefaults(): void {
		$this->settings->method('getFileSettingsOnly')->willThrowException(new RuntimeException('broken'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertTrue($this->enumerator()->enumeratePath($this->zip(['1.txt' => 'een']))->isComplete());
	}

	public function testANextcloudFileIsCopiedAndEnumerated(): void {
		$zip  = $this->zip(['besluit.txt' => 'Het besluit.']);
		$file = $this->createMock(File::class);
		$file->method('fopen')->with('r')->willReturnCallback(static fn () => fopen($zip, 'r'));
		$this->settings->method('getFileSettingsOnly')->willReturn([]);

		$this->assertSame(['besluit.txt'], array_column($this->enumerator()->enumerate($file)->members(), 'path'));
	}

	public function testBoundsFromSettingsKeepDefaultsForBadValuesAndCapTheDepth(): void {
		$bounds = ArchiveBounds::fromSettings(['archive' => ['maxMembers' => '50', 'maxTotalBytes' => 0, 'maxRatio' => 'many', 'maxDepth' => 5]]);

		$this->assertSame(['maxMembers' => 50, 'maxTotalBytes' => 1073741824, 'maxRatio' => 100, 'maxDepth' => 2], $bounds->toArray());
		$this->assertSame(ArchiveBounds::DEFAULTS, ArchiveBounds::fromSettings(['archive' => 'x'])->toArray());
	}
}
