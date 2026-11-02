<?php

/**
 * Tests for ExampleSetValues: every placeholder form, typed whole values and gaps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 */
class ExampleSetValuesTest extends TestCase {
	private ExampleSetValues $values;

	/** @var array<string, mixed> */
	private array $context;

	protected function setUp(): void {
		parent::setUp();
		$this->values  = new ExampleSetValues();
		$this->context = [
			'cast'    => ['sanne' => ['bsn' => '999994001', 'address' => ['number' => 14, 'street' => 'Lindelaan']]],
			'objects' => ['case-1' => ['id' => 'abc', 'identifier' => 'Z-1']],
			'files'   => ['dossier-1' => [20525, 20526]],
			'lookups' => ['caseType' => 'type-uuid'],
			'today'   => new DateTimeImmutable('2026-10-13 12:00', new DateTimeZone('Europe/Amsterdam')),
			'accounts'      => ['background' => ['uid' => 'demo_background']],
			'organisations' => ['zuiddrecht' => 'org-uuid'],
			'instance'      => ['baseUrl' => 'https://cloud.example.test'],
		];
	}

	/**
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public static function filled(): array {
		return [
			'cast field'                => ['{{cast:sanne.bsn}}', '999994001'],
			'nested cast field'         => ['{{cast:sanne.address.street}}', 'Lindelaan'],
			'a whole number keeps type' => ['{{cast:sanne.address.number}}', 14],
			'interpolated number'       => ['Lindelaan {{cast:sanne.address.number}}', 'Lindelaan 14'],
			'date in the past'          => ['{{date:-2}}', '2026-10-11'],
			'date in the future'        => ['{{date:+13}}', '2026-10-26'],
			'datetime'                  => ['{{datetime:-1 09:12}}', '2026-10-12T09:12:00+02:00'],
			'object field'              => ['Zaak {{object:case-1.identifier}}', 'Zaak Z-1'],
			'lookup'                    => ['{{lookup:caseType}}', 'type-uuid'],
			'nested arrays'             => [['a' => ['{{cast:sanne.bsn}}']], ['a' => ['999994001']]],
			'plain values pass'         => [true, true],
			'next thursday'             => ['{{date:+7@thu}}', '2026-10-22'],
			'today is a tuesday'        => ['{{date:0@tue}}', '2026-10-13'],
			'a weekday with a time'     => ['{{datetime:+7@tue 19:30}}', '2026-10-20T19:30:00+02:00'],
			'service account'           => ['{{account:background.uid}}', 'demo_background'],
			'organisation'              => ['{{organisation:zuiddrecht}}', 'org-uuid'],
			'instance'                  => ['{{instance:baseUrl}}/x', 'https://cloud.example.test/x'],
			'a file id keeps its type'  => ['{{file:dossier-1.1}}', 20526],
		];
	}

	/**
	 * @dataProvider filled
	 */
	public function testFillsEveryForm(mixed $declared, mixed $expected): void {
		$gaps = [];
		$this->assertSame($expected, $this->values->fill(value: $declared, context: $this->context, gaps: $gaps));
		$this->assertSame([], $gaps);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unresolved(): array {
		return [
			'unknown cast key'    => ['{{cast:nobody.uid}}'],
			'unknown cast field'  => ['{{cast:sanne.uid}}'],
			'cast key alone'      => ['{{cast:sanne}}'],
			'object of elsewhere' => ['{{object:other.id}}'],
			'unknown lookup'      => ['{{lookup:status}}'],
			'malformed date'      => ['{{date:yesterday}}'],
			'malformed datetime'  => ['{{datetime:-1 25:00}}'],
			'unknown kind'        => ['{{subject}}'],
			'unknown weekday'     => ['{{date:+7@thursday}}'],
			'unknown organisation' => ['{{organisation:nergens}}'],
			'unknown account'     => ['{{account:nobody.uid}}'],
			'file past the last'  => ['{{file:dossier-1.2}}'],
			'file without index'  => ['{{file:dossier-1}}'],
			'file of elsewhere'   => ['{{file:other.0}}'],
		];
	}

	/**
	 * An unresolved placeholder is reported and never becomes an empty string.
	 *
	 * @dataProvider unresolved
	 */
	public function testAnUnresolvedPlaceholderIsAGapNotAnEmptyString(string $placeholder): void {
		$gaps   = [];
		$filled = $this->values->fill(value: ['x' => 'a '.$placeholder], context: $this->context, gaps: $gaps);

		$this->assertSame([$placeholder], $gaps);
		$this->assertSame('a '.$placeholder, $filled['x']);
	}

	public function testAPlannedObjectStandsInForAFieldTheRegisterMakes(): void {
		$context = $this->context;
		$context['objects']['case-2'] = ['id' => 'stand-in', ExampleSetValues::PLANNED => true];
		$gaps    = [];

		$this->assertSame('example-set-stand-in:case-2.identifier', $this->values->fill(value: '{{object:case-2.identifier}}', context: $context, gaps: $gaps));
		$this->assertSame([], $gaps);
	}
}
