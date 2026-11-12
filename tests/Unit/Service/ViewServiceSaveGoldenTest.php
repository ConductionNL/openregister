<?php

/**
 * Golden test pinning ViewService presentation validation and the
 * null-means-untouched field handling of update().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Search\Service\ViewAudience;
use OCA\OpenRegister\Search\Service\ViewDefinition;
use OCA\OpenRegister\Search\Service\ViewService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use OCA\OpenRegister\Search\Service\ViewEdit;

/**
 * Every presentation shape create() accepts or refuses, with the message;
 * and which fields update() leaves untouched when given null.
 *
 * @covers \OCA\OpenRegister\Search\Service\ViewService
 */
final class ViewServiceSaveGoldenTest extends TestCase {

	/**
	 * Mapper calls, in order.
	 *
	 * @var string[]
	 */
	private array $calls = [];

	/**
	 * Build the service around one stored view.
	 *
	 * @param View $stored View the mapper finds
	 *
	 * @return ViewService
	 */
	private function service(View $stored): ViewService {
		$mapper = $this->createMock(ViewMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function () use ($stored): View {
				$this->calls[] = 'find';
				return $stored;
			}
		);
		$mapper->method('findAll')->willReturnCallback(
			function () use ($stored): array {
				$this->calls[] = 'findAll';
				return [$stored];
			}
		);
		$mapper->method('insert')->willReturnCallback(
			function (View $view): View {
				$this->calls[] = 'insert';
				return $view;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (View $view): View {
				$this->calls[] = 'update:' . var_export($view->getIsDefault(), true);
				return $view;
			}
		);

		$schema = new Schema();
		$schema->setProperties(['status' => ['type' => 'string'], 'start' => ['type' => 'string'], 'end' => ['type' => 'string']]);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		return new ViewService($mapper, $this->createMock(LoggerInterface::class), $schemas);
	}//end service()

	/**
	 * A stored view owned by alice.
	 *
	 * @param bool $isDefault Whether it is alice's default
	 *
	 * @return View
	 */
	private static function stored(bool $isDefault): View {
		$view = new View();
		$id = new ReflectionProperty($view, 'id');
		$id->setValue($view, 5);
		$view->setOwner('alice');
		$view->setName('Old');
		$view->setDescription('Old desc');
		$view->setIsPublic(false);
		$view->setIsDefault($isDefault);
		$view->setQuery([]);
		$view->setFavoredBy(['bob']);
		$view->setPresentation(['viewType' => 'table']);
		$view->setSharedWith([['group' => 'g', 'mode' => 'read']]);
		return $view;
	}//end stored()

	/**
	 * Presentation shapes and the outcome of saving them.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function presentations(): array {
		$kanbanNeed = 'A kanban presentation requires a non-empty kanban.groupByField';
		$calNeed = 'A calendar presentation requires a non-empty calendar.dateField';
		$endNeed = 'calendar.endDateField must be a non-empty string when provided';
		return [
			'null' => [null, 'ok'],
			'no viewType' => [['kanban' => []], 'ok'],
			'table' => [['viewType' => 'table'], 'ok'],
			'kanban ok' => [['viewType' => 'kanban', 'kanban' => ['groupByField' => 'status']], 'ok'],
			'kanban missing' => [['viewType' => 'kanban'], $kanbanNeed],
			'kanban empty' => [['viewType' => 'kanban', 'kanban' => ['groupByField' => '']], $kanbanNeed],
			'kanban non-string' => [['viewType' => 'kanban', 'kanban' => ['groupByField' => 7]], $kanbanNeed],
			'kanban unknown field' => [['viewType' => 'kanban', 'kanban' => ['groupByField' => 'nope']], 'field'],
			'calendar ok' => [['viewType' => 'calendar', 'calendar' => ['dateField' => 'start']], 'ok'],
			'calendar end ok' => [['viewType' => 'calendar', 'calendar' => ['dateField' => 'start', 'endDateField' => 'end']], 'ok'],
			'calendar end null' => [['viewType' => 'calendar', 'calendar' => ['dateField' => 'start', 'endDateField' => null]], 'ok'],
			'calendar missing' => [['viewType' => 'calendar', 'calendar' => []], $calNeed],
			'calendar empty' => [['viewType' => 'calendar', 'calendar' => ['dateField' => '']], $calNeed],
			'calendar end empty' => [['viewType' => 'calendar', 'calendar' => ['dateField' => 'start', 'endDateField' => '']], $endNeed],
			'calendar end array' => [['viewType' => 'calendar', 'calendar' => ['dateField' => 'start', 'endDateField' => ['x']]], $endNeed],
			'calendar end unknown' => [['viewType' => 'calendar', 'calendar' => ['dateField' => 'start', 'endDateField' => 'nope']], 'field'],
			'unknown type' => [['viewType' => 'gantt'], 'Unknown presentation viewType: gantt'],
			'numeric type' => [['viewType' => 3], 'Unknown presentation viewType: 3'],
		];
	}//end presentations()

	/**
	 * Create accepts or refuses each shape before any mapper call.
	 *
	 * @param array|null $presentation Presentation config
	 * @param string     $expected     'ok', 'field' (unknown property) or the exact message
	 *
	 * @return void
	 */
	#[DataProvider('presentations')]
	public function testPresentationValidation(?array $presentation, string $expected): void {
		$service = $this->service(self::stored(false));
		try {
			$view = $service->create(new ViewEdit(definition: new ViewDefinition('N', 'D', ['schemas' => ['s1']], $presentation), audience: new ViewAudience('alice', false, false)));
			$outcome = 'ok';
			$this->assertSame($presentation, $view->getPresentation());
		} catch (InvalidArgumentException $e) {
			$outcome = $e->getMessage();
			$this->assertSame([], $this->calls);
		}

		if ($expected === 'field') {
			$this->assertStringContainsString('"nope"', $outcome);
			return;
		}

		$this->assertSame($expected, $outcome);
	}//end testPresentationValidation()

	/**
	 * Null optional fields leave the stored values; given ones replace them.
	 *
	 * @return void
	 */
	public function testUpdateNullLeavesFieldsUntouched(): void {
		$stored = self::stored(true);
		$view = $this->service($stored)->revise(
			id: 5,
			edit: new ViewEdit(
				definition: new ViewDefinition(name: 'New', description: 'New desc', query: ['q' => 1]),
				audience: new ViewAudience(owner: 'alice', isPublic: true, isDefault: true)
			)
		);

		$this->assertSame(['find', 'update:true'], $this->calls);
		$this->assertSame(['New', 'New desc', true, true, ['q' => 1]], [$view->getName(), $view->getDescription(), $view->getIsPublic(), $view->getIsDefault(), $view->getQuery()]);
		$this->assertSame(['bob'], $view->getFavoredBy());
		$this->assertSame(['viewType' => 'table'], $view->getPresentation());
		$this->assertSame([['group' => 'g', 'mode' => 'read']], $view->getSharedWith());
	}//end testUpdateNullLeavesFieldsUntouched()

	/**
	 * Given optional fields replace the stored ones; becoming default clears the old default first.
	 *
	 * @return void
	 */
	public function testUpdateGivenFieldsReplace(): void {
		$stored = self::stored(false);
		$view = $this->service($stored)->revise(
			id: 5,
			edit: new ViewEdit(
				definition: new ViewDefinition(
					name: 'New',
					description: 'New desc',
					query: [],
					presentation: ['viewType' => 'table']
				),
				audience: new ViewAudience(
					owner: 'alice',
					isPublic: false,
					isDefault: true,
					sharedWith: ['k' => ['group' => 'h', 'mode' => 'write']]
				),
				favoredBy: []
			)
		);

		$this->assertSame(['find', 'findAll', 'update:true'], $this->calls);
		$this->assertSame([], $view->getFavoredBy());
		$this->assertSame([['group' => 'h', 'mode' => 'write']], $view->getSharedWith());
	}//end testUpdateGivenFieldsReplace()
}//end class
