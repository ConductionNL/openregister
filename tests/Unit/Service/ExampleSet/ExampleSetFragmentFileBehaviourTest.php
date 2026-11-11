<?php

/**
 * Behaviour tests for ExampleSetFragmentFile: reading an app's fragment and its
 * part files, and what reading refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentFile;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of reading one app's fragment file and its part files.
 */
class ExampleSetFragmentFileBehaviourTest extends TestCase {

	private string $folder;

	protected function setUp(): void {
		$this->folder = sys_get_temp_dir().'/t12frag-'.getmypid().'-'.bin2hex(random_bytes(4));
		mkdir($this->folder.'/demo.d', 0777, true);
	}//end setUp()

	protected function tearDown(): void {
		foreach ((array) glob($this->folder.'/demo.d/*') as $file) {
			unlink((string) $file);
		}

		foreach ((array) glob($this->folder.'/*.json') as $file) {
			unlink((string) $file);
		}

		rmdir($this->folder.'/demo.d');
		rmdir($this->folder);
	}//end tearDown()

	private function write(string $name, string $json): void {
		file_put_contents($this->folder.'/'.$name, $json);
	}//end write()

	private function file(string $app='alpha'): ExampleSetFragmentFile {
		return new ExampleSetFragmentFile(app: $app, folder: $this->folder);
	}//end file()

	public function testAMissingSetFileIsNoFragment(): void {
		$this->assertNull($this->file()->fragment('demo'));
	}

	public function testTheMainFileIsReadAndNamed(): void {
		$this->write('demo.json', '{"objects":[{"key":"a"}]}');

		$fragment = $this->file()->fragment('demo');

		$this->assertSame([['key' => 'a']], $fragment['objects']);
		$this->assertSame('alpha/lib/Settings/example-sets/demo.json', $fragment['@file']);
		$this->assertSame($this->folder, $fragment['@dir']);
	}

	public function testPartsAppendTheirListsInFileNameOrderAndSkipNotes(): void {
		$this->write('demo.json', '{"objects":[{"key":"a"}],"title":"Demo"}');
		$this->write('demo.d/20-b.json', '{"objects":[{"key":"c"}],"set":"demo","$schema":"x"}');
		$this->write('demo.d/10-a.json', '{"objects":[{"key":"b"}],"tasks":[{"key":"t"}]}');

		$fragment = $this->file()->fragment('demo');

		$this->assertSame(['a', 'b', 'c'], array_column($fragment['objects'], 'key'));
		$this->assertSame([['key' => 't']], $fragment['tasks']);
		$this->assertSame('Demo', $fragment['title']);
		$this->assertArrayNotHasKey('set', $fragment);
		$this->assertArrayNotHasKey('$schema', $fragment);
	}

	public function testAPartThatSetsAScalarIsRefused(): void {
		$this->write('demo.json', '{}');
		$this->write('demo.d/10-a.json', '{"title":"Other"}');

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('alpha/lib/Settings/example-sets/demo.d/10-a.json: a part may only add lists; "title" belongs in the main file.');

		$this->file()->fragment('demo');
	}

	public function testAPartThatSetsAMapIsRefused(): void {
		$this->write('demo.json', '{}');
		$this->write('demo.d/10-a.json', '{"objects":{"a":1}}');

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('"objects" belongs in the main file.');

		$this->file()->fragment('demo');
	}

	public function testInvalidJsonIsRefusedWithTheParserMessage(): void {
		$this->write('demo.json', '{"objects": [');

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('alpha/lib/Settings/example-sets/demo.json is not valid JSON: Syntax error');

		$this->file()->fragment('demo');
	}

	public function testAnotherAppMayNotCarryACast(): void {
		$this->write('demo.json', '{"cast":{}}');

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('alpha/lib/Settings/example-sets/demo.json carries a cast. The cast of a set lives once, in OpenRegister');

		$this->file()->fragment('demo');
	}

	public function testOpenRegisterCarriesTheCast(): void {
		$this->write('demo.json', '{"cast":{"people":[]}}');

		$this->assertSame(['people' => []], $this->file(app: 'openregister')->fragment('demo')['cast']);
	}
}//end class
