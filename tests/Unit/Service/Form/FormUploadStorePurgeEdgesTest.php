<?php

/**
 * Unit tests for the upload purge on a missing folder, stray files and unreadable records.
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

use OCA\OpenRegister\Service\File\FilePropertyRules;
use OCA\OpenRegister\Service\Form\FormFileStorage;
use OCA\OpenRegister\Service\Form\FormUploadStore;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * Nothing to purge without a folder; a file that is neither record nor bytes is left alone;
 * a record that cannot be read is purged with its bytes.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormUploadStore
 */
class FormUploadStorePurgeEdgesTest extends TestCase {

	/**
	 * The in-memory app data.
	 *
	 * @var MemoryAppData
	 */
	private MemoryAppData $appData;

	/**
	 * A store over in-memory app data at a fixed time.
	 *
	 * @return FormUploadStore
	 */
	private function store(): FormUploadStore {
		$this->appData = new MemoryAppData();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1800000000);

		return new FormUploadStore(
			new FormFileStorage(appData: $this->appData, time: $time),
			$this->createMock(ITempManager::class),
			new FilePropertyRules(),
			$this->createMock(IL10N::class)
		);

	}//end store()

	/**
	 * Without the uploads folder there is nothing to purge, and the folder is not created.
	 *
	 * @return void
	 */
	public function testWithoutAFolderNothingIsPurged(): void {
		$store = $this->store();

		$this->assertSame(0, $store->purge());
		$this->assertArrayNotHasKey(FormUploadStore::FOLDER, $this->appData->folders);

	}//end testWithoutAFolderNothingIsPurged()

	/**
	 * A stray file is kept; an unreadable record is purged together with its bytes.
	 *
	 * @return void
	 */
	public function testAStrayFileIsKeptAndAnUnreadableRecordIsPurged(): void {
		$store  = $this->store();
		$folder = $this->appData->newFolder(FormUploadStore::FOLDER);
		$folder->newFile('README.txt', 'not ours to judge');
		$folder->newFile('0123456789abcdef0123456789abcdef.json', '{"expiresAt": "soon"}');
		$folder->newFile('0123456789abcdef0123456789abcdef.bin', 'bytes');

		$this->assertSame(1, $store->purge());
		$this->assertTrue($folder->fileExists('README.txt'));
		$this->assertFalse($folder->fileExists('0123456789abcdef0123456789abcdef.json'));
		$this->assertFalse($folder->fileExists('0123456789abcdef0123456789abcdef.bin'));

	}//end testAStrayFileIsKeptAndAnUnreadableRecordIsPurged()
}//end class
