<?php

/**
 * OpenRegister ViewScopeApplierGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The merged queries, lookups, refusals and log lines of the view scope.
 *
 * @covers \OCA\OpenRegister\Service\Object\ViewScopeApplier
 * @uses   \OCA\OpenRegister\Service\Object\ViewScopeRun
 * @uses   \OCA\OpenRegister\Search\Db\View
 */
final class ViewScopeApplierGoldenTest extends TestCase {
	/**
	 * Stored queries by view id. A missing id cannot be resolved.
	 */
	private const VIEWS = [
		'regs'    => ['registers' => [1, 2]],
		'schemas' => ['schemas' => [3, 3, 4]],
		'both'    => ['registers' => [2], 'schemas' => [4], 'searchTerms' => 'kat'],
		'terms'   => ['searchTerms' => ['hond', 'kat']],
		'text'    => ['searchTerms' => 'kat'],
		'empty'   => [],
		'blanks'  => ['registers' => [], 'schemas' => [], 'searchTerms' => ''],
	];

	/**
	 * Lookups, as `id:rbac:multitenancy`.
	 *
	 * @var array<int, string>
	 */
	private array $lookups = [];

	/**
	 * Log lines, as `level message` plus the context without file and line.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * Merges every caller gets, required or not.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>, 2: array<string, mixed>}>
	 */
	public static function merges(): array {
		return [
			'registers'             => [['_limit' => 5], ['regs'], ['_limit' => 5, '@self' => ['register' => [1, 2]]]],
			'schemas repeat folds'  => [[], ['schemas'], ['@self' => ['schema' => [3, 4]]]],
			'one existing id'       => [['@self' => ['register' => 9]], ['regs'], ['@self' => ['register' => [9, 1, 2]]]],
			'an existing list'      => [['@self' => ['register' => [2, 9]]], ['regs'], ['@self' => ['register' => [2, 9, 1]]]],
			'null is nothing'       => [['@self' => ['schema' => null]], ['schemas'], ['@self' => ['schema' => [3, 4]]]],
			'false is nothing'      => [['@self' => ['schema' => false]], ['schemas'], ['@self' => ['schema' => [3, 4]]]],
			'zero is an id'         => [['@self' => ['schema' => 0]], ['schemas'], ['@self' => ['schema' => [0, 3, 4]]]],
			'everything at once'    => [['_search' => 'vis'], ['both'], ['_search' => 'vis kat', '@self' => ['register' => [2], 'schema' => [4]]]],
			'two views combine'     => [[], ['regs', 'both'], ['@self' => ['register' => [1, 2], 'schema' => [4]], '_search' => 'kat']],
		];
	}//end merges()

	/**
	 * A view merges the same for a caller that needs it and one that does not.
	 *
	 * @param array<string, mixed> $query    The query.
	 * @param array<int, string>   $views    The view ids.
	 * @param array<string, mixed> $expected The merged query.
	 *
	 * @return void
	 */
	#[DataProvider('merges')]
	public function testAViewMerges(array $query, array $views, array $expected): void {
		$this->assertSame($expected, $this->scope($query, $views, false));
		$this->assertSame($expected, $this->scope($query, $views, true));
	}//end testAViewMerges()

	/**
	 * Search terms reach `_search`, for a caller that does not need the view.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, mixed>}>
	 */
	public static function terms(): array {
		return [
			'text alone'      => [[], 'text', ['_search' => 'kat']],
			'a list joins'    => [[], 'terms', ['_search' => 'hond kat']],
			'after a search'  => [['_search' => 'vis'], 'terms', ['_search' => 'vis hond kat']],
			'an empty search' => [['_search' => ''], 'text', ['_search' => 'kat']],
			'not a text'      => [['_search' => 7], 'text', ['_search' => 'kat']],
			'no terms'        => [['_search' => 'vis'], 'empty', ['_search' => 'vis']],
			'blank filters'   => [['a' => 1], 'blanks', ['a' => 1]],
		];
	}//end terms()

	/**
	 * Terms are appended to what the caller searched for.
	 *
	 * @param array<string, mixed> $query    The query.
	 * @param string               $view     The view id.
	 * @param array<string, mixed> $expected The merged query.
	 *
	 * @return void
	 */
	#[DataProvider('terms')]
	public function testTermsMerge(array $query, string $view, array $expected): void {
		$this->assertSame($expected, $this->scope($query, [$view], false));
	}//end testTermsMerge()

	/**
	 * Without views a caller that does not need one keeps its query, in silence.
	 *
	 * @return void
	 */
	public function testNoViewsLeaveTheQueryAlone(): void {
		$this->assertSame(['a' => 1], $this->scope(['a' => 1], [], false));
		$this->assertSame([], $this->lookups);
		$this->assertSame([], $this->logged);
	}//end testNoViewsLeaveTheQueryAlone()

	/**
	 * The lookup carries the checks for a caller that does not need the view.
	 *
	 * @return void
	 */
	public function testTheLookupFollowsTheCaller(): void {
		$this->scope([], ['regs', 7], false);
		$this->assertSame(['regs:1:1', '7:1:1'], $this->lookups);

		$this->lookups = [];
		$this->scope([], ['regs'], true);
		$this->assertSame(['regs:0:0'], $this->lookups);
	}//end testTheLookupFollowsTheCaller()

