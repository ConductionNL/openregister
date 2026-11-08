<?php

/**
 * The static entry points outside callers use forward to the class that does the work.
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

use OCA\OpenRegister\Service\MdiIconRenderer;
use OCA\OpenRegister\Service\MdiIconSvg;
use OCA\OpenRegister\Service\WritePhase;
use OCA\OpenRegister\Service\WritePhaseProbe;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\WritePhase
 * @covers \OCA\OpenRegister\Service\MdiIconSvg
 */
final class StaticEntryPointsTest extends TestCase {

	/** @var array<string,mixed> The probe's static state before the test */
	private array $saved = [];

	protected function setUp(): void {
		foreach (['enabled', 'last', 'counts', 'armed', 'phases', 'stamps'] as $name) {
			$this->saved[$name] = $this->probe($name);
		}

		// Armed: no shutdown flush gets registered by these tests.
		$this->setProbe('armed', true);
		$this->setProbe('enabled', true);
		$this->setProbe('last', null);
		$this->setProbe('counts', []);
		$this->setProbe('phases', []);
		$this->setProbe('stamps', []);
	}

	protected function tearDown(): void {
		foreach ($this->saved as $name => $value) {
			$this->setProbe($name, $value);
		}
	}

	private function probe(string $name): mixed {
		return (new ReflectionProperty(WritePhaseProbe::class, $name))->getValue();
	}

	private function setProbe(string $name, mixed $value): void {
		(new ReflectionProperty(WritePhaseProbe::class, $name))->setValue(null, $value);
	}

	public function testWritePhaseRecordsIntoTheProbe(): void {
		WritePhase::count(event: 'db');
		WritePhase::count(event: 'db');
		WritePhase::stamp(label: 'boot');
		WritePhase::mark(phase: 'start');
		WritePhase::mark(phase: 'validate');

		$this->assertSame(['db' => 2], $this->probe('counts'));
		$this->assertArrayHasKey('boot', $this->probe('stamps'));
		$this->assertSame(['validate'], array_keys($this->probe('phases')));
	}

	public function testWritePhaseRecordsNothingWhenTheProbeIsOff(): void {
		$this->setProbe('enabled', false);

		WritePhase::count(event: 'db');
		WritePhase::stamp(label: 'boot');
		WritePhase::mark(phase: 'start');

		$this->assertSame([], $this->probe('counts'));
		$this->assertSame([], $this->probe('stamps'));
	}

	public function testMdiIconSvgRendersWhatTheRendererRenders(): void {
		$this->assertSame(MdiIconRenderer::svg(icon: 'mdi-dog'), MdiIconSvg::of(icon: 'mdi-dog'));
		$this->assertNull(MdiIconSvg::of(icon: null));
		$this->assertNull(MdiIconSvg::of(icon: 'no-such-icon-anywhere'));
	}
}
