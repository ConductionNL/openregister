<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\WritePhaseProbe;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Golden test for WritePhaseProbe: pins counting, stamping, phase marking and the
 * exact flushed log line. The probe's static state is set and restored around
 * every test, so no test depends on the trace flag file existing.
 *
 * The opcache segment depends on the PHP build, so it is matched by shape only.
 *
 * @covers \OCA\OpenRegister\Service\WritePhaseProbe
 */
final class WritePhaseProbeGoldenTest extends TestCase {

	private const LOG = '/tmp/or-write-phases.log';

	private const OPCACHE = '/  \[opcache cached=\S+\/\S+ hits=\S+ misses=\S+ oom_restarts=\S+ hash_restarts=\S+ wasted=-?[\d.]+%\]/';

	/** @var array<string,mixed> Static state before the test */
	private array $saved = [];

	/** @var mixed REQUEST_TIME_FLOAT before the test */
	private mixed $requestTime = null;

	protected function setUp(): void {
		foreach (['enabled', 'last', 'counts', 'armed', 'phases', 'stamps'] as $name) {
			$this->saved[$name] = $this->get($name);
		}

		$this->requestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
		// Armed: no shutdown flush gets registered by these tests.
		$this->set('armed', true);
		$this->set('enabled', true);
		$this->set('last', null);
		$this->set('counts', []);
		$this->set('phases', []);
		$this->set('stamps', []);
	}

	protected function tearDown(): void {
		foreach ($this->saved as $name => $value) {
			$this->set($name, $value);
		}

		if ($this->requestTime === null) {
			unset($_SERVER['REQUEST_TIME_FLOAT']);
			return;
		}

		$_SERVER['REQUEST_TIME_FLOAT'] = $this->requestTime;
	}

	private function get(string $name): mixed {
		return (new ReflectionProperty(WritePhaseProbe::class, $name))->getValue();
	}

	private function set(string $name, mixed $value): void {
		(new ReflectionProperty(WritePhaseProbe::class, $name))->setValue(null, $value);
	}

	private function flushedLine(): ?string {
		clearstatcache();
		$before = (int)@filesize(self::LOG);
		WritePhaseProbe::flush();
		clearstatcache();
		$after = (int)@filesize(self::LOG);
		if ($after === $before) {
			return null;
		}

		$written = (string)file_get_contents(self::LOG, false, null, $before);
		$this->assertStringEndsWith(PHP_EOL, $written);
		return substr($written, 0, -strlen(PHP_EOL));
	}

	private function withoutOpcache(string $line): string {
		return (string)preg_replace(self::OPCACHE, '', $line);
	}

	public function testDisabledProbeRecordsNothing(): void {
		$this->set('enabled', false);
		$this->set('armed', false);
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 1;

		WritePhaseProbe::count('db');
		WritePhaseProbe::stamp('boot');
		WritePhaseProbe::mark('start');
		WritePhaseProbe::mark('validate');

		$this->assertSame([], $this->get('counts'));
		$this->assertSame([], $this->get('stamps'));
		$this->assertSame([], $this->get('phases'));
		$this->assertNull($this->get('last'));
		$this->assertFalse($this->get('armed'));
	}

	public function testUnresolvedFlagFollowsTheFlagFile(): void {
		$this->set('enabled', null);
		WritePhaseProbe::mark('start');
		$this->assertSame(file_exists('/tmp/or-trace-write-phases'), $this->get('enabled'));

		$this->set('enabled', null);
		WritePhaseProbe::count('x');
		$this->assertSame(file_exists('/tmp/or-trace-write-phases'), $this->get('enabled'));

		$this->set('enabled', null);
		WritePhaseProbe::stamp('x');
		$this->assertSame(file_exists('/tmp/or-trace-write-phases'), $this->get('enabled'));
	}

	public function testCountTallies(): void {
		WritePhaseProbe::count('db');
		WritePhaseProbe::count('db');
		WritePhaseProbe::count('cache');

		$this->assertSame(['db' => 2, 'cache' => 1], $this->get('counts'));
	}

