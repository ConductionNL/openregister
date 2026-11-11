<?php

/**
 * Golden test for WaitNode: validateConfig() and execute() over every
 * duration and moment shape, empty firings, and both resume mechanisms.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * Nothing here depends on today's date. A moment to wait UNTIL is always an
 * absolute timestamp far in the past or future, and a duration to wait FOR
 * is recorded as its distance from the clock at the time of the call, in
 * whole seconds, never as a date.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\WaitNode
 */
class WaitNodeGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/wait-node-golden.json';

	/**
	 * Absolute moments that never become "now".
	 *
	 * @var array<int, string>
	 */
	private const ABSOLUTE = ['2000-01-01T00:00:00+00:00', '2999-06-15T12:30:00+00:00'];

	/**
	 * @var WaitNode
	 */
	private WaitNode $node;

	/**
	 * @var string
	 */
	private string $timezone;

	protected function setUp(): void {
		// Durations in days and weeks are only whole multiples of 86400 in a
		// zone without daylight saving.
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->node = new WaitNode($l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
	}//end tearDown()

	/**
	 * A moment, as the fixture records it: absolute when it is one of the
	 * known absolutes, otherwise its distance from the clock in seconds.
	 *
	 * @param DateTime|null $moment The moment.
	 * @param int           $before The clock just before the call.
	 * @param int           $after  The clock just after the call.
	 *
	 * @return string The recorded moment.
	 */
	private static function moment(?DateTime $moment, int $before, int $after): string {
		if ($moment === null) {
			return 'null';
		}

		$iso = $moment->format('c');
		if (in_array($iso, self::ABSOLUTE, true) === true) {
			return $iso;
		}

		$from = $moment->getTimestamp() - $after;
		$to = $moment->getTimestamp() - $before;
		if ($from === $to) {
			return 'now+' . $from;
		}

		return 'now+' . $from . '..' . $to;
	}//end moment()

	private static function configs(): array {
		return [
			'empty' => [],
			'blank both' => ['for' => ' ', 'until' => ' '],
			'for seconds digits' => ['for' => '30'],
			'for zero digits' => ['for' => '0'],
			'for padded digits' => ['for' => ' 45 '],
			'for words' => ['for' => '2 hours'],
			'for plus words' => ['for' => '+90 minutes'],
			'for double plus' => ['for' => '++1 day'],
			'for weeks' => ['for' => '1 week'],
			'for decimal' => ['for' => '1.5'],
			'for garbage' => ['for' => 'whenever'],
			'for int' => ['for' => 12],
			'until past' => ['until' => self::ABSOLUTE[0]],
			'until future' => ['until' => self::ABSOLUTE[1]],
			'until garbage' => ['until' => 'not a time'],
			'until wins over for' => ['until' => self::ABSOLUTE[1], 'for' => '30'],
			'garbage until wins over for' => ['until' => 'not a time', 'for' => '30'],
			'blank until falls back to for' => ['until' => '  ', 'for' => '30'],
		];
	}//end configs()

	private static function contexts(): array {
		return [
			'no context' => static fn (): array => [],
			'resuming flag' => static fn (): array => ['resuming' => true],
			'resuming flag false' => static fn (): array => ['resuming' => false],
			'fresh slot' => static fn (): array => [FlowNodeResumeState::CONTEXT_KEY => (new FlowResumeState())->forNode(nodeId: 'w')],
			'fresh slot and flag' => static fn (): array => [
				'resuming' => true,
				FlowNodeResumeState::CONTEXT_KEY => (new FlowResumeState())->forNode(nodeId: 'w'),
			],
			'held slot' => static function (): array {
				$state = new FlowResumeState();
				$state->forNode(nodeId: 'w')->set(key: 'waitingUntil', value: self::ABSOLUTE[0]);
				return [FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'w')];
			},
			'not a slot' => static fn (): array => [FlowNodeResumeState::CONTEXT_KEY => 'nope', 'resuming' => true],
		];
	}//end contexts()

	private function validate(array $config): string {
		try {
			$this->node->validateConfig($config);
		} catch (Throwable $e) {
			return get_class($e) . ': ' . $e->getMessage();
		}

		return 'ok';
	}//end validate()

	/**
	 * Run one case, again with a fresh context whenever the clock ticked
	 * during the call, so a duration is always read against one second.
	 *
	 * @param array    $items   The items.
	 * @param array    $config  The step config.
	 * @param callable $context Builds a fresh run context.
	 *
	 * @return array The outcome.
	 */
	private function runCase(array $items, array $config, callable $context): array {
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$before = time();
			$outcome = $this->runOnce($items, $config, $context());
			if ($before === time()) {
				break;
			}
		}

		return $outcome;
	}//end runCase()

	private function runOnce(array $items, array $config, array $context): array {
		$slot = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		$before = time();
		try {
			$out = $this->node->execute(new FlowNodeCall(items: $items, config: $config, context: $context));
			$outcome = ['returned' => $out];
		} catch (FlowSuspension $e) {
			$after = time();
			$outcome = [
				'suspended' => self::moment($e->getResumeAt(), $before, $after),
				'reason' => str_replace(($e->getResumeAt()?->format('c') ?? '-'), '<resumeAt>', $e->getMessage()),
			];
		} catch (Throwable $e) {
			$outcome = ['error' => get_class($e) . ': ' . $e->getMessage()];
		}

		if ($slot instanceof FlowNodeResumeState) {
			$held = $slot->all();
			if (isset($held['waitingUntil']) === true && in_array($held['waitingUntil'], self::ABSOLUTE, true) === false) {
				$held['waitingUntil'] = '<resumeAt>';
			}

			$outcome['slot'] = $held;
		}

		return $outcome;
	}//end runOnce()

	/**
	 * A negative duration suspends at what the running PHP reads `+-5 minutes` as.
	 *
	 * PHP 8.3.6 reads it as five minutes ahead, 8.3.36 and 8.4 as five minutes back,
	 * so the distance cannot live in the fixture.
	 *
	 * @return void
	 */
	public function testNegativeDurationSuspendsAtWhatThisPhpReads(): void {
		$config = ['for' => '-5 minutes'];
		$distance = strtotime('+-5 minutes', 0);

		$this->assertSame(300, abs((int)$distance));
		$this->assertSame('ok', $this->validate($config));
		$this->assertSame(
			['suspended' => 'now+' . $distance, 'reason' => 'waiting until <resumeAt>'],
			$this->runCase([['json' => ['a' => 1]]], $config, static fn (): array => [])
		);
	}//end testNegativeDurationSuspendsAtWhatThisPhpReads()

	public function testWaitNodeMatchesGolden(): void {
		$actual = [];
		foreach (self::configs() as $name => $config) {
			$actual['validate: ' . $name] = $this->validate($config);
		}

		$items = [['json' => ['a' => 1]], ['json' => ['b' => 2], 'binary' => ['f' => ['data' => 'x']]]];
		foreach (self::configs() as $name => $config) {
			$actual['execute: ' . $name] = $this->runCase($items, $config, static fn (): array => []);
		}

		foreach (self::contexts() as $name => $context) {
			$actual['execute with ' . $name] = $this->runCase($items, ['for' => '30'], $context);
			$actual['execute empty with ' . $name] = $this->runCase([], ['for' => '30'], $context);
			$actual['execute garbage with ' . $name] = $this->runCase($items, ['for' => 'whenever'], $context);
		}

		$actual['configKeys'] = $this->node->configKeys();

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testWaitNodeMatchesGolden()
}//end class
