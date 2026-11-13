<?php

/**
 * Unit test for TranslationSearchRequest and TranslationSearchOptions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\TranslationSearchOptions;
use OCA\OpenRegister\Controller\Support\TranslationSearchRequest;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use PHPUnit\Framework\TestCase;

/**
 * Translation search parameters become a filter, a bounded listing and two flags.
 *
 * @covers \OCA\OpenRegister\Controller\Support\TranslationSearchRequest
 * @covers \OCA\OpenRegister\Controller\Support\TranslationSearchOptions
 * @uses \OCA\OpenRegister\Controller\Support\TranslationFilter
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class TranslationSearchRequestTest extends TestCase {
	public function testFilterFieldsAreReadAndCastToString(): void {
		$request = TranslationSearchRequest::fromParams(
			['language' => 'de', 'status' => 'draft', 'objectUuid' => 'u-1', 'sourceLanguage' => 'nl']
		);

		$this->assertSame('de', $request->filter->language);
		$this->assertSame('draft', $request->filter->status);
		$this->assertSame('u-1', $request->filter->objectUuid);
		$this->assertSame('nl', $request->filter->sourceLanguage);
	}

	public function testNonScalarAndAbsentValuesAreNull(): void {
		$request = TranslationSearchRequest::fromParams(['language' => ['de', 'en']]);

		$this->assertNull($request->filter->language);
		$this->assertNull($request->filter->status);
		$this->assertNull($request->options->listing->search);
	}

	public function testTheLimitDefaultsTo100AndIsClampedBetween1And1000(): void {
		$this->assertSame(100, TranslationSearchRequest::fromParams([])->options->listing->limit);
		$this->assertSame(1, TranslationSearchRequest::fromParams(['limit' => 0])->options->listing->limit);
		$this->assertSame(1000, TranslationSearchRequest::fromParams(['limit' => 5000])->options->listing->limit);
		$this->assertSame(50, TranslationSearchRequest::fromParams(['limit' => '50'])->options->listing->limit);
	}

	public function testTheQueryBecomesTheListingSearch(): void {
		$this->assertSame('welkom', TranslationSearchRequest::fromParams(['query' => 'welkom'])->options->listing->search);
	}

	public function testFlagsAreOnOnlyForTruthyWords(): void {
		foreach (['1', 'true', 'YES', ' on '] as $on) {
			$options = TranslationSearchRequest::fromParams(['isOutOfDate' => $on, 'compareToSource' => $on])->options;
			$this->assertTrue($options->outdatedOnly(), $on);
			$this->assertTrue($options->withSource(), $on);
		}

		foreach (['0', 'false', 'no', '', 'maybe'] as $off) {
			$options = TranslationSearchRequest::fromParams(['isOutOfDate' => $off, 'compareToSource' => $off])->options;
			$this->assertFalse($options->outdatedOnly(), $off);
			$this->assertFalse($options->withSource(), $off);
		}
	}

	public function testAbsentFlagsAreOff(): void {
		$options = new TranslationSearchOptions(listing: new ListQuery(), isOutOfDate: null, compareToSource: null);

		$this->assertFalse($options->outdatedOnly());
		$this->assertFalse($options->withSource());
	}

	public function testABooleanTrueParamCastsToOn(): void {
		$this->assertTrue(TranslationSearchRequest::fromParams(['isOutOfDate' => true])->options->outdatedOnly());
	}
}
