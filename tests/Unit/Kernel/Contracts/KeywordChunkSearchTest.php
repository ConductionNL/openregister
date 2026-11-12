<?php

/**
 * A keyword chunk search reads its source type from the filters and never caps rows below one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts;

use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch
 */
class KeywordChunkSearchTest extends TestCase {

	public function testSourceTypeComesFromTheFiltersAsGiven(): void {
		$search = new KeywordChunkSearch(query: 'permit', limit: 10, filters: ['source_type' => ['file', 'object']]);

		$this->assertSame(['file', 'object'], $search->sourceType());
	}//end testSourceTypeComesFromTheFiltersAsGiven()

	public function testSourceTypeIsNullWithoutThatFilter(): void {
		$this->assertNull((new KeywordChunkSearch(query: 'permit', limit: 10))->sourceType());
		$this->assertNull((new KeywordChunkSearch(query: 'permit', limit: 10, filters: ['sourceType' => 'file']))->sourceType());
	}//end testSourceTypeIsNullWithoutThatFilter()

	public function testRowCapIsTheLimitButNeverBelowOne(): void {
		$this->assertSame(7, (new KeywordChunkSearch(query: 'q', limit: 7))->rowCap());
		$this->assertSame(1, (new KeywordChunkSearch(query: 'q', limit: 1))->rowCap());
		$this->assertSame(1, (new KeywordChunkSearch(query: 'q', limit: 0))->rowCap());
		$this->assertSame(1, (new KeywordChunkSearch(query: 'q', limit: -3))->rowCap());
	}//end testRowCapIsTheLimitButNeverBelowOne()

	public function testTheUnrankedFallbackIsOffUnlessAsked(): void {
		$this->assertFalse((new KeywordChunkSearch(query: 'q', limit: 5))->allowUnrankedFallback);
		$this->assertTrue((new KeywordChunkSearch(query: 'q', limit: 5, allowUnrankedFallback: true))->allowUnrankedFallback);
	}//end testTheUnrankedFallbackIsOffUnlessAsked()
}//end class
