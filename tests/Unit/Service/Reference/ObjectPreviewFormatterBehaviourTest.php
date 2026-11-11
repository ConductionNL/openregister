<?php

/**
 * Behaviour tests for ObjectPreviewFormatter — what a link preview shows.
 *
 * A pasted object URL is parsed, the object fetched and turned into a rich
 * reference. These tests pin the decisions in that composition: which field
 * becomes the title, how the description is cut, which properties are shown
 * (internal ones never, at most four, only strings and numbers), whether the
 * deep link or the default route is used, and every fallback of the schema,
 * register and icon lookups.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Reference
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Reference;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ObjectPreviewFormatterBehaviourTest extends TestCase {

	private const UUID = '0b1e2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	private const URL = 'https://cloud.example.com/index.php/apps/openregister/objects/3/4/' . self::UUID;

	private ObjectOperationsInterface&MockObject $objects;

	private DeepLinkRegistryService&MockObject $deepLinks;

	private SchemaMapperInterface&MockObject $schemas;

	private RegisterMapperInterface&MockObject $registers;

	private LoggerInterface&MockObject $logger;

	private ObjectPreviewFormatter $formatter;

	protected function setUp(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $url): string => $url === '/' ? 'https://cloud.example.com/' : 'https://cloud.example.com' . $url
		);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $args = []): string => '/route/' . $route . '?' . http_build_query($args)
		);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => '/img/' . $app . '/' . $file);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		$this->objects   = $this->createMock(ObjectOperationsInterface::class);
		$this->deepLinks = $this->createMock(DeepLinkRegistryService::class);
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);

		$this->formatter = new ObjectPreviewFormatter(
			$urls,
			$l10n,
			new ObjectPreviewSources($this->objects, $this->deepLinks, $this->schemas, $this->registers),
			$this->logger
		);
	}

	private function object(array $data): ObjectEntity&MockObject {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($data);
		return $object;
	}

	private function titled(string $class, ?string $title, ?string $icon = null): Schema|Register {
		$entity = new $class();
		$entity->setTitle($title);
		if ($icon !== null) {
			$entity->setIcon($icon);
		}
		return $entity;
	}

	public function testAReferenceIsComposedFromTheObjectItsSchemaRegisterAndDeepLink(): void {
		$this->objects->expects($this->once())->method('find')
			->with(...FindCallShape::findArgs(self::UUID, [], false, 3, 4))
			->willReturn($this->object([
				'@self' => ['name' => 'Melding 12', 'updated' => '2026-01-01T00:00:00+00:00', 'owner' => 'anna'],
				'id' => 'internal',
				'_secret' => 'hidden',
				'@type' => 'hidden',
				'summary' => str_repeat('s', 250),
				'priority' => 3,
				'score' => 1.5,
				'tags' => ['a'],
				'empty' => '',
				'street' => str_repeat('x', 150),
				'city' => 'Utrecht',
				'zip' => '3511',
			]));
		$this->schemas->method('find')->willReturn($this->titled(Schema::class, 'Meldingen'));
		$this->registers->method('find')->willReturn($this->titled(Register::class, 'Zaken'));
		$this->deepLinks->expects($this->once())->method('resolveUrl')
			->with(3, 4, ['name' => 'Melding 12', 'updated' => '2026-01-01T00:00:00+00:00', 'owner' => 'anna', 'uuid' => self::UUID, 'register' => 3, 'schema' => 4])
			->willReturn('/apps/procest/#/cases/' . self::UUID);
		$this->deepLinks->method('resolveIcon')->with(3, 4)->willReturn('/apps/procest/icon.svg');

		$reference = $this->formatter->buildReference(self::URL);
		$rich = $reference->getRichObject();

		$this->assertSame('Melding 12', $reference->getTitle());
		$this->assertSame(str_repeat('s', 200), $reference->getDescription(), 'A summary is cut at 200 characters without an ellipsis.');
		$this->assertSame('https://cloud.example.com/apps/procest/#/cases/' . self::UUID, $reference->getUrl());
		$this->assertSame('/apps/procest/icon.svg', $reference->getImageUrl());
		$this->assertSame(['id' => 4, 'title' => 'Meldingen'], $rich['schema']);
		$this->assertSame(['id' => 3, 'title' => 'Zaken'], $rich['register']);
		$this->assertSame('2026-01-01T00:00:00+00:00', $rich['updated']);
		$this->assertSame(
			[
				['label' => 'Summary', 'value' => str_repeat('s', 100)],
				['label' => 'Priority', 'value' => '3'],
				['label' => 'Score', 'value' => '1.5'],
				['label' => 'Street', 'value' => str_repeat('x', 100)],
			],
			$rich['properties'],
			'Internal, empty and non-scalar fields are skipped and at most four are shown.'
		);
	}

	public function testWithoutADeepLinkTheDefaultRouteAndAppIconAreUsed(): void {
		$this->objects->method('find')->willReturn($this->object(['@self' => [], 'title' => 'Titel']));
		$this->deepLinks->method('resolveUrl')->willReturn(null);
		$this->deepLinks->method('resolveIcon')->willReturn(null);
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->registers->method('find')->willReturn($this->titled(Register::class, ''));

		$reference = $this->formatter->buildReference(self::URL);
		$rich = $reference->getRichObject();

		$this->assertSame('Titel', $reference->getTitle());
		$this->assertSame('https://cloud.example.com/route/openregister.objects.show?register=3&schema=4&id=' . self::UUID, $reference->getUrl());
		$this->assertSame('/img/openregister/app-dark.svg', $reference->getImageUrl());
		$this->assertSame('T:Unknown Schema', $rich['schema']['title']);
		$this->assertSame('T:Unknown Register', $rich['register']['title']);
		$this->assertSame('', $rich['updated']);
		$this->assertSame('', $reference->getDescription());
		$this->assertSame([['label' => 'Title', 'value' => 'Titel']], $rich['properties']);
	}

	public function testTheTitleFallsBackFromNameToTheIdAndThenToUnknown(): void {
		$this->deepLinks->method('resolveUrl')->willReturn('/x');
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->object(['@self' => ['name' => ''], 'title' => ['not', 'text'], 'name' => 'Naam']),
			$this->object(['@self' => ['id' => 'self-id'], 'id' => 'data-id']),
			$this->object(['@self' => [], 'id' => 'data-id']),
			$this->object(['@self' => ['id' => 42]])
		);

		$titles = [];
		for ($i = 0; $i < 4; $i++) {
			$titles[] = $this->formatter->buildReference(self::URL)->getTitle();
		}

		$this->assertSame(['Naam', 'self-id', 'data-id', 'T:Unknown Object'], $titles);
	}

	public function testALongDescriptionIsCutWithAnEllipsisAndAShortOneIsKept(): void {
		$this->deepLinks->method('resolveUrl')->willReturn('/x');
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->object(['@self' => [], 'description' => str_repeat('d', 201)]),
			$this->object(['@self' => [], 'description' => str_repeat('d', 200)]),
			$this->object(['@self' => [], 'summary' => '', 'description' => 'Kort'])
		);

		$this->assertSame(str_repeat('d', 200) . '...', $this->formatter->buildReference(self::URL)->getDescription());
		$this->assertSame(str_repeat('d', 200), $this->formatter->buildReference(self::URL)->getDescription());
		$this->assertSame('Kort', $this->formatter->buildReference(self::URL)->getDescription());
	}

	public function testAMissingObjectOrAFailingLookupGivesNoReference(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('db down')));
		$this->logger->expects($this->once())->method('debug')->with(
			'[ObjectPreviewFormatter] Failed to resolve reference: {error}',
			['error' => 'db down', 'reference' => self::URL]
		);

		$this->assertNull($this->formatter->buildReference(self::URL));
		$this->assertNull($this->formatter->buildReference(self::URL));
	}

	public function testAnUnrecognisedUrlIsNeitherFetchedNorCollapsed(): void {
		$this->objects->expects($this->never())->method('find');
		$other = 'https://elsewhere.example.com/apps/openregister/objects/3/4/' . self::UUID;

		$this->assertNull($this->formatter->buildReference($other));
		$this->assertSame($other, $this->formatter->resolveCachePrefix($other));
		$this->assertSame('3/4/' . self::UUID, $this->formatter->resolveCachePrefix(self::URL));
	}

	public function testEveryCanonicalUrlParsesBackToItsParts(): void {
		$urls = $this->formatter->buildCanonicalUrls(new ObjectRef(7, 8, self::UUID));

		$this->assertCount(3, $urls);
		foreach ($urls as $url) {
			$this->assertSame(['registerId' => 7, 'schemaId' => 8, 'uuid' => self::UUID], $this->formatter->parseReference($url));
		}
		$this->assertNull($this->formatter->parseReference('https://cloud.example.com/apps/openregister/objects/7/8/not-a-uuid'));
	}

	public function testTheSchemaIconIsOnlyUsedWhenSetAndKnownToMdi(): void {
		$this->schemas->method('find')->willReturnOnConsecutiveCalls(
			$this->titled(Schema::class, 'S', 'mdi-dog'),
			$this->titled(Schema::class, 'S', 'no-such-icon-xyz'),
			$this->titled(Schema::class, 'S', ''),
			$this->throwException(new DoesNotExistException('gone'))
		);

		$this->assertSame('/route/openregister.icon.mdi?name=mdi-dog', $this->formatter->resolveMdiIconUrl(4));
		$this->assertNull($this->formatter->resolveMdiIconUrl(4));
		$this->assertNull($this->formatter->resolveSchemaIconName(4));
		$this->assertNull($this->formatter->resolveSchemaIconName(4));
	}

	public function testSchemaAndRegisterNamesFallBackOnAnEmptyTitleOrALookupFailure(): void {
		$this->schemas->method('find')->willReturnOnConsecutiveCalls($this->titled(Schema::class, 'Meldingen'), $this->titled(Schema::class, null), $this->throwException(new RuntimeException('x')));
		$this->registers->method('find')->willReturnOnConsecutiveCalls($this->titled(Register::class, 'Zaken'), $this->throwException(new RuntimeException('x')));

		$this->assertSame(['Meldingen', 'T:Unknown Schema', 'T:Unknown Schema'], [$this->formatter->resolveSchemaName(1), $this->formatter->resolveSchemaName(1), $this->formatter->resolveSchemaName(1)]);
		$this->assertSame(['Zaken', 'T:Unknown Register'], [$this->formatter->resolveRegisterName(1), $this->formatter->resolveRegisterName(1)]);
	}
}
