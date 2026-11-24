<?php

/**
 * A dry-run streaming batch runs every row through the save with persisting off, and only that row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/widget-record-import/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence;
use OCA\OpenRegister\Service\Object\SaveObject\StreamingSave;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * `saveObject()` is the stub; the loop, the dry-run marker and the verdict routing are real.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\StreamingSave
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\StreamRun
 * @uses   \OCA\OpenRegister\Service\Object\BatchOperationStatus
 * @uses   \OCA\OpenRegister\Service\Object\RowFailure
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 */
class SaveObjectStreamingDryRunTest extends TestCase {

	/**
	 * Per row: the `persist` argument and whether the handler was previewing during the save.
	 *
	 * @var list<array{persist: bool, previewing: bool}>
	 */
	private array $seen = [];

	/**
	 * A handler whose saveObject() records how it was called, publishes the verdicts given and throws on "bad".
	 *
	 * @param list<string> $verdicts One verdict per row that does not throw.
	 *
	 * @return SaveObject
	 */
	private function handler(array $verdicts): SaveObject {
		$handler = $this->createPartialMock(SaveObject::class, ['saveObject']);
		$existence = (new \ReflectionClass(ReferenceExistence::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(SaveObject::class, 'referenceExistence'))->setValue($handler, $existence);
		(new ReflectionProperty(SaveObject::class, 'logger'))->setValue($handler, $this->createMock(LoggerInterface::class));
		$previewing = new ReflectionProperty(SaveObject::class, 'previewing');
		$slot = new ReflectionProperty(SaveObject::class, 'lastSaveOutcome');

		$call = 0;
		$handler->method('saveObject')->willReturnCallback(
			function (array $data, $options, bool $rbac = true, bool $multitenancy = true, bool $persist = true) use ($handler, $previewing, $slot, $verdicts, &$call): ObjectEntity {
				$this->seen[] = ['persist' => $persist, 'previewing' => $previewing->getValue($handler)];
				if (($data['title'] ?? '') === 'bad') {
					throw new ValidationException('title is not valid');
				}

				$slot->setValue($handler, $verdicts[$call]);
				$call++;
				$entity = new ObjectEntity();
				$entity->setUuid('uuid-' . $call);
				return $entity;
			}
		);

		return $handler;
	}

	public function testADryRunSavesEveryRowWithoutPersistingAndClassifiesIt(): void {
		$handler = $this->handler(['created', 'updated', 'unchanged']);

		$status = $handler->saveObjectsStreaming(
			save: new StreamingSave(register: 1, schema: 1, rows: [['title' => 'a'], ['id' => 'b'], ['id' => 'c']], dryRun: true)
		);

		$this->assertSame(
			[
				['persist' => false, 'previewing' => true],
				['persist' => false, 'previewing' => true],
				['persist' => false, 'previewing' => true],
			],
			$this->seen
		);
		$this->assertSame([1, 1, 1], [$status->getCreatedCount(), $status->getUpdatedCount(), $status->getUnchangedCount()]);
		$this->assertFalse((new ReflectionProperty(SaveObject::class, 'previewing'))->getValue($handler));
	}

	public function testARealBatchPersistsAndIsNeverPreviewing(): void {
		$handler = $this->handler(['created']);

		$handler->saveObjectsStreaming(save: new StreamingSave(register: 1, schema: 1, rows: [['title' => 'a']]));

		$this->assertSame([['persist' => true, 'previewing' => false]], $this->seen);
	}

	public function testAFailingDryRunRowIsRecordedAndDoesNotLeaveTheHandlerPreviewing(): void {
		$handler = $this->handler(['created', 'created']);

		$status = $handler->saveObjectsStreaming(
			save: new StreamingSave(register: 1, schema: 1, rows: [['title' => 'bad'], ['title' => 'a']], dryRun: true)
		);

		$this->assertSame(1, $status->getFailedCount());
		$this->assertSame('title is not valid', $status->getFailed()[0]['message']);
		$this->assertSame(0, $status->getFailed()[0]['index']);
		$this->assertSame(1, $status->getCreatedCount());
		$this->assertFalse((new ReflectionProperty(SaveObject::class, 'previewing'))->getValue($handler));

		// The same handler, next a real save: it persists.
		$this->seen = [];
		$handler->saveObjectsStreaming(save: new StreamingSave(register: 1, schema: 1, rows: [['title' => 'a']]));
		$this->assertSame([['persist' => true, 'previewing' => false]], $this->seen);
	}

	public function testThePreviewedUpdateIsUnchangedOnlyWhenTheDataIsIdentical(): void {
		$outcome = new ReflectionMethod(SaveObject::class, 'previewUpdateOutcome');

		$this->assertSame('unchanged', $outcome->invoke(null, ['a' => 1, 'b' => 'x'], ['a' => 1, 'b' => 'x']));
		$this->assertSame('updated', $outcome->invoke(null, ['a' => 2, 'b' => 'x'], ['a' => 1, 'b' => 'x']));
		$this->assertSame('updated', $outcome->invoke(null, ['b' => 'x', 'a' => 1], ['a' => 1, 'b' => 'x']));
		$this->assertSame('updated', $outcome->invoke(null, ['a' => '1'], ['a' => 1]));
	}
}
