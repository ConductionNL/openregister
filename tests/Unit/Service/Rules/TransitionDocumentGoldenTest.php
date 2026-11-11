<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Rules\TransitionDocument}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use OCA\OpenRegister\Service\Rules\RuleAttachment;
use OCA\OpenRegister\Service\Rules\TransitionDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case runs readsPrior(), refusalFor() over every trigger and
 * declaration, and decidedBy() over a set of documents, for one condition;
 * build() is pinned separately. The answers are compared with
 * fixtures/transition-document-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class TransitionDocumentGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/transition-document-golden.json';

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function cases(): array {
		return [
			'null' => [null],
			'number' => [5],
			'plain string' => ['status'],
			'before string' => ['$before'],
			'before path' => ['$before.status'],
			'before prefix only' => ['$beforeX'],
			'after path' => ['$after.status'],
			'var after' => [['==' => [['var' => 'status'], 'open']]],
			'var before' => [['==' => [['var' => '$before.status'], 'open']]],
			'before as key' => [['$before' => 1]],
			'deep before' => [['and' => [['==' => [1, 1]], ['!' => [['var' => ['$before.x', null]]]]]]],
			'empty' => [[]],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testMatchesGolden(mixed $node): void {
		$transition = new TransitionDocument();
		$refusals = [];
		foreach (['declared' => ['requiresPrior' => true], 'undeclared' => [], 'declared truthy' => ['requiresPrior' => 1]] as $label => $rule) {
			foreach (['create', 'onCreate', 'beforeCreate', 'afterCreate', 'update', 'Create', null] as $trigger) {
				$refusals[$label . ' ' . json_encode($trigger)] = $transition->refusalFor(attachment: new RuleAttachment(ruleName: 'r1', declaration: $rule, trigger: $trigger), node: $node);
			}
		}

		$documents = [
			'no before' => ['status' => 'a', '$after' => ['status' => 'a']],
			'same' => ['$before' => ['a' => 1, 'b' => 2], '$after' => ['a' => 1, 'b' => 2]],
			'reordered' => ['$before' => ['b' => 2, 'a' => 1], '$after' => ['a' => 1, 'b' => 2]],
			'changed' => ['$before' => ['a' => 1], '$after' => ['a' => 2]],
			'before null' => ['$before' => null, '$after' => []],
			'no after' => ['$before' => []],
			'scalar sides' => ['$before' => 'x', '$after' => 'x'],
		];
		$decided = [];
		foreach ($documents as $label => $document) {
			$decided[$label] = $transition->decidedBy($node, $document);
		}

		$this->assertGolden(['readsPrior' => $transition->readsPrior($node), 'refusalFor' => $refusals, 'decidedBy' => $decided]);
	}//end testMatchesGolden()

	public function testBuild(): void {
		$transition = new TransitionDocument();
		$this->assertSame(['s' => 1, '$after' => ['s' => 1]], $transition->build(['s' => 1], null));
		$this->assertSame(['s' => 1, '$after' => ['s' => 1], '$before' => []], $transition->build(['s' => 1], []));
		$this->assertSame(['s' => 2, '$after' => ['s' => 2], '$before' => ['s' => 1]], $transition->build(['s' => 2], ['s' => 1]));
		$this->assertSame(['$after' => ['$after' => 'x'], '$before' => ['q' => 1]], $transition->build(['$after' => 'x'], ['q' => 1]));
	}//end testBuild()

	/**
	 * @param array<string, mixed> $actual
	 */
	private function assertGolden(array $actual): void {
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
	}//end assertGolden()
}//end class
