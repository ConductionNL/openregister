<?php

/**
 * Golden test for ExampleSetValues: what fill(), ownerOf(), fillObject(),
 * viewsOf(), tasksOf() and flowsOf() answer, and the gaps fill() names, for a
 * fixed table of declared values and contexts that reaches every placeholder
 * kind. Re-record with UPDATE_GOLDEN=1.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 */
class ExampleSetValuesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/example-set-values-golden.json';

	public function testValuesMatchGolden(): void {
		$values = new ExampleSetValues();
		$actual = [];
		foreach ($this->fills() as $name => $declared) {
			$gaps          = ['earlier'];
			$filled        = $values->fill($declared, $this->context(), $gaps);
			$actual[$name] = ['filled' => $filled, 'gaps' => $gaps];
		}

		$actual['no today'] = $values->fill(['{{date:+1}}', 'a {{datetime:0 09:00}}'], ['cast' => []], $gaps);
		foreach ($this->owners() as $name => $object) {
			$actual['owner '.$name] = $values->ownerOf($object, $this->context());
		}

		$gaps = [];
		$actual['fillObject'] = [
			$values->fillObject(
				['data' => ['a' => '{{cast:anna.uid}}', 'tpl' => 'Hi {{zaakNummer}}', 'b' => '{{cast:x.y}}'], 'verbatimFields' => ['tpl', 5]],
				$this->context(),
				$gaps
			),
			$gaps,
			$values->fillObject([], $this->context(), $gaps),
		];
		foreach ([null, $this->record()] as $index => $record) {
			$actual['fragment rows '.$index] = [
				$values->viewsOf($this->fragment(), $record),
				$values->tasksOf($this->fragment(), $record),
				$values->flowsOf($this->fragment(), $record),
				$values->viewsOf([], $record),
				$values->flowsOf(['app' => 'a'], $record),
			];
		}

		$this->assertGolden($actual);
	}

	private function assertGolden(array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame(json_encode($outcome), json_encode($actual[$name]), $name);
		}
	}

	/**
	 * A fixed context: no dependency on the day the test runs.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		return [
			'cast'          => [
				'anna' => ['uid' => 'anna', 'age' => 41, 'admin' => true, 'off' => false, 'tags' => ['a'], 'none' => '', 'float' => 1.5],
				'bad'  => 'text',
			],
			'objects'       => [
				'case' => ['id' => 'c1', 'title' => 'Case', 'nested' => ['deep' => 'D']],
				'plan' => ['id' => 'p', ExampleSetValues::PLANNED => true],
			],
			'files'         => ['case' => [11, 0, 'x', 13]],
			'views'         => ['inbox' => ['uuid' => 'v-1']],
			'tasks'         => ['call' => ['uuid' => 't-1']],
			'flows'         => ['self' => ['uuid' => 'f-self'], 'intake' => ['uuid' => 'f-1', 'runs' => ['r1' => 'run-1']]],
			'accessLinks'   => ['link' => ['url' => 'https://example.test/s/x']],
			'lookups'       => ['zaaktype' => 'zt-1', 'empty' => ''],
			'accounts'      => ['bot' => ['uid' => 'bot-1']],
			'organisations' => ['gemeente' => 'org-1'],
			'instance'      => ['baseUrl' => 'https://nc.test'],
			'registers'     => ['zaken' => 7],
			'schemas'       => ['zaken/zaak' => 9],
			'today'         => new DateTimeImmutable('2026-03-04', new DateTimeZone('Europe/Amsterdam')),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fills(): array {
		return [
			'scalar'            => 5,
			'plain text'        => 'no placeholder',
			'null'              => null,
			'cast whole'        => '{{cast:anna.uid}}',
			'cast spaced'       => '{{ cast: anna.uid }}',
			'cast int'          => '{{cast:anna.age}}',
			'cast list'         => '{{cast:anna.tags}}',
			'cast empty'        => '{{cast:anna.none}}',
			'cast short ref'    => '{{cast:anna}}',
			'cast missing'      => '{{cast:bob.uid}}',
			'cast through text' => '{{cast:bad.uid}}',
			'object field'      => '{{object:case.nested.deep}}',
			'object planned'    => '{{object:plan.number}}',
			'object planned key' => '{{object:plan}}',
			'object missing'    => '{{object:nope.id}}',
			'file'              => [
				'{{file:case.0}}', '{{file:case.1}}', '{{file:case.2}}', '{{file:case.3}}',
				'{{file:case.9}}', '{{file:case.x}}', '{{file:case}}', '{{file:case.-1}}',
			],
			'view task'         => ['{{view:inbox.uuid}}', '{{task:call.uuid}}'],
			'flow'              => ['{{flow:self}}', '{{flow:intake.runs.r1}}', '{{flow:intake.uuid}}', '{{flow:self.uuid}}'],
			'access link'       => '{{accessLink:link.url}}',
			'lookup'            => ['{{lookup:zaaktype}}', '{{lookup:empty}}', '{{lookup:none}}'],
			'account'           => '{{account:bot.uid}}',
			'organisation'      => ['{{organisation:gemeente}}', '{{organisation:x}}'],
			'instance'          => ['{{instance:baseUrl}}', '{{instance:other}}'],
			'register schema'   => ['{{register:zaken}}', '{{schema:zaken/zaak}}', '{{schema:zaken}}'],
			'dates'             => [
				'{{date:0}}', '{{date:+3}}', '{{date:-10}}', '{{date:2@mon}}',
				'{{date:0@wed}}', '{{date:0@sun}}', '{{date:x}}', '{{date:12345}}',
			],
			'datetimes'         => ['{{datetime:0 09:12}}', '{{datetime:+1@fri 23:59}}', '{{datetime:0 24:00}}', '{{datetime:0}}', '{{datetime:-200 00:00}}'],
			'unknown kind'      => ['{{nope:x.y}}', '{{plain}}'],
			'in text'           => 'Hi {{cast:anna.uid}}, age {{cast:anna.age}}, admin {{cast:anna.admin}} off {{cast:anna.off}} f {{cast:anna.float}}',
			'in text gaps'      => 'x {{cast:anna.tags}} y {{cast:nobody.uid}} z {{date:+1}}',
			'trailing newline'  => "{{cast:nobody.uid}}\n",
			'nested'            => ['a' => ['b' => '{{cast:anna.uid}}', 3 => '{{bad:x}}'], 'c' => [true, 1.5, null]],
			'broken braces'     => ['{{cast:anna.uid', '{cast:anna.uid}', '{{}}', '{{ {{cast:anna.uid}} }}'],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function owners(): array {
		return [
			'absent'      => ['data' => []],
			'cast'        => ['owner' => '{{cast:anna.uid}}'],
			'literal'     => ['owner' => 'anna'],
			'stranger'    => ['owner' => 'zed'],
			'gap'         => ['owner' => '{{cast:nobody.uid}}'],
			'not text'    => ['owner' => '{{cast:anna.age}}'],
			'null'        => ['owner' => null],
		];
	}

	private function fragment(): array {
		return [
			'app'   => 'zaak',
			'views' => [['key' => 'inbox'], ['key' => 'gone']],
			'tasks' => [['key' => 'call'], ['key' => 7]],
			'flows' => [
				[
					'key'  => 'intake',
					'data' => [
						'runs' => [
							['key' => 'r1', 'status' => 'suspended', 'node' => 'wait'],
							['key' => 'r2'],
							['status' => 'suspended'],
							['key' => 5],
							['key' => 'r3', 'status' => 'suspended'],
						],
					],
				],
				['key' => 'plain'],
				['key' => 'objrun', 'data' => ['runs' => (object) ['a' => (object) ['key' => 'r9', 'status' => 'done']]]],
			],
		];
	}

	private function record(): array {
		return [
			'views' => ['zaak/inbox' => ['uuid' => 'v-1'], 'zaak/gone' => 'not a row'],
			'tasks' => ['zaak/call' => ['uuid' => 't-1'], 'zaak/7' => ['uuid' => 't-7']],
			'flows' => ['zaak/intake' => ['uuid' => 'f-1']],
		];
	}
}
