<?php

/**
 * The form stores' folder lookup creates its folder only when asked.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec exclude Pins the store folder lookup shared by the form stores.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\AppDataFolder;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * folder(create: false) never creates; folder(create: true) creates once.
 */
class AppDataFolderTest extends TestCase {

	/**
	 * A store that uses the trait over the given app data.
	 *
	 * @param MemoryAppData $appData The app data.
	 *
	 * @return object The store.
	 */
	private function store(MemoryAppData $appData): object {
		return new class ($appData) {
			use AppDataFolder;

			public const FOLDER = 'pinned-folder';

			/**
			 * Constructor.
			 *
			 * @param MemoryAppData $appData The app data.
			 */
			public function __construct(private readonly MemoryAppData $appData) {
			}//end __construct()
		};
	}//end store()

	/**
	 * Call the trait's private folder().
	 *
	 * @param object $store  The store.
	 * @param bool   $create Whether to create.
	 *
	 * @return mixed The folder or null.
	 */
	private function folder(object $store, bool $create): mixed {
		return (new ReflectionMethod($store, 'folder'))->invoke($store, $create);
	}//end folder()

	/**
	 * Without create, an absent folder stays absent.
	 *
	 * @return void
	 */
	public function testAbsentFolderIsNotCreatedWhenNotAsked(): void {
		$appData = new MemoryAppData();
		$this->assertNull($this->folder($this->store($appData), false));

		$this->expectException(NotFoundException::class);
		$appData->getFolder('pinned-folder');
	}//end testAbsentFolderIsNotCreatedWhenNotAsked()

	/**
	 * With create, the folder is made once and then found.
	 *
	 * @return void
	 */
	public function testFolderIsCreatedWhenAskedAndFoundAfterwards(): void {
		$appData = new MemoryAppData();
		$store = $this->store($appData);

		$created = $this->folder($store, true);
		$this->assertSame($created, $appData->getFolder('pinned-folder'));
		$this->assertSame($created, $this->folder($store, false));
		$this->assertSame($created, $this->folder($store, true));
	}//end testFolderIsCreatedWhenAskedAndFoundAfterwards()
}//end class
