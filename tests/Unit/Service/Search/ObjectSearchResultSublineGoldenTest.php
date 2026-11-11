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
class ObjectSearchResultSublineGoldenTest extends TestCase {

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
	 * The subline names the owner, then the register and the schema, each only when the hit carries its id.
	 *
	 * @return void
	 */
	public function testSublineNamesRegisterAndSchemaOnlyWhenKnown(): void {
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$formatter = $this->formatter();

		$both = $formatter->format(['@self' => ['id' => 'u1', 'register' => 7, 'schema' => 9]], null)->jsonSerialize()['subline'];
		$schemaOnly = $formatter->format(['@self' => ['id' => 'u2', 'schema' => 9]], null)->jsonSerialize()['subline'];
		$neither = $formatter->format(['@self' => ['id' => 'u3']], null)->jsonSerialize()['subline'];

		$this->assertSame(['Open Register · Unknown Register · Unknown Schema', 'Open Register · Unknown Schema', 'Open Register'], [$both, $schemaOnly, $neither]);
	}//end testSublineNamesRegisterAndSchemaOnlyWhenKnown()
}//end class
