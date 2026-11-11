<?php

/**
 * Golden test for FlowValueTemplate: render() and renderTracked() over
 * scalars, whole-value and embedded placeholders, nested lists and maps,
 * and every way a path can come out empty.
 * Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 */
class FlowValueTemplateGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/flow-value-template-golden.json';

	/**
	 * The record every value is rendered against.
	 *
	 * @var array<string, mixed>
	 */
	private const JSON = [
		'repo' => 'openregister',
		'n' => 3,
		'f' => 1.5,
		'yes' => true,
		'no' => false,
		'zero' => 0,
		'nil' => null,
		'blank' => '',
		'list' => [1, 'two'],
		'map' => ['a' => ['b' => 'deep'], 'empty' => []],
		'@id' => 'at-id',
		'under_score' => 'u',
		'0' => 'zero-key',
	];

	private static function values(): array {
		return [
			'null' => null,
			'int' => 7,
			'bool' => true,
			'plain string' => 'no placeholders',
			'empty string' => '',
			'whole string' => '{{repo}}',
			'whole spaced' => '{{  repo  }}',
			'whole int keeps type' => '{{n}}',
			'whole float' => '{{f}}',
			'whole true' => '{{yes}}',
			'whole false' => '{{no}}',
			'whole zero' => '{{zero}}',
			'whole null' => '{{nil}}',
			'whole blank' => '{{blank}}',
			'whole missing' => '{{missing}}',
			'whole list' => '{{list}}',
			'whole nested' => '{{map.a.b}}',
			'whole nested missing' => '{{map.a.c}}',
			'whole through scalar' => '{{repo.x}}',
			'whole empty map' => '{{map.empty}}',
			'whole at key' => '{{@id}}',
			'whole numeric key' => '{{0}}',
			'whole list index' => '{{list.1}}',
			'embedded' => 'repo={{repo}} n={{ n }}',
			'embedded bools' => '{{yes}}/{{no}}/{{zero}}',
			'embedded empties' => 'a{{nil}}b{{blank}}c{{missing}}d{{missing}}',
			'embedded array' => 'list={{list}} map={{map}}',
			'embedded float' => 'f={{f}}',
			'two wholes are embedded' => '{{repo}}{{n}}',
			'bad placeholder chars' => '{{re-po}} {{ }} {repo}',
			'unterminated' => '{{repo',
			'list of values' => ['{{repo}}', 'x{{missing}}', 5, null, ['{{nil}}', '{{n}}']],
			'map of values' => ['k' => '{{map.a}}', 'm' => ['deep' => 'v={{map.a.b}}', 'gone' => '{{nope}}'], 'repeat' => '{{nope}}'],
			'empty list' => [],
		];
	}//end values()

	public function testFlowValueTemplateMatchesGolden(): void {
		$actual = [];
		foreach (self::values() as $name => $value) {
			$actual['render: ' . $name] = FlowValueTemplate::render($value, self::JSON);
			$actual['renderTracked: ' . $name] = FlowValueTemplate::renderTracked($value, self::JSON);
		}

		$actual['render: against empty record'] = FlowValueTemplate::render(['a' => '{{repo}}', 'b' => 'x{{repo}}'], []);
		$actual['renderTracked: against empty record'] = FlowValueTemplate::renderTracked(['a' => '{{repo}}', 'b' => 'x{{repo}}'], []);

		// JSON cannot tell 1.0 from 1, nor keep an int-keyed map's keys
		// apart from a list's; var_export can.
		$actual = array_map(static fn ($value): string => var_export($value, true), $actual);

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
	}//end testFlowValueTemplateMatchesGolden()
}//end class
