<?php

/**
 * Golden characterization test for AnnotationNotificationDispatcher::matches().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Runs matches() over every combination of a trigger corpus, a list of active
 * events and a list of contexts, and compares each answer with
 * `fixtures/dispatcher-matches-golden.json`. Each trigger maps to one string
 * per event (the empty event is keyed `(empty)`), one character per context: T (true), F (false) or E (error).
 * Set `OR_GOLDEN_WRITE=1` to regenerate the fixture.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher
 */
final class AnnotationNotificationDispatcherMatchesGoldenTest extends TestCase {

	private const EVENTS = ['created', 'updated', 'transition', 'calculatedChange', 'deleted', 'booking.confirmed', ''];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/dispatcher-matches-golden.json';
	}//end goldenPath()

	/**
	 * The declared triggers.
	 *
	 * @return array<string, mixed>
	 */
	private static function triggers(): array {
		return [
			'string-app-event' => 'booking.confirmed',
			'string-created' => 'created',
			'string-empty' => '',
			'int' => 7,
			'null' => null,
			'empty-array' => [],
			'event' => ['event' => 'booking.confirmed'],
			'event-empty' => ['event' => '', 'type' => 'created'],
			'event-non-string' => ['event' => 5, 'type' => 'updated'],
			'type-created' => ['type' => 'created'],
			'type-updated' => ['type' => 'updated'],
			'type-transition' => ['type' => 'transition'],
			'type-deleted' => ['type' => 'deleted'],
			'transition-action' => ['type' => 'transition', 'action' => 'close'],
			'transition-action-list' => ['type' => 'transition', 'action' => ['close', 'reopen']],
			'transition-action-int' => ['type' => 'transition', 'action' => 1],
			'transition-action-null' => ['type' => 'transition', 'action' => null],
			'created-filter-equals' => ['type' => 'created', 'filter' => ['field' => 'channel', 'value' => 'telefoon']],
			'created-filter-in' => ['type' => 'created', 'filter' => ['field' => 'channel', 'operator' => 'in', 'values' => ['mail', 'telefoon', ['x']]]],
			'created-filter-notin' => ['type' => 'created', 'filter' => ['field' => 'channel', 'operator' => 'notIn', 'values' => ['mail']]],
			'created-filter-bad-values' => ['type' => 'created', 'filter' => ['field' => 'channel', 'operator' => 'in', 'values' => 'mail']],
			'created-filter-no-field' => ['type' => 'created', 'filter' => ['value' => 'x']],
			'created-filter-string' => ['type' => 'created', 'filter' => 'channel'],
			'created-filter-null' => ['type' => 'created', 'filter' => null],
			'updated-changed' => ['type' => 'updated', 'condition' => ['field' => 'status']],
			'updated-equals' => ['type' => 'updated', 'condition' => ['field' => 'status', 'operator' => 'equals', 'value' => 'closed']],
			'updated-equals-from' => ['type' => 'updated', 'condition' => ['field' => 'status', 'operator' => 'equals', 'value' => 'closed', 'from' => 'open']],
			'updated-unknown-op' => ['type' => 'updated', 'condition' => ['field' => 'status', 'operator' => 'gt']],
			'updated-condition-string' => ['type' => 'updated', 'condition' => 'status'],
			'updated-condition-no-field' => ['type' => 'updated', 'condition' => []],
			'calc-no-field' => ['type' => 'calculatedChange'],
			'calc-field-only' => ['type' => 'calculatedChange', 'field' => 'score'],
			'calc-condition' => ['type' => 'calculatedChange', 'field' => 'score', 'condition' => ['lt' => 0.85]],
			'calc-previously' => ['type' => 'calculatedChange', 'field' => 'score', 'previously' => ['gte' => 0.85]],
			'calc-both' => ['type' => 'calculatedChange', 'field' => 'score', 'condition' => ['lt' => 0.85], 'previously' => ['gte' => 0.85]],
			'calc-eq-ne' => ['type' => 'calculatedChange', 'field' => 'score', 'condition' => ['ne' => 0.5], 'previously' => ['eq' => 0.9]],
			'calc-condition-string' => ['type' => 'calculatedChange', 'field' => 'score', 'condition' => 'lt', 'previously' => 3],
			'mixed-everything' => ['type' => 'created', 'action' => 'close', 'condition' => ['field' => 'status'], 'field' => 'score', 'filter' => ['field' => 'channel', 'value' => 'mail']],
		];
	}//end triggers()

	/**
	 * The per-event contexts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function contexts(): array {
		$old = ['status' => 'open', 'score' => 0.9, 'channel' => 'mail'];
		$new = ['status' => 'closed', 'score' => 0.5, 'channel' => 'telefoon'];
		return [
			[],
			['action' => 'close'],
			['action' => 'reopen', '_data' => $new],
			['action' => 1, '_data' => $old],
			['_data' => 'not-an-array'],
			['_newData' => $new, '_oldData' => $old],
			['_newData' => $old, '_oldData' => $new],
			['_newData' => $new, '_oldData' => $new],
			['_newData' => $new],
			['_newData' => ['status' => ['x'], 'score' => 'high'], '_oldData' => ['status' => null, 'score' => 0.9]],
		];
	}//end contexts()

	/**
	 * Every trigger answers each event and context as recorded.
	 *
	 * @return void
	 *
	 * @covers ::matches
	 */
	public function testMatchesAgreesWithGolden(): void {
		$method = new ReflectionMethod(AnnotationNotificationDispatcher::class, 'matches');
		$dispatcher = (new \ReflectionClass(AnnotationNotificationDispatcher::class))->newInstanceWithoutConstructor();

		$actual = [];
		foreach (self::triggers() as $name => $triggerSpec) {
			foreach (self::EVENTS as $event) {
				$answers = '';
				foreach (self::contexts() as $context) {
					try {
						$answers .= ($method->invoke($dispatcher, $triggerSpec, $event, $context) === true) ? 'T' : 'F';
					} catch (\Throwable $e) {
						$answers .= 'E';
					}
				}

				$actual[$name][($event === '') ? '(empty)' : $event] = $answers;
			}
		}

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			file_put_contents(self::goldenPath(), json_encode($actual, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertSame($golden, $actual);
	}//end testMatchesAgreesWithGolden()
}//end class