	public function testStampRecordsFirstOffsetOnly(): void {
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 2;
		WritePhaseProbe::stamp('boot');
		$first = $this->get('stamps')['boot'];
		$this->assertGreaterThanOrEqual(2000.0, $first);
		$this->assertLessThan(60000.0, $first);

		$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 30;
		WritePhaseProbe::stamp('boot');
		$this->assertSame($first, $this->get('stamps')['boot']);
	}

	public function testStampWithoutRequestTimeIsIgnored(): void {
		unset($_SERVER['REQUEST_TIME_FLOAT']);
		WritePhaseProbe::stamp('boot');
		$_SERVER['REQUEST_TIME_FLOAT'] = 0.0;
		WritePhaseProbe::stamp('boot');

		$this->assertSame([], $this->get('stamps'));
	}

	public function testMarkAccumulatesPhasesAfterTheFirstMark(): void {
		WritePhaseProbe::mark('validate');
		$this->assertSame([], $this->get('phases'));
		$this->assertIsFloat($this->get('last'));

		$this->set('last', microtime(true) - 0.5);
		WritePhaseProbe::mark('validate');
		$this->set('last', microtime(true) - 0.25);
		WritePhaseProbe::mark('validate');
		$this->set('last', microtime(true) - 1);
		WritePhaseProbe::mark('start');

		$phases = $this->get('phases');
		$this->assertSame(['validate'], array_keys($phases));
		$this->assertGreaterThanOrEqual(750.0, $phases['validate']);
		$this->assertLessThan(30000.0, $phases['validate']);
		$this->assertEqualsWithDelta(microtime(true), $this->get('last'), 5.0);
	}

	public function testFlushWritesTheTimelineAndResets(): void {
		unset($_SERVER['REQUEST_TIME_FLOAT']);
		$this->set('phases', ['persist' => 12.4, 'validate' => 30.6, 'render' => 1.5]);
		$this->set('stamps', ['controller' => 80.2, 'boot' => 40.0]);
		$this->set('counts', ['cache' => 1, 'db' => 7, 'event' => 3]);
		$this->set('last', 123.0);

		$line = $this->flushedLine();

		$this->assertNotNull($line);
		$this->assertLessThanOrEqual(1, preg_match_all(self::OPCACHE, $line));
		$this->assertSame(
			'total=44ms  validate=31ms  persist=12ms  render=2ms  [at boot=40ms controller=80ms]  [counts db=7 event=3 cache=1]',
			$this->withoutOpcache($line)
		);
		$this->assertSame([], $this->get('phases'));
		$this->assertSame([], $this->get('counts'));
		$this->assertNull($this->get('last'));
		$this->assertSame(['boot' => 40.0, 'controller' => 80.2], $this->get('stamps'));
	}

	public function testFlushStampsItsOwnOffsetWhenRequestTimeIsKnown(): void {
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 3;
		$this->set('counts', ['db' => 1]);

		$line = $this->withoutOpcache((string)$this->flushedLine());

		$this->assertMatchesRegularExpression('/^total=0ms  \[at flush=\d+ms\]  \[counts db=1\]$/', $line);
		$this->assertGreaterThanOrEqual(3000.0, $this->get('stamps')['flush']);
	}

	public function testFlushWithPhasesOnly(): void {
		unset($_SERVER['REQUEST_TIME_FLOAT']);
		$this->set('phases', ['a' => 1.0]);

		$this->assertSame('total=1ms  a=1ms', $this->withoutOpcache((string)$this->flushedLine()));
	}

	public function testFlushWithStampsOnlyKeepsThemForTheNextFlush(): void {
		unset($_SERVER['REQUEST_TIME_FLOAT']);
		$this->set('stamps', ['boot' => 5.0]);

		$this->assertSame('total=0ms  [at boot=5ms]', $this->withoutOpcache((string)$this->flushedLine()));
		$this->assertSame('total=0ms  [at boot=5ms]', $this->withoutOpcache((string)$this->flushedLine()));
	}

	public function testFlushIsSilentWhenDisabledOrEmpty(): void {
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 1;
		$this->assertNull($this->flushedLine());
		$this->assertSame([], $this->get('stamps'));

		$this->set('counts', ['db' => 1]);
		$this->set('enabled', false);
		$this->assertNull($this->flushedLine());
		$this->set('enabled', null);
		$this->assertNull($this->flushedLine());
		$this->assertSame(['db' => 1], $this->get('counts'));
	}
}
