<?php

/**
 * ExternalLinkResolverTest — a filled link, and a hidden one.
 *
 * The hiding is the requirement worth guarding hardest. A URL with `{bagId}`
 * still in it renders blue and clickable and fails only once a caseworker
 * clicks it, so the test that matters is the one asserting NOTHING comes back.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExternalLink
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExternalLink;

use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver
 */
class ExternalLinkResolverTest extends TestCase {

	/**
	 * The declaration a gemeente writes for the BAG viewer.
	 *
	 * @return array<int, array<string, mixed>> The declarations.
	 */
	private static function bagDeclaration(): array {
		return [
			[
				'title' => 'Open in the BAG viewer',
				'url' => 'https://bagviewer.kadaster.nl/lvbag/bag-viewer/#?searchQuery={bagId}',
			],
		];
	}//end bagDeclaration()

	public function testACaseOpensTheSameAddressInTheBagViewer(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: self::bagDeclaration(),
			object: ['bagId' => '0518200000123456'],
		);

		$this->assertCount(1, $links);
		$this->assertSame('Open in the BAG viewer', $links[0]['title']);
		$this->assertSame(
			'https://bagviewer.kadaster.nl/lvbag/bag-viewer/#?searchQuery=0518200000123456',
			$links[0]['url']
		);
	}//end testACaseOpensTheSameAddressInTheBagViewer()

	public function testAMissingValueHidesTheLink(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: self::bagDeclaration(),
			object: ['zaaknummer' => 'Z-2026-01'],
		);

		$this->assertSame(
			[],
			$links,
			'A URL with {bagId} still in it renders blue and clickable and fails only once somebody clicks it.'
		);
	}//end testAMissingValueHidesTheLink()

	public function testAnEmptyStringIsNoValue(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: self::bagDeclaration(),
			object: ['bagId' => '   '],
		);

		$this->assertSame([], $links);
	}//end testAnEmptyStringIsNoValue()

	public function testANestedValueFillsAPlaceholder(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [['title' => 'BAG', 'url' => 'https://example.test/{adres.bagId}']],
			object: ['adres' => ['bagId' => '123']],
		);

		$this->assertSame('https://example.test/123', $links[0]['url']);
	}//end testANestedValueFillsAPlaceholder()

	public function testAValueIsUrlEncoded(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [['title' => 'Search', 'url' => 'https://example.test/?q={naam}']],
			object: ['naam' => 'de Vries & Zonen/2026'],
		);

		$this->assertSame(
			'https://example.test/?q=de%20Vries%20%26%20Zonen%2F2026',
			$links[0]['url'],
			'An unencoded ampersand silently truncates the query at the next parameter.'
		);
	}//end testAValueIsUrlEncoded()

	public function testAnArrayValueDoesNotFillAPlaceholder(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [['title' => 'BAG', 'url' => 'https://example.test/{ids}']],
			object: ['ids' => ['a', 'b']],
		);

		$this->assertSame(
			[],
			$links,
			'An array has no single spelling in a URL; picking one would be this class inventing a convention.'
		);
	}//end testAnArrayValueDoesNotFillAPlaceholder()

	public function testAnIntegerAndABooleanDoFillAPlaceholder(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [['title' => 'X', 'url' => 'https://example.test/{n}/{flag}']],
			object: ['n' => 42, 'flag' => true],
		);

		$this->assertSame('https://example.test/42/true', $links[0]['url']);
	}//end testAnIntegerAndABooleanDoFillAPlaceholder()

	public function testTheSamePlaceholderTwiceIsFilledTwice(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [['title' => 'X', 'url' => 'https://example.test/{id}/related/{id}']],
			object: ['id' => 'abc'],
		);

		$this->assertSame('https://example.test/abc/related/abc', $links[0]['url']);
	}//end testTheSamePlaceholderTwiceIsFilledTwice()

	public function testAConditionThatHoldsOffersTheLink(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [
				[
					'title' => 'Open in the financial system',
					'url' => 'https://finance.test/{factuurnummer}',
					'condition' => ['status' => 'gefactureerd'],
				],
			],
			object: ['factuurnummer' => 'F-1', 'status' => 'gefactureerd'],
		);

		$this->assertCount(1, $links);
	}//end testAConditionThatHoldsOffersTheLink()

	public function testAConditionThatDoesNotHoldHidesTheLink(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [
				[
					'title' => 'Open in the financial system',
					'url' => 'https://finance.test/{factuurnummer}',
					'condition' => ['status' => 'gefactureerd'],
				],
			],
			object: ['factuurnummer' => 'F-1', 'status' => 'concept'],
		);

		$this->assertSame([], $links);
	}//end testAConditionThatDoesNotHoldHidesTheLink()

	public function testAConditionNamingAnAbsentPropertyHidesTheLink(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [
				['title' => 'X', 'url' => 'https://example.test/', 'condition' => ['status' => 'open']],
			],
			object: [],
		);

		$this->assertSame([], $links);
	}//end testAConditionNamingAnAbsentPropertyHidesTheLink()

	public function testAConditionMayNameSeveralAcceptedValues(): void {
		$declarations = [
			['title' => 'X', 'url' => 'https://example.test/', 'condition' => ['status' => ['open', 'in behandeling']]],
		];
		$resolver = new ExternalLinkResolver();

		$this->assertCount(1, $resolver->resolve(declarations: $declarations, object: ['status' => 'open']));
		$this->assertCount(1, $resolver->resolve(declarations: $declarations, object: ['status' => 'in behandeling']));
		$this->assertSame([], $resolver->resolve(declarations: $declarations, object: ['status' => 'gesloten']));
	}//end testAConditionMayNameSeveralAcceptedValues()

	public function testEveryConditionEntryMustHold(): void {
		$declarations = [
			[
				'title' => 'X',
				'url' => 'https://example.test/',
				'condition' => ['status' => 'open', 'soort' => 'vergunning'],
			],
		];

		$links = (new ExternalLinkResolver())->resolve(
			declarations: $declarations,
			object: ['status' => 'open', 'soort' => 'melding'],
		);

		$this->assertSame([], $links);
	}//end testEveryConditionEntryMustHold()

	public function testOneHiddenLinkDoesNotHideTheOthers(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [
				['title' => 'BAG', 'url' => 'https://bag.test/{bagId}'],
				['title' => 'GIS', 'url' => 'https://gis.test/{x}/{y}'],
				['title' => 'Finance', 'url' => 'https://finance.test/{zaaknummer}'],
			],
			object: ['bagId' => '1', 'zaaknummer' => 'Z-1'],
		);

		$this->assertSame(['BAG', 'Finance'], array_column($links, 'title'));
	}//end testOneHiddenLinkDoesNotHideTheOthers()

	public function testADeclarationWithNoTitleOrNoUrlIsNotOffered(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [
				['url' => 'https://example.test/'],
				['title' => 'No url'],
				'not an object',
			],
			object: [],
		);

		$this->assertSame([], $links);
	}//end testADeclarationWithNoTitleOrNoUrlIsNotOffered()

	public function testATemplateWithNoPlaceholdersIsOfferedAsWritten(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [['title' => 'Handbook', 'url' => 'https://example.test/handbook']],
			object: [],
		);

		$this->assertSame('https://example.test/handbook', $links[0]['url']);
	}//end testATemplateWithNoPlaceholdersIsOfferedAsWritten()

	public function testTheOptionalDescriptionAndTargetRideAlong(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [
				[
					'title' => 'BAG',
					'url' => 'https://example.test/',
					'description' => 'The address in the national register',
					'target' => '_blank',
				],
			],
			object: [],
		);

		$this->assertSame('The address in the national register', $links[0]['description']);
		$this->assertSame('_blank', $links[0]['target']);
	}//end testTheOptionalDescriptionAndTargetRideAlong()

	public function testAnAbsentDescriptionIsNotPublishedEmpty(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [['title' => 'BAG', 'url' => 'https://example.test/']],
			object: [],
		);

		$this->assertSame(['title', 'url'], array_keys($links[0]));
	}//end testAnAbsentDescriptionIsNotPublishedEmpty()

	public function testAnAbsurdlyDeepPathIsRefusedRatherThanWalked(): void {
		$links = (new ExternalLinkResolver())->resolve(
			declarations: [['title' => 'X', 'url' => 'https://example.test/{a.b.c.d.e.f.g}']],
			object: ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'deep']]]]]]],
		);

		$this->assertSame(
			[],
			$links,
			'An unbounded walk over caller-supplied data is a denial of service with a friendly name.'
		);
	}//end testAnAbsurdlyDeepPathIsRefusedRatherThanWalked()

	public function testPlaceholdersInReadsTheTemplate(): void {
		$this->assertSame(
			['bagId', 'adres.straat'],
			ExternalLinkResolver::placeholdersIn(template: 'https://x.test/{bagId}/{adres.straat}/{bagId}')
		);
		$this->assertSame([], ExternalLinkResolver::placeholdersIn(template: 'https://x.test/plain'));
	}//end testPlaceholdersInReadsTheTemplate()
}//end class
