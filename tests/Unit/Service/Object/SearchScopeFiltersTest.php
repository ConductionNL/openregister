<?php

/**
 * How a search's register and schema scope become id filters: through the
 * resolver when there is one, otherwise read as numeric ids, with a list
 * read item by item and an unreadable reference refused by kind.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Service\Object\SearchReferenceResolver;
use OCA\OpenRegister\Service\Object\SearchScopeFilters;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see SearchScopeFilters}.
 */
class SearchScopeFiltersTest extends TestCase {

	/**
	 * With a resolver, each reference is resolved by its own kind; a null
	 * reference adds no filter.
	 *
	 * @return void
	 */
	public function testTheResolverResolvesEachReferenceByKind(): void {
		$resolver = $this->createMock(SearchReferenceResolver::class);
		$resolver->expects($this->once())->method('register')->with('cases')->willReturn(4);
		$resolver->expects($this->once())->method('schema')->with(['case', 'task'])->willReturn([9, 10]);

		$filters = new SearchScopeFilters(resolver: $resolver);

		$this->assertSame(['register' => 4, 'schema' => [9, 10]], $filters->forScope(register: 'cases', schema: ['case', 'task']));
	}//end testTheResolverResolvesEachReferenceByKind()

	/**
	 * A resolver answering null for a reference adds no filter for it.
	 *
	 * @return void
	 */
	public function testAnUnresolvedReferenceAddsNoFilter(): void {
		$resolver = $this->createMock(SearchReferenceResolver::class);
		$resolver->method('register')->willReturn(null);
		$resolver->expects($this->never())->method('schema');

		$this->assertSame([], (new SearchScopeFilters(resolver: $resolver))->forScope(register: 'gone', schema: null));
	}//end testAnUnresolvedReferenceAddsNoFilter()

	/**
	 * Without a resolver, numeric references read as ids, blanks add nothing,
	 * and a list keeps only its readable, non-blank items.
	 *
	 * @return void
	 */
	public function testWithoutAResolverNumericReferencesReadAsIds(): void {
		$filters = new SearchScopeFilters(resolver: null);

		$this->assertSame(['register' => 4, 'schema' => 9], $filters->forScope(register: ' 4 ', schema: 9));
		$this->assertSame([], $filters->forScope(register: ' ', schema: null));
		$this->assertSame(['schema' => [9, 11]], $filters->forScope(register: null, schema: ['9', '', 11, ['nested'], null]));
	}//end testWithoutAResolverNumericReferencesReadAsIds()

	/**
	 * Without a resolver, a slug register is refused as a missing register.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsRefusedAsARegister(): void {
		$this->expectException(RegisterNotFoundException::class);
		(new SearchScopeFilters(resolver: null))->forScope(register: 'cases', schema: 9);
	}//end testAnUnreadableRegisterIsRefusedAsARegister()

	/**
	 * Without a resolver, a slug in a schema list is refused as a missing schema.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaIsRefusedAsASchema(): void {
		$this->expectException(SchemaNotFoundException::class);
		(new SearchScopeFilters(resolver: null))->forScope(register: 4, schema: ['9', 'case']);
	}//end testAnUnreadableSchemaIsRefusedAsASchema()
}//end class
