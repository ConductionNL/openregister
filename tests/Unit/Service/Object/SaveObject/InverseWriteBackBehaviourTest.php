<?php

/**
 * Unit tests for writing a relation back onto its targets: what is written, and when nothing is.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject\CascadeRun;
use OCA\OpenRegister\Service\Object\SaveObject\InverseWriteBack;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A complete write-back appends the source to each target's inverse list; an incomplete declaration,
 * an unknown target schema or an unreadable target writes nothing for it.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\InverseWriteBack
 */
class InverseWriteBackBehaviourTest extends TestCase {

	private const TARGET_1 = '11111111-1111-4111-8111-111111111111';
	private const TARGET_2 = '22222222-2222-4222-8222-222222222222';

	/**
	 * The object finder, mocked.
	 *
	 * @var MagicObjectFinderInterface&MockObject
	 */
	private MagicObjectFinderInterface&MockObject $objects;

	/**
	 * The writer, mocked.
	 *
	 * @var SaveObjectWriterInterface&MockObject
	 */
	private SaveObjectWriterInterface&MockObject $saver;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(MagicObjectFinderInterface::class);
		$this->saver   = $this->createMock(SaveObjectWriterInterface::class);

	}//end setUp()

	/**
	 * Write back one property definition from a source `case-1` in register 4.
	 *
	 * @param array<string, mixed> $definition The property definition of `notes`.
	 * @param array<string, mixed> $data       The source data.
	 *
	 * @return array<string, mixed> The source data after the write-back.
	 */
	private function writeBack(array $definition, array $data): array {
		$note = new Schema();
		$note->setId(5);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id) use ($note): Schema {
				if ($id === 'note') {
					return $note;
				}

				throw new DoesNotExistException('no schema ' . $id);
			}
		);
		$schemas->method('findAll')->willReturn([]);

		$parent = new ObjectEntity();
		$parent->setUuid('case-1');
		$parent->setRegister('4');

		$run         = new CascadeRun();
		$run->parent = $parent;
		$run->data   = $data;

		$source = new Schema();
		$source->setProperties(['title' => ['type' => 'string'], 'notes' => $definition]);

		return (new InverseWriteBack(
			$this->objects,
			$this->saver,
			new SaveLookups($schemas, $this->createMock(RegisterMapperInterface::class)),
			$this->createMock(IURLGenerator::class)
		))->write($run, $source);

	}//end writeBack()

	/**
	 * A stored target.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $body The body.
	 *
	 * @return ObjectEntity
	 */
	private function target(string $uuid, array $body): ObjectEntity {
		$target = new ObjectEntity();
		$target->setUuid($uuid);
		$target->setObject($body);

		return $target;

	}//end target()

	/**
	 * The source is appended to the target's inverse list and, when asked, removed from the source.
	 *
	 * @return void
	 */
	public function testTheSourceIsAppendedAndRemovedWhenAsked(): void {
		$this->objects->method('find')->willReturn($this->target(self::TARGET_1, ['cases' => ['case-0']]));
		$this->saver->expects($this->once())
			->method('saveObject')
			->with('4', '5', $this->callback(static fn (array $d): bool => $d['cases'] === ['case-0', 'case-1']), self::TARGET_1);

		$data = $this->writeBack(
			['type' => 'array', 'items' => ['$ref' => 'note', 'inversedBy' => 'cases', 'writeBack' => true, 'removeAfterWriteBack' => true]],
			['title' => 'x', 'notes' => [self::TARGET_1]]
		);

		$this->assertSame(['title' => 'x'], $data);

	}//end testTheSourceIsAppendedAndRemovedWhenAsked()

	/**
	 * A declaration without a target schema writes nothing and keeps the source data.
	 *
	 * @return void
	 */
	public function testADeclarationWithoutATargetSchemaWritesNothing(): void {
		$this->objects->expects($this->never())->method('find');
		$this->saver->expects($this->never())->method('saveObject');

		$data = $this->writeBack(
			['type' => 'array', 'items' => ['inversedBy' => 'cases', 'writeBack' => true, 'removeAfterWriteBack' => true]],
			['notes' => [self::TARGET_1]]
		);

		$this->assertSame(['notes' => [self::TARGET_1]], $data);

	}//end testADeclarationWithoutATargetSchemaWritesNothing()

	/**
	 * A target schema nobody can resolve writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownTargetSchemaWritesNothing(): void {
		$this->objects->expects($this->never())->method('find');
		$this->saver->expects($this->never())->method('saveObject');

		$data = $this->writeBack(
			['type' => 'array', 'items' => ['$ref' => 'ghost', 'inversedBy' => 'cases', 'writeBack' => true, 'removeAfterWriteBack' => true]],
			['notes' => [self::TARGET_1]]
		);

		$this->assertSame(['notes' => [self::TARGET_1]], $data);

	}//end testAnUnknownTargetSchemaWritesNothing()

	/**
	 * A target that cannot be read is skipped; the next target is still written.
	 *
	 * @return void
	 */
	public function testAnUnreadableTargetIsSkipped(): void {
		$this->objects->method('find')->willReturnCallback(
			function (string $id): ObjectEntity {
				if ($id === self::TARGET_1) {
					throw new DoesNotExistException('gone');
				}

				return $this->target(self::TARGET_2, []);
			}
		);
		$this->saver->expects($this->once())
			->method('saveObject')
			->with('4', '5', $this->callback(static fn (array $d): bool => $d['cases'] === ['case-1']), self::TARGET_2);

		$this->writeBack(
			['type' => 'array', 'items' => ['$ref' => 'note', 'inversedBy' => 'cases', 'writeBack' => true]],
			['notes' => [self::TARGET_1, self::TARGET_2]]
		);

	}//end testAnUnreadableTargetIsSkipped()
}//end class
