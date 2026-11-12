<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/**
 * Golden test for SeedReferenceIndex.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\SeedReferenceIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins collectFromObjects(), isEmpty(), isReferenced(), record() and replaceInObjects().
 *
 * Each case is a list of seed objects, the targets recorded and the `@self`
 * blocks asked about. Every answer, the replaced objects and every log entry
 * (without its file and line) are compared with
 * fixtures/seed-reference-index-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\SeedReferenceIndex
 */
class SeedReferenceIndexGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/seed-reference-index-golden.json';

	/** @var list<array<int, mixed>> Every log entry, in order. */
	private array $logs = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$objects = [
			['title' => 'Case', 'zaaktype' => '@ref:zt-1', 'besluit' => '@ref:besluittype:bt-1', 'nested' => ['deep' => ['@ref:doc', 'plain', 5, null, '@ref:']], '@self' => ['slug' => 'case-1', 'schema' => 'zaak']],
			['title' => 'Second', 'link' => '@ref::zt-1', 'ambiguous' => '@ref:dup', 'missing' => '@ref:nope', 'missingQualified' => '@ref:zaak:nope', '@self' => null],
			['title' => 'Third', 'colons' => '@ref:a:b:c', 'notToken' => 'x@ref:zt-1', 'upper' => '@REF:zt-1', 'list' => ['@ref:zt-1', '@ref:besluittype:bt-1']],
			'not an array',
			['@self' => ['slug' => 'self-ref', 'note' => '@ref:zt-1'], 'value' => '@ref:self-ref'],
		];
		$targets = [
			['slug' => 'zt-1', 'id' => 'uuid-zt-1', 'schema' => 'zaaktype'],
			['slug' => 'bt-1', 'id' => 'uuid-bt-1', 'schema' => 'besluittype'],
			['slug' => 'doc', 'id' => 'uuid-doc'],
			['slug' => 'dup', 'id' => 'uuid-dup-a', 'schema' => 'a'],
			['slug' => 'dup', 'id' => 'uuid-dup-b', 'schema' => 'b'],
			['slug' => 'same', 'id' => 'uuid-same', 'schema' => 'a'],
			['slug' => 'same', 'id' => 'uuid-same', 'schema' => 'b'],
			['slug' => 'c', 'id' => 'uuid-abc', 'schema' => 'a:b'],
			['slug' => 'self-ref', 'id' => 'uuid-self', 'schema' => ''],
		];
		$asked = [
			['slug' => 'zt-1'],
			['slug' => 'zt-1', 'schema' => 'other'],
			['slug' => 'bt-1', 'schema' => 'besluittype'],
			['slug' => 'bt-1'],
			['slug' => ''],
			['schema' => 'zaak'],
			null,
			'string self',
			['slug' => 'dup', 'schema' => 'x'],
			['slug' => 'c', 'schema' => 'a:b'],
			['slug' => 'nope', 'schema' => 'zaak'],
			['slug' => 'self-ref', 'schema' => ''],
		];
		return [
			'nothing referenced' => [['objects' => [['title' => 'plain', 'n' => [1, 2]], 'x'], 'targets' => [], 'asked' => $asked]],
			'empty list' => [['objects' => [], 'targets' => $targets, 'asked' => $asked]],
			'full' => [['objects' => $objects, 'targets' => $targets, 'asked' => $asked]],
			'nothing recorded' => [['objects' => $objects, 'targets' => [], 'asked' => $asked]],
			'only qualified refs' => [['objects' => [['a' => '@ref:s:x'], ['b' => ['@ref:t:y']]], 'targets' => [['slug' => 'x', 'id' => 'ux', 'schema' => 's']], 'asked' => [['slug' => 'x', 'schema' => 's'], ['slug' => 'x']]]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testIndexMatchesGolden(array $case): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->logs[] = ['warning', $message, $context];
			}
		);
		$index = new SeedReferenceIndex($logger);
		$index->collectFromObjects($case['objects']);
		$actual = ['isEmpty' => $index->isEmpty(), 'referencedBefore' => [], 'referencedAfter' => []];
		foreach ($case['asked'] as $self) {
			$actual['referencedBefore'][] = $index->isReferenced($self);
		}

		foreach ($case['targets'] as $target) {
			$index->record($target);
		}

		foreach ($case['asked'] as $self) {
			$actual['referencedAfter'][] = $index->isReferenced($self);
		}

		$actual['replaced'] = $index->replaceInObjects($case['objects']);
		$actual['logs'] = $this->logs;
		$actual = json_decode(json_encode($actual), true);

		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testIndexMatchesGolden()
}//end class
