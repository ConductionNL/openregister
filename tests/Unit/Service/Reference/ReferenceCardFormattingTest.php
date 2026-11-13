<?php

/**
 * The card a reference renders: the schema's declaration when it has one, and
 * the title-and-schema default when it has none.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Reference
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

namespace OCA\OpenRegister\Tests\Unit\Service\Reference;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the declared and the default reference card.
 *
 * @covers \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Reference\ObjectPreviewSources
 * @uses \OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 */
final class ReferenceCardFormattingTest extends TestCase {

	/**
	 * The referenced object's uuid.
	 */
	private const UUID = '0b1e2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	/**
	 * Its URL, as the deep link registry parses it.
	 */
	private const URL = 'https://cloud.example.com/index.php/apps/openregister/objects/3/4/' . self::UUID;

	/**
	 * The object service.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * The schemas.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemas;

	/**
	 * The formatter under test.
	 *
	 * @var ObjectPreviewFormatter
	 */
	private ObjectPreviewFormatter $formatter;

	/**
	 * Build the formatter over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.com' . $url);
		$urls->method('linkToRoute')->willReturn('/route');
		$urls->method('imagePath')->willReturn('/img/openregister/app.svg');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$register = new Register();
		$register->setTitle('Vastgoed');
		$registers->method('find')->willReturn($register);
		$deepLinks = $this->createMock(DeepLinkRegistryService::class);
		$deepLinks->method('resolveUrl')->willReturn('/apps/vastgoed/#/panden/' . self::UUID);
		$deepLinks->method('resolveIcon')->willReturn('/apps/vastgoed/icon.svg');

		$this->formatter = new ObjectPreviewFormatter(
			$urls,
			$l10n,
			new ObjectPreviewSources($this->objects, $deepLinks, $this->schemas, $registers),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Serve one object and one schema.
	 *
	 * @param array<string, mixed> $objectData The rendered object.
	 * @param mixed                $card       The schema's card block, or null for none.
	 *
	 * @return void
	 */
	private function serve(array $objectData, mixed $card): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($objectData);
		$this->objects->method('find')->willReturn($object);

		$schema = new Schema();
		$schema->setTitle('Panden');
		if ($card !== null) {
			$schema->setConfiguration([ReferenceCardDeclaration::ANNOTATION => $card]);
		}

		$this->schemas->method('find')->willReturn($schema);
	}//end serve()

	/**
	 * The object the tests reference.
	 *
	 * @return array<string, mixed> The rendered object.
	 */
	private function pand(): array {
		return [
			'@self' => ['name' => 'Object 12', 'updated' => '2026-01-01T00:00:00+00:00'],
			'adres' => 'Lindelaan 2',
			'bouwjaar' => 1923,
			'gebruiksdoel' => 'woonfunctie',
			'kadastraal' => 'UTR-1',
			'foto' => ['downloadUrl' => 'https://cloud.example.com/f/9'],
		];
	}//end pand()

	/**
	 * A declared card shows the declared title, its summary rows and its image.
	 *
	 * @return void
	 */
	public function testADeclaredCardShowsTheDeclaredFields(): void {
		$this->serve(
			objectData: $this->pand(),
			card: ['titleProperty' => 'adres', 'summary' => ['bouwjaar', 'gebruiksdoel'], 'imageProperty' => 'foto']
		);

		$reference = $this->formatter->buildReference(referenceText: self::URL);
		$rich = $reference?->getRichObject();

		$this->assertSame('Lindelaan 2', $reference?->getTitle());
		$this->assertSame('Bouwjaar: 1923 · Gebruiksdoel: woonfunctie', $reference?->getDescription());
		$this->assertSame('https://cloud.example.com/f/9', $reference?->getImageUrl());
		$this->assertSame(
			[['label' => 'Bouwjaar', 'value' => '1923'], ['label' => 'Gebruiksdoel', 'value' => 'woonfunctie']],
			$rich['properties']
		);
		$this->assertSame('https://cloud.example.com/f/9', $rich['image_url']);
	}//end testADeclaredCardShowsTheDeclaredFields()

	/**
	 * A card declaring only summary properties keeps the object's own title and icon.
	 *
	 * @return void
	 */
	public function testACardWithoutATitleOrImageKeepsTheDefaults(): void {
		$this->serve(objectData: $this->pand(), card: ['summary' => ['kadastraal']]);

		$reference = $this->formatter->buildReference(referenceText: self::URL);

		$this->assertSame('Object 12', $reference?->getTitle());
		$this->assertSame('Kadastraal: UTR-1', $reference?->getDescription());
		$this->assertSame('/apps/vastgoed/icon.svg', $reference?->getImageUrl());
		$this->assertArrayNotHasKey('image_url', (array)$reference?->getRichObject());
	}//end testACardWithoutATitleOrImageKeepsTheDefaults()

	/**
	 * A declared card whose fields the object does not fill falls back to the schema's name.
	 *
	 * @return void
	 */
	public function testADeclaredCardWithNothingToShowNamesTheSchema(): void {
		$this->serve(
			objectData: ['@self' => ['name' => 'Object 12'], 'adres' => 'Lindelaan 2'],
			card: ['summary' => ['bouwjaar']]
		);

		$reference = $this->formatter->buildReference(referenceText: self::URL);

		$this->assertSame('Object 12', $reference?->getTitle());
		$this->assertSame('Panden', $reference?->getDescription());
	}//end testADeclaredCardWithNothingToShowNamesTheSchema()

	/**
	 * An undeclared schema is dull, not wrong: the object's title, its schema in the
	 * rich object, and the first properties it fills.
	 *
	 * @return void
	 */
	public function testAnUndeclaredSchemaIsDullNotWrong(): void {
		$this->serve(objectData: $this->pand(), card: null);

		$reference = $this->formatter->buildReference(referenceText: self::URL);
		$rich = $reference?->getRichObject();

		$this->assertSame('Object 12', $reference?->getTitle());
		$this->assertSame('Panden', $rich['schema']['title']);
		$this->assertSame('', $reference?->getDescription());
		$this->assertSame('Adres', $rich['properties'][0]['label']);
		$this->assertArrayNotHasKey('image_url', (array)$rich);
	}//end testAnUndeclaredSchemaIsDullNotWrong()
}//end class
