<?php

/**
 * Unit tests for the search result a schema declares.
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
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCA\OpenRegister\Service\Search\SearchResultDeclaration;
use OCA\OpenRegister\Service\Search\SearchResultDeclarations;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SearchResultDeclaration, SearchResultDeclarations and their use in the result formatter.
 *
 * @covers \OCA\OpenRegister\Service\Search\SearchResultDeclaration
 * @covers \OCA\OpenRegister\Service\Search\SearchResultDeclarations
 * @covers \OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\MdiIconRenderer
 * @uses \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter
 * @uses \OCA\OpenRegister\Service\Reference\ObjectPreviewSources
 * @uses \OCA\OpenRegister\Service\Search\ObjectSearchHit
 */
class SearchResultDeclarationTest extends TestCase {

	/**
	 * A schema declaring the given block.
	 *
	 * @param mixed $block The annotation's value.
	 *
	 * @return Schema
	 */
	private function schema(mixed $block): Schema {
		$schema = new Schema();
		$schema->setConfiguration([SearchResultDeclaration::ANNOTATION => $block]);
		return $schema;
	}//end schema()

	/**
	 * A formatter whose schemas all answer with the given schema.
	 *
	 * @param Schema $schema       The schema every lookup answers.
	 * @param bool   $withDeclared Whether the formatter reads declarations.
	 *
	 * @return ObjectSearchResultFormatter
	 */
	private function formatter(Schema $schema, bool $withDeclared = true): ObjectSearchResultFormatter {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route, array $params): string => '/objects/' . ($params['id'] ?? ''));
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.com' . $url);

		$deepLinks = $this->createMock(DeepLinkRegistryService::class);
		$deepLinks->method('resolveUrl')->willReturn(null);
		$deepLinks->method('resolveIcon')->willReturn(null);
		$deepLinks->method('resolveDisplayName')->willReturn('Dossiq');

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$preview = new ObjectPreviewFormatter(
			$urls,
			$l10n,
			new ObjectPreviewSources(
				objectService: $this->createMock(ObjectService::class),
				deepLinkRegistry: $deepLinks,
				schemaMapper: $schemaMapper,
				registerMapper: $this->createMock(RegisterMapper::class)
			),
			$this->createMock(LoggerInterface::class)
		);

		$declarations = null;
		if ($withDeclared === true) {
			$declarations = new SearchResultDeclarations($schemaMapper, $this->createMock(LoggerInterface::class));
		}

		return new ObjectSearchResultFormatter($urls, $deepLinks, $preview, $declarations);
	}//end formatter()

	/**
	 * The case object the formatter tests use.
	 *
	 * @return array<string, mixed>
	 */
	private static function caseObject(): array {
		return [
			'title' => 'Intern dossier 12',
			'identifier' => 'Z-2026-0042',
			'summary' => 'Kapvergunning eik Kerkstraat',
			'subject' => 'Kapvergunning',
			'@self' => ['id' => 'u1', 'register' => 1, 'schema' => 2],
		];
	}//end caseObject()

	public function testASchemaThatDeclaresNothingHasNoDeclaration(): void {
		self::assertNull(SearchResultDeclaration::of(new Schema()));
		self::assertNull(SearchResultDeclaration::of($this->schema('titleProperty')));
		self::assertNull(SearchResultDeclaration::of($this->schema(['titleProperty' => ' ', 'sublineProperty' => 7])));
	}//end testASchemaThatDeclaresNothingHasNoDeclaration()

	public function testTheDeclaredValuesAreReadFromTheObject(): void {
		$declared = SearchResultDeclaration::of($this->schema(['titleProperty' => ' identifier ', 'sublineProperty' => 'subject', 'orderDateProperty' => 'receivedAt']));

		self::assertSame('Z-2026-0042', $declared->title(['identifier' => ' Z-2026-0042 ']));
		self::assertSame('Bezwaar', $declared->subline(['subject' => 'Bezwaar']));
		self::assertSame(1789200000, $declared->orderDate(['receivedAt' => '2026-09-12T08:00:00+00:00']));
		self::assertNull($declared->title(['identifier' => '']));
		self::assertNull($declared->subline(['subject' => ['nested']]));
		self::assertNull($declared->subline(['subject' => true]));
		self::assertNull($declared->orderDate(['receivedAt' => 'ooit']));
		self::assertNull($declared->orderDate([]));
	}//end testTheDeclaredValuesAreReadFromTheObject()

	public function testADeclarationNamingAMissingPropertyIsRefusedByName(): void {
		$properties = ['identifier' => ['type' => 'string'], 'receivedAt' => ['type' => 'string', 'format' => 'date-time']];

		self::assertSame([], SearchResultDeclaration::faults(null, $properties));
		self::assertSame(['x-openregister-search-result must be an object.'], SearchResultDeclaration::faults('identifier', $properties));
		self::assertSame([], SearchResultDeclaration::faults(['titleProperty' => 'identifier', 'orderDateProperty' => 'receivedAt'], $properties));
		self::assertSame(
			[
				'x-openregister-search-result.sublineProperty ""onderwerp"" is not a property of this schema.',
				'x-openregister-search-result.orderDateProperty "12" is not a property of this schema.',
			],
			SearchResultDeclaration::faults(['titleProperty' => 'identifier', 'sublineProperty' => 'onderwerp', 'orderDateProperty' => 12], $properties)
		);
	}//end testADeclarationNamingAMissingPropertyIsRefusedByName()

	public function testDeclarationsAreLoadedOncePerSchemaAndAFailedLoadDeclaresNone(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->expects($this->exactly(2))->method('find')->willReturnCallback(
			fn (int|string $id): Schema => match ((int)$id) {
				2 => $this->schema(['titleProperty' => 'identifier']),
				default => throw new RuntimeException('gone'),
			}
		);
		$declarations = new SearchResultDeclarations($schemaMapper, $this->createMock(LoggerInterface::class));

		self::assertSame('identifier', $declarations->forSchema(2)?->titleProperty);
		self::assertSame('identifier', $declarations->forSchema(2)?->titleProperty);
		self::assertNull($declarations->forSchema(9));
		self::assertNull($declarations->forSchema(9));
		self::assertNull($declarations->forSchema(0));
	}//end testDeclarationsAreLoadedOncePerSchemaAndAFailedLoadDeclaresNone()

	public function testACaseReadsAsACaseInTheSearchBar(): void {
		$schema = $this->schema(['titleProperty' => 'identifier', 'sublineProperty' => 'subject']);

		$entry = $this->formatter($schema)->format(self::caseObject(), 'eik')->jsonSerialize();

		self::assertSame('Z-2026-0042', $entry['title']);
		self::assertStringStartsWith('Dossiq · ', $entry['subline']);
		self::assertStringEndsWith(' — Kapvergunning', $entry['subline']);
	}//end testACaseReadsAsACaseInTheSearchBar()

	public function testAnObjectLeavingTheDeclaredPropertiesEmptyFallsBackToTheDerivation(): void {
		$schema = $this->schema(['titleProperty' => 'identifier', 'sublineProperty' => 'subject']);
		$object = array_merge(self::caseObject(), ['identifier' => '', 'subject' => null]);

		$declared = $this->formatter($schema)->format($object, null)->jsonSerialize();
		$plain = $this->formatter($schema, withDeclared: false)->format($object, null)->jsonSerialize();

		self::assertSame($plain, $declared);
		self::assertSame('Intern dossier 12', $declared['title']);
	}//end testAnObjectLeavingTheDeclaredPropertiesEmptyFallsBackToTheDerivation()

	public function testAnUndeclaredSchemaBuildsTheEntryExactlyAsBefore(): void {
		$object = self::caseObject();

		self::assertSame(
			$this->formatter(new Schema(), withDeclared: false)->format($object, 'eik')->jsonSerialize(),
			$this->formatter(new Schema())->format($object, 'eik')->jsonSerialize()
		);
		self::assertSame(
			$this->formatter(new Schema(), withDeclared: false)->format(new ObjectEntity(), null)->jsonSerialize(),
			$this->formatter(new Schema())->format(new ObjectEntity(), null)->jsonSerialize()
		);
	}//end testAnUndeclaredSchemaBuildsTheEntryExactlyAsBefore()
}//end class