	/**
	 * What a caller that does not need the view hears, and what it keeps.
	 *
	 * @return void
	 */
	public function testAFailedViewIsLoggedAndSkipped(): void {
		$this->assertSame(
			['a' => 1, '@self' => ['register' => [1, 2]]],
			$this->scope(['a' => 1], ['gone', 'regs'], false)
		);

		$this->assertSame(
			[
				['debug', '[ViewScopeApplier] Applying views to query', ['viewIds' => ['gone', 'regs'], 'originalQuery' => ['a']]],
				['warning', '[ViewScopeApplier] Failed to apply view', ['viewId' => 'gone', 'error' => 'no view gone']],
				['debug', '[ViewScopeApplier] Applied view to query', ['viewId' => 'regs', 'registers' => [1, 2], 'schemas' => [], 'hasSearchTerms' => false]],
			],
			$this->logged
		);
	}//end testAFailedViewIsLoggedAndSkipped()

	/**
	 * The last log line names what the view held.
	 *
	 * @return void
	 */
	public function testAnAppliedViewSaysWhatItHeld(): void {
		$this->scope([], ['both'], false);

		$this->assertSame(
			['debug', '[ViewScopeApplier] Applied view to query', ['viewId' => 'both', 'registers' => [2], 'schemas' => [4], 'hasSearchTerms' => true]],
			$this->logged[1]
		);
	}//end testAnAppliedViewSaysWhatItHeld()

	/**
	 * Refusals of a caller whose only bound is the view.
	 *
	 * @return array<string, array{0: array<int, int|string>, 1: string, 2: array<int, string>}>
	 */
	public static function refusals(): array {
		return [
			'no views'         => [[], 'Refusing a view-scoped search without a view to scope it by.', []],
			'unresolved'       => [['gone'], 'no view gone', ['gone:0:0']],
			'stops at the bad' => [['regs', 'gone', 'schemas'], 'no view gone', ['regs:0:0', 'gone:0:0']],
			'terms alone'      => [['text'], 'View "text" carries no register or schema filter.', ['text:0:0']],
			'nothing'          => [['empty'], 'View "empty" carries no register or schema filter.', ['empty:0:0']],
			'blanks'           => [['blanks'], 'View "blanks" carries no register or schema filter.', ['blanks:0:0']],
			'a null query'     => [['null'], 'View "null" carries no register or schema filter.', ['null:0:0']],
			'a number as id'   => [[7], 'View "7" carries no register or schema filter.', ['7:0:0']],
		];
	}//end refusals()

	/**
	 * Every way of failing to apply the view raises, and is logged first.
	 *
	 * @param array<int, int|string> $views   The view ids.
	 * @param string                 $message The refusal.
	 * @param array<int, string>     $lookups The lookups made.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testARequiredViewFailsClosed(array $views, string $message, array $lookups): void {
		try {
			$this->scope(['a' => 1], $views, true);
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame($message, $e->getMessage());
			$this->assertSame($lookups, $this->lookups);
		}

		if ($views !== []) {
			$this->assertSame(
				['warning', '[ViewScopeApplier] Failed to apply view', ['viewId' => end($views) === 'schemas' ? 'gone' : end($views), 'error' => $message]],
				end($this->logged)
			);
		}
	}//end testARequiredViewFailsClosed()

	/**
	 * An exception that is no Exception is not the applier's to judge.
	 *
	 * @return void
	 */
	public function testAnErrorTravelsForEveryCaller(): void {
		$this->expectException(\TypeError::class);

		$this->scope([], ['type-error'], false);
	}//end testAnErrorTravelsForEveryCaller()

	/**
	 * Run one scope over the recording mapper and logger.
	 *
	 * @param array<string, mixed>   $query    The query.
	 * @param array<int, int|string> $views    The view ids.
	 * @param bool                   $required Whether the view is the caller's only bound.
	 *
	 * @return array<string, mixed> The merged query.
	 */
	private function scope(array $query, array $views, bool $required): array {
		if ($required === true) {
			return $this->applier()->applyRequired($query, $views);
		}

		return $this->applier()->apply($query, $views);
	}//end scope()

	/**
	 * The applier over a mapper and a logger that record what they are told.
	 *
	 * @return ViewScopeApplier The applier.
	 */
	private function applier(): ViewScopeApplier {
		$mapper = $this->createMock(ViewMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function (int|string $id, bool $_rbac = true, bool $_multitenancy = true): View {
				$this->lookups[] = sprintf('%s:%d:%d', $id, $_rbac, $_multitenancy);
				if ($id === 'type-error') {
					throw new \TypeError('bad');
				}

				if ($id === 'gone') {
					throw new RuntimeException('no view gone');
				}

				$view = new View();
				$view->setQuery(self::VIEWS[$id] ?? null);
				return $view;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new ViewScopeApplier($mapper, $logger);
	}//end applier()
}//end class
