<?php

/**
 * Unit tests for the Core ports the search result formatter reads its labels and links through.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Kernel\Contracts\Core\AppDeepLinksInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RegisterSchemaLabelsInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The formatter needs only the two Core ports; Integrations and Configuration bind them.
 *
 * @covers \OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter
 * @uses \OCA\OpenRegister\Service\Search\ObjectSearchHit
 */
final class ObjectSearchResultFormatterPortsTest extends TestCase {

	private const HIT = [
		'title' => 'Case 7',
		'case'  => 'c-7',
		'@self' => ['id' => 'u-7', 'register' => 3, 'schema' => 5],
	];

	/**
	 * A formatter over port doubles.
	 *
	 * @param string|null $deepLink The deep link the registry answers.
	 * @param string|null $mdiIcon  The schema icon URL the labels answer.
	 *
	 * @return ObjectSearchResultFormatter
	 */
	private function formatter(?string $deepLink, ?string $mdiIcon): ObjectSearchResultFormatter {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params): string => '/or/' . $params['register'] . '/' . $params['schema'] . '/' . $params['id']
		);

		$deepLinks = $this->createMock(AppDeepLinksInterface::class);
		$deepLinks->method('resolveDisplayName')->with(3, 5)->willReturn('Dossiq');
		$deepLinks->method('resolveUrl')->willReturn($deepLink);
		$deepLinks->method('resolveIcon')->with(3, 5)->willReturn('icon-dossiq');

		$labels = $this->createMock(RegisterSchemaLabelsInterface::class);
		$labels->method('resolveRegisterName')->with(3)->willReturn('Zaken');
		$labels->method('resolveSchemaName')->with(5)->willReturn('Zaak');
		$labels->method('resolveMdiIconUrl')->with(5)->willReturn($mdiIcon);

		return new ObjectSearchResultFormatter($urls, $deepLinks, $labels);
	}

	public function testAResultIsBuiltFromTheAppsLinkAndLabelAndTheRegisterAndSchemaNames(): void {
		$entry = $this->formatter(deepLink: '/apps/dossiq/cases/c-7', mdiIcon: null)->format(self::HIT, null)->jsonSerialize();

		$this->assertSame('Case 7', $entry['title']);
		$this->assertSame('Dossiq · Zaken · Zaak', $entry['subline']);
		$this->assertSame('/apps/dossiq/cases/c-7', $entry['resourceUrl']);
		$this->assertSame('icon-dossiq', $entry['icon']);
		$this->assertTrue($entry['rounded']);
		$this->assertSame('', $entry['thumbnailUrl']);
	}

	public function testTheSchemaIconWinsAndAnUnfilledLinkFallsBackToOpenRegistersPage(): void {
		$entry = $this->formatter(deepLink: '/apps/dossiq/cases/{missing}', mdiIcon: '/icon/mdi/Dog')->format(self::HIT, null)->jsonSerialize();

		$this->assertSame('/or/3/5/u-7', $entry['resourceUrl']);
		$this->assertSame('/icon/mdi/Dog', $entry['thumbnailUrl']);
		$this->assertSame('icon-openregister', $entry['icon']);
		$this->assertFalse($entry['rounded']);
	}

	public function testIntegrationsBindsTheDeepLinkPortToItsRegistry(): void {
		$this->assertSame(DeepLinkRegistryService::class, IntegrationWiring::BINDINGS[AppDeepLinksInterface::class]);
		$this->assertTrue(is_subclass_of(DeepLinkRegistryService::class, AppDeepLinksInterface::class));
		$this->assertTrue(is_subclass_of(AppDeepLinksInterface::class, DeepLinkResolverInterface::class));
	}

	public function testConfigurationBindsTheLabelsPortToItsPreviewFormatter(): void {
		$this->assertSame(ObjectPreviewFormatter::class, ContractBindings::BINDINGS[RegisterSchemaLabelsInterface::class]);
		$this->assertTrue(is_subclass_of(ObjectPreviewFormatter::class, RegisterSchemaLabelsInterface::class));
	}
}
