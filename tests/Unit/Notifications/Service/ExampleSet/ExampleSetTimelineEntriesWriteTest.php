<?php

/**
 * Characterisation test of ExampleSetTimelineEntries::write().
 *
 * Pins every field of a written entry, the reuse of an entry an earlier
 * install wrote, and the error for a missing object or a failed write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\ExampleSet
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- test doubles named for what they stand in for.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Db\TimelineEntryMapper;
use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Party\PartyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class ExampleSetTimelineEntriesWriteTest extends TestCase {
	private PartyService&MockObject $parties;

	private TimelineEntryMapper&MockObject $mapper;

	private TimelineKindService&MockObject $kinds;

	private ExampleSetTimelineEntries $entries;

	protected function setUp(): void {
		parent::setUp();
		$this->parties = $this->createMock(PartyService::class);
		$this->mapper = $this->createMock(TimelineEntryMapper::class);
		$this->kinds = $this->createMock(TimelineKindService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => match ($id) {
			PartyService::class => $this->parties,
			TimelineEntryMapper::class => $this->mapper,
			TimelineKindService::class => $this->kinds,
		});
		$this->entries = new ExampleSetTimelineEntries(container: $container);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('7');
		$object->setSchema('9');
		return $object;
	}

	private static function row(array $extra = []): ExampleSetRow {
		return new ExampleSetRow(
			'alpha',
			'status-1',
			[...[
				'object' => 'obj-1',
				'kind' => 'statuswijziging',
				'message' => 'Status gewijzigd',
				'author' => 'esra',
				'at' => '2026-10-04T09:12:00+02:00',
				'fields' => ['to' => 'Open'],
			], ...$extra]
		);
	}

	public function testAMissingObjectIsAnError(): void {
		$this->parties->method('findUnscoped')->with('obj-1')->willReturn(null);
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame(['error' => 'no object "obj-1" to give the entry', 'entry' => null], $this->entries->write(self::row()));
	}

	public function testAnEntryAnEarlierInstallWroteIsReused(): void {
		$this->parties->method('findUnscoped')->willReturn($this->object());
		$other = new TimelineEntry();
		$other->setUuid('other');
		$other->setRawSource('example-set:alpha/other');
		$earlier = new TimelineEntry();
		$earlier->setUuid('earlier');
		$earlier->setRawSource('example-set:alpha/status-1');
		$this->mapper->expects($this->once())->method('findForObject')->with('obj-1', null, 500)->willReturn([$other, $earlier]);
		$this->mapper->expects($this->never())->method('insert');
		$this->kinds->expects($this->never())->method('validateFields');

		$this->assertSame(['error' => '', 'entry' => ['uuid' => 'earlier', 'object' => 'obj-1']], $this->entries->write(self::row()));
	}

	public function testANewEntryCarriesEveryField(): void {
		$this->parties->method('findUnscoped')->willReturn($this->object());
		$this->mapper->method('findForObject')->willReturn([]);
		$this->kinds->expects($this->once())->method('validateFields')
			->with('statuswijziging', ['to' => 'Open'])->willReturn(['to' => 'Open!']);
		$inserted = null;
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (TimelineEntry $entry) use (&$inserted): TimelineEntry {
				$inserted = $entry;
				$saved = new TimelineEntry();
				$saved->setUuid('saved-uuid');
				return $saved;
			}
		);

		$this->assertSame(['error' => '', 'entry' => ['uuid' => 'saved-uuid', 'object' => 'obj-1']], $this->entries->write(self::row()));

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $inserted->getUuid());
		$this->assertSame(
			['obj-1', '7', '9', 'statuswijziging', 'esra', 'internal', 'Status gewijzigd', ['to' => 'Open!'], false, 'example-set:alpha/status-1'],
			[
				$inserted->getObjectUuid(), $inserted->getRegister(), $inserted->getSchema(), $inserted->getKind(),
				$inserted->getAuthor(), $inserted->getVisibility(), $inserted->getMessage(), $inserted->getFields(),
				$inserted->getPinned(), $inserted->getRawSource(),
			]
		);
		$this->assertSame('2026-10-04T09:12:00+02:00', $inserted->getCreated()->format('c'));
		$this->assertEquals($inserted->getCreated(), $inserted->getUpdated());
	}

	public function testAnEntryWithoutAuthorOrFieldsIsTheSystemsWithEmptyFields(): void {
		$this->parties->method('findUnscoped')->willReturn($this->object());
		$this->mapper->method('findForObject')->willReturn([]);
		$this->kinds->expects($this->once())->method('validateFields')->with('statuswijziging', [])->willReturn([]);
		$inserted = null;
		$this->mapper->method('insert')->willReturnCallback(
			static function (TimelineEntry $entry) use (&$inserted): TimelineEntry {
				$inserted = $entry;
				return $entry;
			}
		);
		$row = self::row(['visibility' => 'public']);
		$data = $row->data;
		unset($data['author'], $data['fields']);

		$result = $this->entries->write(new ExampleSetRow('alpha', 'status-1', $data));

		$this->assertSame('', $result['error']);
		$this->assertNull($inserted->getAuthor());
		$this->assertSame('public', $inserted->getVisibility());
	}

	public function testAFailedWriteIsTheError(): void {
		$this->parties->method('findUnscoped')->willReturn($this->object());
		$this->mapper->method('findForObject')->willReturn([]);
		$this->kinds->method('validateFields')->willThrowException(new \InvalidArgumentException('No entry kind named x'));
		$this->mapper->expects($this->never())->method('insert');

		$this->assertSame(['error' => 'No entry kind named x', 'entry' => null], $this->entries->write(self::row()));
	}

	public function testAFailedLookupIsTheError(): void {
		$this->parties->method('findUnscoped')->willReturn($this->object());
		$this->mapper->method('findForObject')->willThrowException(new \RuntimeException('db down'));

		$this->assertSame(['error' => 'db down', 'entry' => null], $this->entries->write(self::row()));
	}
	public static function refusals(): array {
		$good = ['object' => 'o', 'kind' => 'k', 'message' => 'm', 'at' => '2026-10-04T09:12:00+02:00', 'author' => 'esra'];
		return [
			'fine' => [$good, ''],
			'unknown fields' => [[...$good, 'pinned' => true, 'x' => 1], 'field "pinned", "x" is not a timeline entry field a set may write'],
			'no object' => [[...$good, 'object' => ' '], 'a timeline entry needs the object it is on (object)'],
			'kind not a string' => [[...$good, 'kind' => 3], 'a timeline entry needs a kind (kind)'],
			'no message' => [[...$good, 'message' => ''], 'a timeline entry needs a message (message)'],
			'no moment and no message' => [[...$good, 'message' => '', 'at' => ''], 'a timeline entry needs a message (message)'],
			'foreign author' => [[...$good, 'author' => 'nobody'], '"nobody" is not an account the set creates or adopts'],
			'fields a text' => [[...$good, 'fields' => 'x'], 'fields is an object'],
			'bad moment' => [[...$good, 'at' => 'morgen'], 'at "morgen" is not a date and time'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
	public function testRefusalNamesExactlyWhatIsWrong(array $data, string $expected): void {
		$this->assertSame($expected, $this->entries->refusal($data, ['esra']));
	}
}
