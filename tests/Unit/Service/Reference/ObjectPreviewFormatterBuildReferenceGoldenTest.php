<?php

/**
 * Golden test for ObjectPreviewFormatter::buildReference().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Reference
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Reference;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each case resolves one object reference and records the reference (title,
 * description, image, url, rich object), or null when the object is absent or
 * the lookup is refused, plus the find() arguments and the logged message.
 * Compared with fixtures/object-preview-build-reference-golden.json; set
 * UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter
 */
class ObjectPreviewFormatterBuildReferenceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/object-preview-build-reference-golden.json';

	private const UUID = '550e8400-e29b-41d4-a716-446655440000';

	/**
	 * @return array<string, array{0: string, 1: mixed, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		$hash = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/' . self::UUID;
		$api = 'https://cloud.example.com/index.php/apps/openregister/api/objects/7/3/' . self::UUID;
		$long = str_repeat('abcdefghij', 25);
		return [
			'not a reference'           => ['https://cloud.example.com/apps/files/', [], []],
			'object absent'             => [$hash, null, []],
			'lookup refused'            => [$hash, 'throw', []],
			'self name'                 => [$hash, ['@self' => ['name' => 'Self', 'updated' => '2026-01-01'], 'title' => 'T', 'name' => 'N'], []],
			'title over name'           => [$hash, ['@self' => ['name' => ''], 'title' => 'T', 'name' => 'N'], []],
			'name when title not text'  => [$hash, ['@self' => ['name' => 5], 'title' => ['x'], 'name' => 'N'], []],
			'zero string skipped'       => [$hash, ['@self' => ['name' => '0'], 'title' => '0', 'name' => 'N'], []],
			'self id fallback'          => [$hash, ['@self' => ['id' => 'self-id'], 'id' => 'top-id'], []],
			'top id fallback'           => [$hash, ['@self' => [], 'id' => 'top-id'], []],
			'id not text'               => [$hash, ['@self' => ['id' => 9]], []],
			'empty id'                  => [$hash, ['@self' => ['id' => ''], 'id' => 'top-id'], []],
			'summary'                   => [$hash, ['@self' => [], 'summary' => $long, 'description' => 'D'], []],
			'description short'         => [$hash, ['@self' => [], 'summary' => '', 'description' => 'Short'], []],
			'description long'          => [$hash, ['@self' => [], 'summary' => 3, 'description' => $long], []],
			'description exactly max'   => [$hash, ['@self' => [], 'description' => substr($long, 0, 200)], []],
			'description multibyte'     => [$hash, ['@self' => [], 'description' => str_repeat('é', 201)], []],
			'description not text'      => [$hash, ['@self' => [], 'description' => ['x']], []],
			'properties'                => [$hash, ['@self' => [], 'id' => 'i', 'uuid' => 'u', '_owner' => 'o', '_x' => 'x', '@y' => 'y', 'empty' => '', 'flag' => true, 'list' => [1], 'none' => null, 'status' => 'Active', 'count' => 3, 'ratio' => 1.5, 'long' => $long, 'fifth' => 'F'], []],
			'properties fewer than max' => [$hash, ['@self' => [], 'a' => 'A', 'b' => 0], []],
			'deep link url and icon'    => [$api, ['@self' => ['name' => 'X']], ['url' => '/custom/x', 'icon' => 'https://icons/x.svg']],
			'deep link absolute url'    => [$api, ['@self' => ['name' => 'X', 'register' => 99, 'uuid' => 'other']], ['url' => 'https://elsewhere/x']],
			'self not array'            => [$hash, ['@self' => 'oops', 'title' => 'T'], []],
			'unknown schema register'   => [$hash, ['@self' => ['name' => 'X']], ['schema' => 'throw', 'register' => 'throw']],
			'untitled schema register'  => [$hash, ['@self' => ['name' => 'X']], ['schema' => '', 'register' => null]],
			'deep link throws'          => [$hash, ['@self' => ['name' => 'X']], ['url' => 'throw']],
		];
	}

	/**
	 * @param string               $url     The reference text.
	 * @param mixed                $object  The serialised object, null (absent) or 'throw' (refused).
	 * @param array<string, mixed> $options Deep-link and name-lookup behaviour.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testBuildReference(string $url, mixed $object, array $options): void {
		$record = ['find' => [], 'deepLinkData' => null, 'logged' => []];

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => (str_starts_with($path, 'https://') === true) ? $path . '#abs' : 'https://cloud.example.com' . $path
		);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params): string => '/route/' . $route . '/' . implode('/', $params)
		);
		$urlGenerator->method('imagePath')->willReturnCallback(
			static fn (string $app, string $file): string => '/img/' . $app . '/' . $file
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'l10n:' . $text);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (...$args) use ($object, &$record) {
				$record['find'][] = $args;
				if ($object === 'throw') {
					throw new \RuntimeException('Access denied');
				}

				if ($object === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);

		$deepLinks = $this->createMock(DeepLinkRegistryService::class);
		$deepLinks->method('resolveUrl')->willReturnCallback(
			static function (int $registerId, int $schemaId, array $objectData) use ($options, &$record): ?string {
				$record['deepLinkData'] = [$registerId, $schemaId, $objectData];
				if (($options['url'] ?? null) === 'throw') {
					throw new \RuntimeException('deep link failed');
				}

				return ($options['url'] ?? null);
			}
		);
		$deepLinks->method('resolveIcon')->willReturn(($options['icon'] ?? null));

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function () use ($options): Schema {
				if (($options['schema'] ?? 'Producten') === 'throw') {
					throw new \RuntimeException('no schema');
				}

				$schema = new Schema();
				$schema->setTitle(($options['schema'] ?? 'Producten'));
				return $schema;
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function () use ($options): Register {
				if (($options['register'] ?? 'Gemeente') === 'throw') {
					throw new \RuntimeException('no register');
				}

				$register = new Register();
				$register->setTitle(($options['register'] ?? 'Gemeente'));
				return $register;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function (string $message, array $context) use (&$record): void {
				$record['logged'][] = [$message, $context];
			}
		);

		$formatter = new ObjectPreviewFormatter(
			$urlGenerator,
			$l10n,
			new ObjectPreviewSources(
				objectService: $objectService,
				deepLinkRegistry: $deepLinks,
				schemaMapper: $schemaMapper,
				registerMapper: $registerMapper
			),
			$logger
		);

		try {
			$reference = $formatter->buildReference($url);
			$outcome = ($reference === null) ? null : $reference->jsonSerialize();
		} catch (\Throwable $e) {
			$outcome = ['thrown' => get_class($e)];
		}

		$actual = [
			'reference' => $outcome,
			'record'    => $record,
		];
		$actual = json_decode((string) json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true);

		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}

	/**
	 * Mutation check: a refused lookup that slipped through would surface a
	 * reference; the golden for "lookup refused" must therefore be null.
	 *
	 * @return void
	 */
	public function testRefusedLookupGoldenIsNull(): void {
		$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertNull($golden['lookup refused']['reference']);
		$this->assertNull($golden['object absent']['reference']);
		$this->assertNotNull($golden['self name']['reference']);
	}
}
