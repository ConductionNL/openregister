<?php

/**
 * Unit tests for purging expired or unreadable upload-claim records.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormUploadClaims;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\SimpleFS\ISimpleFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A live record is kept; an expired, expiry-less or unreadable one is deleted.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormUploadClaims
 */
class FormUploadClaimsPurgeTest extends TestCase {

	/**
	 * The fixed clock reading.
	 */
	private const NOW = 1800000000;

	/**
	 * The claims over a clock frozen at NOW.
	 *
	 * @return FormUploadClaims
	 */
	private function claims(): FormUploadClaims {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new FormUploadClaims($time);

	}//end claims()

	/**
	 * A record that has not expired is kept.
	 *
	 * @return void
	 */
	public function testALiveRecordIsKept(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn(json_encode(['expiresAt' => self::NOW + 1]));
		$file->expects($this->never())->method('delete');

		$this->assertSame(0, $this->claims()->purgeIfExpired($file));

	}//end testALiveRecordIsKept()

	/**
	 * Record contents that are deleted.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function deadRecords(): array {
		return [
			'expired'           => [json_encode(['expiresAt' => self::NOW - 60])],
			'expiring this second' => [json_encode(['expiresAt' => self::NOW])],
			'without an expiry' => [json_encode(['form' => 'f'])],
			'unreadable'        => ['{not json'],
			'a json scalar'     => ['"text"'],
		];

	}//end deadRecords()

	/**
	 * A record that is expired, has no expiry or cannot be read is deleted.
	 *
	 * @param string $content The record file's content.
	 *
	 * @return void
	 */
	#[DataProvider('deadRecords')]
	public function testADeadRecordIsDeleted(string $content): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn($content);
		$file->expects($this->once())->method('delete');

		$this->assertSame(1, $this->claims()->purgeIfExpired($file));

	}//end testADeadRecordIsDeleted()
}//end class
