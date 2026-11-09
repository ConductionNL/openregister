<?php

/**
 * Characterisation test pinning the exact subline, link and icon output of
 * ObjectSearchResultFormatter, so the internal helpers can be reshaped
 * without changing what a search hit shows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of ObjectSearchResultFormatter::format().
 *
 * @covers \OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter
 */
class ObjectSearchResultFormatterGoldenTest extends TestCase {

	/**
	 * Mock deep link registry.
	 *
	 * @var DeepLinkRegistryService&MockObject
	 */
	private DeepLinkRegistryService $deepLinkRegistry;

	/**
	 * Mock schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * Calls the URL generator received for the OpenRegister object route.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $routeCalls = [];

	/**
	 * Build the formatter with a recording URL generator.
	 *
	 * @return ObjectSearchResultFormatter
	 */
	private function formatter(): ObjectSearchResultFormatter {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(function (string $route, array $params): string {
			$this->routeCalls[] = $params;
			return '/route/' . $route;
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => $text);

		$preview = new ObjectPreviewFormatter(
			$urlGenerator,
			$l10n,
			new ObjectPreviewSources(
				objectService: $this->createMock(ObjectService::class),
				deepLinkRegistry: $this->deepLinkRegistry,
				schemaMapper: $this->schemaMapper,
				registerMapper: $this->createMock(RegisterMapper::class)
			),
			$this->createMock(LoggerInterface::class)
		);

		return new ObjectSearchResultFormatter($urlGenerator, $this->deepLinkRegistry, $preview);
	}//end formatter()

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('none'));
	}//end setUp()

	/**
	 * The excerpt window is 60 characters each side, ellipsised on both
	 * edges, matched case-insensitively and left verbatim.
	 *
	 * @return void
	 */
	public function testExcerptWindowIsExactAndCaseInsensitive(): void {
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn('Dossiq');
		$value = str_repeat('a', 70) . 'JaNsEn' . str_repeat('b', 70);

		$entry = $this->formatter()->format(['notes' => $value, 'id' => 'u1'], 'jansen')->jsonSerialize();

		$this->assertSame('Dossiq — …' . str_repeat('a', 60) . 'JaNsEn' . str_repeat('b', 60) . '…', $entry['subline']);
	}//end testExcerptWindowIsExactAndCaseInsensitive()

	/**
	 * A match near the start is not ellipsised at the front; the end is.
	 *
	 * @return void
	 */
	public function testExcerptNearStartHasNoLeadingEllipsis(): void {
		$value = 'xx term ' . str_repeat('z', 100);

		$entry = $this->formatter()->format(['notes' => $value], 'term')->jsonSerialize();

		$this->assertSame('Open Register — xx term ' . str_repeat('z', 59) . '…', $entry['subline']);
	}//end testExcerptNearStartHasNoLeadingEllipsis()

	/**
	 * With no string match and no summary, a long description is cut at 100.
	 *
	 * @return void
	 */
	public function testDescriptionFallbackIsTruncatedAtOneHundred(): void {
		$entry = $this->formatter()->format(['description' => str_repeat('d', 120), 'n' => 5], 'nomatch')->jsonSerialize();

		$this->assertSame('Open Register — ' . str_repeat('d', 100) . '…', $entry['subline']);
	}//end testDescriptionFallbackIsTruncatedAtOneHundred()

	/**
	 * A null term (filter-only browse) uses the summary; no summary or
	 * description leaves the bare owner label.
	 *
	 * @return void
	 */
	public function testNullTermUsesSummaryAndBareOwnerOtherwise(): void {
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn('');
		$formatter = $this->formatter();

		$this->assertSame('Open Register — Sum', $formatter->format(['summary' => 'Sum', 'x' => 'Sum'], null)->jsonSerialize()['subline']);
		$this->assertSame('Open Register', $formatter->format(['x' => 'y'], null)->jsonSerialize()['subline']);
	}//end testNullTermUsesSummaryAndBareOwnerOtherwise()

	/**
	 * Without `@self` the register, schema and id come from the top level,
	 * reach the deep-link registry, and the fallback route gets them too.
	 *
	 * @return void
	 */
	public function testTopLevelIdsFeedLinkIconAndRoute(): void {
		$this->deepLinkRegistry->expects($this->once())->method('resolveUrl')
			->with(7, 9, ['title' => 'T', 'id' => 'u7', 'register' => 7, 'schema' => 9, 'uuid' => 'u7'])
			->willReturn('/apps/x/{missing}');
		$this->deepLinkRegistry->expects($this->once())->method('resolveIcon')->with(7, 9)->willReturn('icon-x');
		$this->deepLinkRegistry->method('resolveDisplayName')->with(7, 9)->willReturn(null);

		$entry = $this->formatter()->format(['title' => 'T', 'id' => 'u7', 'register' => '7', 'schema' => '9'], null)->jsonSerialize();

		$this->assertSame('/route/openregister.objects.show', $entry['resourceUrl']);
		$this->assertSame([['register' => 7, 'schema' => 9, 'id' => 'u7']], $this->routeCalls);
		$this->assertSame('icon-x', $entry['icon']);
		$this->assertFalse($entry['rounded']);
		$this->assertSame('T', $entry['title']);
	}//end testTopLevelIdsFeedLinkIconAndRoute()

	/**
	 * A claimed pair gets a rounded app icon and its filled deep link.
	 *
	 * @return void
	 */
	public function testClaimedPairIsRoundedAndKeepsItsDeepLink(): void {
		$this->deepLinkRegistry->method('resolveUrl')->willReturn('/apps/x/u1');
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn('App');

		$entry = $this->formatter()->format(['@self' => ['id' => 'u1', 'name' => 'Nm']], null)->jsonSerialize();

		$this->assertSame('/apps/x/u1', $entry['resourceUrl']);
		$this->assertSame('icon-openregister', $entry['icon']);
		$this->assertTrue($entry['rounded']);
		$this->assertSame('Nm', $entry['title']);
		$this->assertSame('App', $entry['subline']);
	}//end testClaimedPairIsRoundedAndKeepsItsDeepLink()
}//end class
