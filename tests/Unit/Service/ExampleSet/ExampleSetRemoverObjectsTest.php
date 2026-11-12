<?php

/**
 * Unit test for ExampleSetRemoverObjects: the collaborators it bundles for the remover.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverObjects;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverObjects
 */
class ExampleSetRemoverObjectsTest extends TestCase {
	/**
	 * Each collaborator is held as given.
	 *
	 * @return void
	 */
	public function testHoldsEachCollaborator(): void {
		$store = $this->createMock(ExampleSetStore::class);
		$record = $this->createMock(ExampleSetRecord::class);
		$files = $this->createMock(ExampleSetFiles::class);
		$changes = $this->createMock(ExampleSetChanges::class);
		$bundle = new ExampleSetRemoverObjects(store: $store, record: $record, files: $files, changes: $changes);
		$this->assertSame($store, $bundle->store);
		$this->assertSame($record, $bundle->record);
		$this->assertSame($files, $bundle->files);
		$this->assertSame($changes, $bundle->changes);
	}//end testHoldsEachCollaborator()
}//end class
