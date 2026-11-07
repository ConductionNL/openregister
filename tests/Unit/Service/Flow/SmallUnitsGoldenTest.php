<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Files\Service\File\PlaceholderIdTranslator;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use OCA\OpenRegister\Service\Flow\Timer\CalendarDependency;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for BpmnVocabulary::readingFor(), PlaceholderIdTranslator::rankByFirstAppearance()
 * and CalendarDependency::verdictFor(), over every vocabulary element, several row sets and
 * every timer/calendar combination. Compares with fixtures/small-units-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary
 * @covers \OCA\OpenRegister\Files\Service\File\PlaceholderIdTranslator
 * @covers \OCA\OpenRegister\Service\Flow\Timer\CalendarDependency
 * @uses \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses \OCA\OpenRegister\Db\FlowTimer
 */
class SmallUnitsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/small-units-golden.json';

	public function testUnitsMatchTheRecording(): void {
		$vocabulary = new BpmnVocabulary();
		$elements = array_merge(array_keys(BpmnVocabulary::IMPORT), array_keys(BpmnVocabulary::APPROXIMATED), array_keys(BpmnVocabulary::REFUSED), [BpmnVocabulary::FALLBACK, 'madeUpThing', '']);
		$readings = [];
		foreach ($elements as $element) {
			$readings[$element] = $vocabulary->readingFor(element: $element);
		}

		$rowSets = [
			[],
			[['entity_id' => 3, 'file_id' => 2, 'position_start' => 5], ['entity_id' => 1, 'file_id' => 1, 'position_start' => 9], ['entity_id' => 2, 'file_id' => 1, 'position_start' => 4]],
			[['entity_id' => 4, 'file_id' => 1, 'position_start' => 1], ['entity_id' => 4, 'file_id' => 1, 'position_start' => 7], ['entity_id' => '', 'file_id' => 0], ['file_id' => 1, 'position_start' => 2]],
			[['entity_id' => 9, 'file_id' => 1, 'position_start' => 3], ['entity_id' => 8, 'file_id' => 1, 'position_start' => 3], ['entity_id' => '7', 'file_id' => '1', 'position_start' => '3']],
		];
		$ranks = array_map(fn (array $rows): array => PlaceholderIdTranslator::rankByFirstAppearance(rows: $rows), $rowSets);

		$verdicts = [];
		foreach ([null, '', ' nl ', 'nl', 'org-cal', 'boom'] as $slug) {
			foreach ([null, 'org-1'] as $organisation) {
				foreach (['nl', 'org-cal', ''] as $changed) {
					$verdicts[] = [$slug, $organisation, $changed, $this->dependency()->verdictFor(timer: $this->timer(slug: $slug, organisation: $organisation), changedSlug: $changed)];
				}
			}
		}

		$actual = json_decode((string)json_encode(['readings' => $readings, 'ranks' => $ranks, 'verdicts' => $verdicts]), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}

	private function timer(?string $slug, ?string $organisation): FlowTimer {
		$timer = new FlowTimer();
		$timer->setCalendarSlug($slug);
		$timer->setOrganisation($organisation);
		return $timer;
	}

	private function dependency(): CalendarDependency {
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->method('resolve')->willReturnCallback(function (?string $slug, ?string $organisation) {
			$named = trim((string)$slug);
			if ($named === 'boom') {
				throw new RuntimeException('no such calendar');
			}

			$resolved = $named;
			if ($resolved === '') {
				$resolved = $organisation !== null ? 'org-cal' : 'nl';
			}

			return WorkingCalendar::fromArray(definition: ['slug' => $resolved, 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8, 'rules' => []]);
		});
		return new CalendarDependency(calendars: $calendars);
	}
}
