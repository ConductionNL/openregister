<?php

/**
 * Golden tests for LinkedEntityPropertyHandler::extractAndPopulate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which ids land in which metadata column, their order, merging and the debug log.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler
 */
class LinkedEntityPropertyHandlerGoldenTest extends TestCase {

	/**
	 * Run the handler.
	 *
	 * @param array|null $properties The schema properties.
	 * @param array $data The object data.
	 * @param ObjectEntity|null $object The object.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ObjectEntity The object returned.
	 */
	private function populate(?array $properties, array $data, ?ObjectEntity $object = null, ?LoggerInterface $logger = null): ObjectEntity {
		$schema = new Schema();
		$schema->setProperties($properties);
		$object ??= new ObjectEntity();
		$handler = new LinkedEntityPropertyHandler($logger ?? $this->createMock(LoggerInterface::class));
		$object->setObject($data);
		$result = $handler->extractAndPopulate($object, $schema);
		$this->assertSame($object, $result);

		return $result;
	}//end populate()

	/**
	 * The eight metadata columns.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The columns.
	 */
	private function columns(ObjectEntity $object): array {
		return [
			'mail' => $object->getMail(),
			'contacts' => $object->getContacts(),
			'notes' => $object->getNotes(),
			'todos' => $object->getTodos(),
			'calendar' => $object->getCalendar(),
			'talk' => $object->getTalk(),
			'deck' => $object->getDeck(),
			'files' => $object->getFiles(),
		];
	}//end columns()

	public function testEveryTypeAndShape(): void {
		$properties = [
			'm' => ['type' => 'NcMail'],
			'c' => ['type' => 'NcContact'],
			'n' => ['type' => 'NcNote'],
			't' => ['type' => 'NcTodo'],
			'e' => ['type' => 'NcCalendarEvent'],
			'k' => ['type' => 'NcTalk'],
			'd' => ['type' => 'NcDeck'],
			'f' => ['type' => 'array', 'items' => ['type' => 'NcFile']],
			'm2' => ['type' => 'array', 'items' => ['type' => 'NcMail']],
			'plain' => ['type' => 'string'],
			'scalar' => 'NcMail',
			7 => ['type' => 'NcDeck'],
		];
		$data = [
			'm' => ['type' => 'NcMail', 'id' => '1/6', 'label' => 'x'],
			'c' => 'contact-1',
			'n' => ['id' => 42],
			't' => '',
			'e' => ['label' => 'no id'],
			'k' => null,
			'd' => 'deck-1',
			'f' => [['id' => 'f1'], 'f2', null, '', ['id' => 'f1'], 5],
			'm2' => [['id' => '1/6'], ['id' => '2/9']],
			'plain' => 'ignored',
			'scalar' => 'ignored',
			7 => 'deck-2',
		];

		$this->assertSame(
			[
				'mail' => ['1/6', '2/9'],
				'contacts' => ['contact-1'],
				'notes' => ['42'],
				'todos' => null,
				'calendar' => null,
				'talk' => null,
				'deck' => ['deck-1', 'deck-2'],
				'files' => ['f1', 'f2'],
			],
			$this->columns($this->populate($properties, $data))
		);
	}//end testEveryTypeAndShape()

	public function testExistingAdHocLinksArePreservedAndDeduplicated(): void {
		$object = new ObjectEntity();
		$object->setMail(['old', '1/6']);
		$object->setFiles(['x']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'[LinkedEntityPropertyHandler] Merged IDs into metadata column',
			['ncType' => 'NcMail', 'newIds' => ['1/6', 'new', 'new'], 'existing' => 2, 'merged' => 3]
		);

		$this->populate(
			['a' => ['type' => 'array', 'items' => ['type' => 'NcMail']], 'f' => ['type' => 'array', 'items' => ['type' => 'NcFile']]],
			['a' => ['1/6', 'new', ['id' => 'new']], 'f' => 'not-a-list'],
			$object,
			$logger
		);

		$this->assertSame(['old', '1/6', 'new'], $object->getMail());
		$this->assertSame(['x'], $object->getFiles());
	}//end testExistingAdHocLinksArePreservedAndDeduplicated()

	public function testOneLogLinePerTypeInFirstSeenOrder(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$context['ncType'], $context['newIds'], $context['existing'], $context['merged']];
			}
		);

		$this->populate(
			['t1' => ['type' => 'NcTalk'], 'm' => ['type' => 'NcMail'], 't2' => ['type' => 'NcTalk']],
			['t1' => 'a', 'm' => 'b', 't2' => 'a'],
			null,
			$logger
		);

		$this->assertSame([['NcTalk', ['a', 'a'], 0, 1], ['NcMail', ['b'], 0, 1]], $logged);
	}//end testOneLogLinePerTypeInFirstSeenOrder()

	public function testNothingHappensWithoutProperties(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('debug');
		$empty = ['mail' => null, 'contacts' => null, 'notes' => null, 'todos' => null, 'calendar' => null, 'talk' => null, 'deck' => null, 'files' => []];

		$this->assertSame($empty, $this->columns($this->populate(null, ['m' => 'x'], null, $logger)));
		$this->assertSame($empty, $this->columns($this->populate([], ['m' => 'x'], null, $logger)));
		$this->assertSame($empty, $this->columns($this->populate(['m' => ['type' => 'NcMail']], [], null, $logger)));
	}//end testNothingHappensWithoutProperties()
}//end class
