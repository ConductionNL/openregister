<?php

/**
 * Unit tests for the smart picker reading a schema's reference card.
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
 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCA\OpenRegister\Service\Search\SearchResultDeclaration;
use OCA\OpenRegister\Service\Search\SearchResultDeclarations;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The picker offers objects through the unified search, so a result reads the
 * card a schema declares when it declares no search result of its own.
 *
 * @covers \OCA\OpenRegister\Service\Search\SearchResultDeclarations
 * @covers \OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\MdiIconRenderer
 * @uses \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter
 * @uses \OCA\OpenRegister\Service\Reference\ObjectPreviewSources
 * @uses \OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration
 * @uses \OCA\OpenRegister\Service\Search\ObjectSearchHit
 * @uses \OCA\OpenRegister\Service\Search\SearchResultDeclaration
 */
class SearchResultCardTest extends TestCase {

	/**
	 * A schema with the given configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private static function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setConfiguration($configuration);
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
	 * The case object the tests use.
	 *
	 * @return array<string, mixed>
	 */
	private static function caseObject(): array {
		return [
			'title' => 'Intern dossier 12',
			'identifier' => 'Z-2026-0042',
			'summary' => 'Kapvergunning eik Kerkstraat',
			'subject' => 'Kapvergunning',
			'status' => 'In behandeling',
			'@self' => ['id' => 'u1', 'register' => 1, 'schema' => 2],
		];
	}//end caseObject()

	public function testACardOnlySchemaShowsTheCardInThePicker(): void {
		$schema = self::schema([ReferenceCardDeclaration::ANNOTATION => ['titleProperty' => 'identifier', 'summary' => ['subject', 'status']]]);

		$entry = $this->formatter($schema)->format(self::caseObject(), 'eik')->jsonSerialize();

		self::assertSame('Z-2026-0042', $entry['title']);
		self::assertStringStartsWith('Dossiq · ', $entry['subline']);
		self::assertStringEndsWith(' — Subject: Kapvergunning · Status: In behandeling', $entry['subline']);
	}//end testACardOnlySchemaShowsTheCardInThePicker()

	public function testTheSearchResultDeclarationWinsOverTheCard(): void {
		$schema = self::schema(
			[
				SearchResultDeclaration::ANNOTATION => ['titleProperty' => 'title', 'sublineProperty' => 'summary'],
				ReferenceCardDeclaration::ANNOTATION => ['titleProperty' => 'identifier', 'summary' => ['subject']],
			]
		);

		$entry = $this->formatter($schema)->format(self::caseObject(), null)->jsonSerialize();

		self::assertSame('Intern dossier 12', $entry['title']);
		self::assertStringEndsWith(' — Kapvergunning eik Kerkstraat', $entry['subline']);
		self::assertStringNotContainsString('Subject:', $entry['subline']);
	}//end testTheSearchResultDeclarationWinsOverTheCard()

	public function testACardWithOnlyASummaryKeepsTheDerivedTitle(): void {
		$schema = self::schema([ReferenceCardDeclaration::ANNOTATION => ['summary' => ['status']]]);

		$entry = $this->formatter($schema)->format(self::caseObject(), null)->jsonSerialize();

		self::assertSame('Intern dossier 12', $entry['title']);
		self::assertStringEndsWith(' — Status: In behandeling', $entry['subline']);
	}//end testACardWithOnlyASummaryKeepsTheDerivedTitle()

	public function testAnObjectLeavingTheCardEmptyIsBuiltExactlyAsWithoutIt(): void {
		$schema = self::schema([ReferenceCardDeclaration::ANNOTATION => ['titleProperty' => 'identifier', 'summary' => ['subject']]]);
		$object = array_merge(self::caseObject(), ['identifier' => '', 'subject' => null]);

		$carded = $this->formatter($schema)->format($object, 'eik')->jsonSerialize();
		$plain = $this->formatter($schema, withDeclared: false)->format($object, 'eik')->jsonSerialize();

		self::assertSame($plain, $carded);
	}//end testAnObjectLeavingTheCardEmptyIsBuiltExactlyAsWithoutIt()

	public function testBothDeclarationsShareOneSchemaLoadAndAFailedLoadDeclaresNone(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->expects($this->exactly(2))->method('find')->willReturnCallback(
			static fn (int|string $id): Schema => match ((int)$id) {
				2 => self::schema(
					[
						SearchResultDeclaration::ANNOTATION => ['titleProperty' => 'identifier'],
						ReferenceCardDeclaration::ANNOTATION => ['summary' => ['status']],
					]
				),
				default => throw new RuntimeException('gone'),
			}
		);
		$declarations = new SearchResultDeclarations($schemaMapper, $this->createMock(LoggerInterface::class));

		self::assertSame('identifier', $declarations->forSchema(2)?->titleProperty);
		self::assertSame(['status'], $declarations->cardFor(2)?->summary);
		self::assertSame(['status'], $declarations->cardFor(2)?->summary);
		self::assertNull($declarations->cardFor(9));
		self::assertNull($declarations->forSchema(9));
		self::assertNull($declarations->cardFor(0));
	}//end testBothDeclarationsShareOneSchemaLoadAndAFailedLoadDeclaresNone()
}//end class
