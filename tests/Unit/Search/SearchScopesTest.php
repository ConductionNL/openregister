<?php

/**
 * What a caller asked the unified search to look in.
 *
 * 🔴 THE TWO WAYS THIS FAILS ARE OPPOSITE, AND BOTH ARE SILENT. A scope that
 * narrows nothing when it should answers rows the reader filtered out and looks
 * like the filter is broken. A scope that narrows everything when it should not
 * answers an EMPTY page to somebody who simply mistyped a chip, and looks
 * exactly like a search that found nothing. So `colour:blue` is reported as
 * unparsed AND leaves the search unscoped, and both halves are asserted.
 *
 * 🔑 TWO CHIPS ARE AN OR, NOT AN AND. A reader who ticks two schemas wants to
 * see more, not less; intersecting them answers an empty page to somebody who
 * asked for both.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Search\SearchScopes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\SearchScopes
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */
final class SearchScopesTest extends TestCase {

	/**
	 * The searchable schemas a narrowing is applied to.
	 *
	 * @var array<int, array{id: int, slug: string, register: string}>
	 */
	private const SCHEMAS = [
		['id' => 1, 'slug' => 'case', 'register' => 'dossiq'],
		['id' => 2, 'slug' => 'contact', 'register' => 'dossiq'],
		['id' => 3, 'slug' => 'invoice', 'register' => 'shillinq'],
	];

	/**
	 * The four shapes, read from a comma-separated string.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testTheFourShapesAreParsed(): void {
		$scopes = SearchScopes::parse('app:dossiq, register:dossiq ,schema:case,files');

		self::assertSame(['dossiq'], $scopes->apps);
		self::assertSame(['dossiq'], $scopes->registers);
		self::assertSame(['case'], $scopes->schemas);
		self::assertTrue($scopes->filesOnly);
		self::assertSame([], $scopes->unparsed);
	}

	/**
	 * A list is accepted as readily as a string.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testAListIsAcceptedToo(): void {
		$scopes = SearchScopes::parse(['schema:case', 'schema:contact']);

		self::assertSame(['case', 'contact'], $scopes->schemas);
	}

	/**
	 * 🔴 A schema scope hides the other schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testASchemaScopeHidesTheOtherSchemas(): void {
		self::assertSame(
			[1],
			SearchScopes::parse('schema:case')->narrowSchemas(self::SCHEMAS)
		);
	}

	/**
	 * A register scope keeps every schema of that register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testARegisterScopeKeepsItsSchemas(): void {
		self::assertSame(
			[1, 2],
			SearchScopes::parse('register:dossiq')->narrowSchemas(self::SCHEMAS)
		);
	}

	/**
	 * 🔴 Two chips are an OR: a reader who ticks both wants to see more.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testTwoChipsAreAnOrRatherThanAnAnd(): void {
		self::assertSame(
			[1, 2, 3],
			SearchScopes::parse('schema:invoice,register:dossiq')->narrowSchemas(self::SCHEMAS),
			'intersecting them answers an empty page to somebody who asked for both'
		);
	}

	/**
	 * 🔴 `files` is a kind, not a place: it narrows no schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testFilesNarrowsNoSchema(): void {
		$scopes = SearchScopes::parse('files');

		self::assertTrue($scopes->filesOnly);
		self::assertSame(
			[1, 2, 3],
			$scopes->narrowSchemas(self::SCHEMAS),
			'`files` says which hits to keep, not where to look'
		);
		self::assertTrue($scopes->narrows(), 'but it IS a narrowing');
	}

	/**
	 * 🔴 An unparseable scope is reported AND leaves the search unscoped.
	 *
	 * Both halves. Reported, so a UI can say which chip it could not honour;
	 * unscoped, because answering an empty page to somebody who mistyped looks
	 * exactly like a search that found nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testAnUnparseableScopeIsReportedAndNarrowsNothing(): void {
		$scopes = SearchScopes::parse('colour:blue, schema:');

		self::assertSame(['colour:blue', 'schema:'], $scopes->unparsed);
		self::assertFalse($scopes->narrows());
		self::assertSame(
			[1, 2, 3],
			$scopes->narrowSchemas(self::SCHEMAS),
			'a mistyped chip must not empty the page'
		);
	}

	/**
	 * Nothing at all is not a scope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testNoScopeNarrowsNothing(): void {
		foreach (['', null, [], '  ,  '] as $raw) {
			$scopes = SearchScopes::parse($raw);
			self::assertFalse($scopes->narrows());
			self::assertSame([1, 2, 3], $scopes->narrowSchemas(self::SCHEMAS));
		}
	}

	/**
	 * A scope naming a schema nothing has keeps nothing, which is correct.
	 *
	 * The caller asked a precise question and the answer is genuinely empty —
	 * unlike the mistyped chip above, which asked no question at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testAScopeNamingNothingKeepsNothing(): void {
		self::assertSame(
			[],
			SearchScopes::parse('schema:nosuchschema')->narrowSchemas(self::SCHEMAS)
		);
	}

	/**
	 * Case and whitespace do not change what a chip means.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testCaseAndWhitespaceDoNotMatter(): void {
		self::assertSame(
			['case'],
			SearchScopes::parse('  SCHEMA:Case  ')->schemas
		);
	}
}//end class
