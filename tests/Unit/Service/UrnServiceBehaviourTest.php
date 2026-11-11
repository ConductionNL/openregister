<?php

/**
 * UrnService behaviour: the instance slug, building a URN for an object, parsing,
 * resolving to the API url, the reverse url-to-URN lookup and bulk resolution.
 *
 * UrnServiceGoldenTest snapshots the same calls; this file asserts what each
 * branch decides, so a regression names itself.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\UrnService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\UrnService
 */
class UrnServiceBehaviourTest extends TestCase {

	private const UUID = '1c1c970f-d50c-4943-8128-78999e240eec';
	private const BASE = 'https://cloud.example.com';

	private RegisterMapperInterface&MockObject $registers;
	private SchemaMapperInterface&MockObject $schemas;
	private IURLGenerator&MockObject $urls;
	private IAppConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => self::BASE . $path);
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturn('');
		$this->logger = $this->createMock(LoggerInterface::class);

		$register = new Register();
		$register->setSlug('decidesk');
		$schema = new Schema();
		$schema->setSlug('meeting');
		$this->registers->method('find')->willReturnCallback(static function (string|int $id) use ($register): Register {
			return match ((string)$id) {
				'1', 'decidesk' => $register,
				'broken' => throw new RuntimeException('db down'),
				default => throw new DoesNotExistException('no register'),
			};
		});
		$this->schemas->method('find')->willReturnCallback(static function (string|int $id) use ($schema): Schema {
			return match ((string)$id) {
				'2', 'meeting' => $schema,
				'broken' => throw new RuntimeException('db down'),
				default => throw new DoesNotExistException('no schema'),
			};
		});
	}

	private function service(?IURLGenerator $urls = null, ?IAppConfig $config = null): UrnService {
		return new UrnService(
			new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registers, schemas: $this->schemas, tables: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class)),
			$urls ?? $this->urls,
			$config ?? $this->config,
			$this->logger
		);
	}

	private static function object(?string $uuid, ?string $register, ?string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister($register);
		$object->setSchema($schema);
		return $object;
	}

	public function testTheInstanceSlugIsTheSanitisedHostByDefault(): void {
		$this->assertSame('cloud-example-com', $this->service()->getInstanceSlug());
	}

	public function testAConfiguredInstanceOverridesTheHost(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueString')->with('openregister', 'urn_instance', '')->willReturn(' My Stable_ID! ');

		$this->assertSame('my-stable-id', $this->service(config: $config)->getInstanceSlug());
	}

	public function testAnUnreadableConfigFallsBackToTheHost(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willThrowException(new RuntimeException('config down'));

		$this->assertSame('cloud-example-com', $this->service(config: $config)->getInstanceSlug());
	}

	public function testWithoutAUsableHostTheInstanceIsLocalhost(): void {
		$throwing = $this->createMock(IURLGenerator::class);
		$throwing->method('getAbsoluteURL')->willThrowException(new RuntimeException('no request'));
		$relative = $this->createMock(IURLGenerator::class);
		$relative->method('getAbsoluteURL')->willReturn('/index.php');

		$this->assertSame('localhost', $this->service(urls: $throwing)->getInstanceSlug());
		$this->assertSame('localhost', $this->service(urls: $relative)->getInstanceSlug());
	}

	public function testBuildLowerCasesEveryPart(): void {
		$this->assertSame(
			'urn:nl-or:cloud-example-com:decidesk:meeting:' . self::UUID,
			$this->service()->build('DeciDesk', 'Meeting', strtoupper(self::UUID))
		);
	}

	public function testBuildForObjectResolvesIdsToSlugs(): void {
		$this->assertSame(
			'urn:nl-or:cloud-example-com:decidesk:meeting:' . self::UUID,
			$this->service()->buildForObject(self::object(self::UUID, '1', '2'))
		);
	}

	public function testBuildForObjectNeedsUuidRegisterAndSchema(): void {
		$service = $this->service();

		$this->assertNull($service->buildForObject(self::object(null, '1', '2')));
		$this->assertNull($service->buildForObject(self::object(self::UUID, null, '2')));
		$this->assertNull($service->buildForObject(self::object(self::UUID, '1', '')));
		$this->assertNull($service->buildForObject(self::object(self::UUID, 'missing', '2')));
		$this->assertNull($service->buildForObject(self::object(self::UUID, '1', 'missing')));
	}

	public function testALookupFailureOtherThanAMissIsLoggedAndYieldsNull(): void {
		$this->logger->expects($this->once())->method('debug')
			->with('[UrnService] register lookup failed for "broken": db down');

		$this->assertNull($this->service()->buildForObject(self::object(self::UUID, 'broken', '2')));
	}

	public function testAMissIsNotLogged(): void {
		$this->logger->expects($this->never())->method('debug');

		$this->assertNull($this->service()->buildForObject(self::object(self::UUID, 'missing', '2')));
	}

	public function testParseSplitsAnOpenRegisterUrnCaseInsensitivelyOnTheNid(): void {
		$expected = ['instance' => 'a', 'register' => 'b', 'schema' => 'c', 'uuid' => 'd'];

		$this->assertSame($expected, $this->service()->parse('urn:nl-or:a:b:c:d'));
		$this->assertSame($expected, $this->service()->parse('URN:NL-OR:a:b:c:d:extra'));
	}

	public function testParseRefusesOtherNamespacesShortUrnsAndNonUrns(): void {
		$service = $this->service();

		$this->assertNull($service->parse('urn:isbn:a:b:c:d'));
		$this->assertNull($service->parse('urn:nl-or:a:b:c'));
		$this->assertNull($service->parse('not a urn'));
	}

	public function testResolveUrlPointsAtTheReadByUuidEndpoint(): void {
		$this->assertSame(
			self::BASE . '/apps/openregister/api/objects/decidesk/meeting/' . self::UUID,
			$this->service()->resolveUrl('urn:nl-or:cloud-example-com:1:2:' . self::UUID)
		);
	}

	public function testResolveUrlEncodesTheUuidSegment(): void {
		$this->assertSame(
			self::BASE . '/apps/openregister/api/objects/decidesk/meeting/a%20b%2Fc',
			$this->service()->resolveUrl('urn:nl-or:cloud-example-com:decidesk:meeting:a b/c')
		);
	}

	public function testResolveUrlRefusesForeignInstancesUnknownRefsAndGarbage(): void {
		$service = $this->service();

		$this->assertNull($service->resolveUrl('urn:nl-or:elsewhere:decidesk:meeting:' . self::UUID));
		$this->assertNull($service->resolveUrl('urn:nl-or:cloud-example-com:missing:meeting:' . self::UUID));
		$this->assertNull($service->resolveUrl('urn:nl-or:cloud-example-com:decidesk:missing:' . self::UUID));
		$this->assertNull($service->resolveUrl('garbage'));
	}

	public function testUrnFromUrlAcceptsTheHashApiAndDirectShapes(): void {
		$service = $this->service();
		$urn = 'urn:nl-or:cloud-example-com:decidesk:meeting:' . self::UUID;

		$this->assertSame($urn, $service->urnFromUrl(self::BASE . '/apps/openregister/#/registers/1/schemas/2/objects/' . self::UUID));
		$this->assertSame($urn, $service->urnFromUrl(self::BASE . '/index.php/apps/openregister/api/objects/decidesk/meeting/' . strtoupper(self::UUID)));
		$this->assertSame($urn, $service->urnFromUrl(self::BASE . '/apps/openregister/objects/1/meeting/' . self::UUID));
	}

	public function testUrnFromUrlRefusesOtherHostsBadUuidsTrailingPathsAndUnknownRefs(): void {
		$service = $this->service();

		$this->assertNull($service->urnFromUrl('https://other.example/apps/openregister/objects/1/2/' . self::UUID));
		$this->assertNull($service->urnFromUrl(self::BASE . '/apps/openregister/objects/1/2/not-a-uuid'));
		$this->assertNull($service->urnFromUrl(self::BASE . '/apps/openregister/objects/1/2/' . self::UUID . '/extra'));
		$this->assertNull($service->urnFromUrl(self::BASE . '/apps/openregister/objects/missing/2/' . self::UUID));
	}

	public function testResolveBulkSkipsBlanksAndNonStringsAndMapsEachUrn(): void {
		$good = 'urn:nl-or:cloud-example-com:1:2:' . self::UUID;

		$this->assertSame(
			[$good => self::BASE . '/apps/openregister/api/objects/decidesk/meeting/' . self::UUID, 'garbage' => null],
			$this->service()->resolveBulk([$good, '', 5, 'garbage', $good])
		);
	}
}
