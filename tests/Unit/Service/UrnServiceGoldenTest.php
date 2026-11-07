<?php

/**
 * Golden test for UrnService.
 *
 * Runs build / parse / resolve / reverse-lookup calls against recording
 * mappers, URL generator and app config, per scenario, and records each
 * result plus every collaborator and logger call in
 * fixtures/urn-service-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\UrnService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\UrnService
 */
class UrnServiceGoldenTest extends TestCase {

	private const UUID = '1C1C970F-D50C-4943-8128-78999E240EEC';

	/**
	 * Ordered call log.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * Scenarios: [options (config, base), calls].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scenarios(): array {
		$u = self::UUID;
		$lu = strtolower(self::UUID);
		$base = 'https://Cloud.Example.com';
		$urn = "urn:nl-or:cloud-example-com:decidesk:meeting:{$lu}";
		return [
			'build and instance from host' => [[], [['build', 'DeciDesk', 'Meeting', $u], ['instance']]],
			'instance from config' => [['config' => ' My Stable_ID! '], [['instance'], ['build', 'r', 's', 'u']]],
			'instance config throws' => [['config' => 'throws'], [['instance']]],
			'instance url throws' => [['base' => 'throws'], [['instance']]],
			'instance url without host' => [['base' => '/relative'], [['instance']]],
			'instance host all symbols' => [['base' => 'https://---'], [['instance']]],
			'build for object' => [[], [['object', $u, '1', '2'], ['object', $u, 'decidesk', 'meeting']]],
			'build for object incomplete' => [[], [['object', '', '1', '2'], ['object', null, '1', '2'], ['object', $u, '', '2'], ['object', $u, '1', null], ['object', $u, 'missing', '2'], ['object', $u, '1', 'broken']]],
			'parse' => [[], [['parse', $urn], ['parse', 'URN:NL-OR:a:b:c:d:e'], ['parse', 'urn:nl-or:a:b:c'], ['parse', 'urn:other:a:b:c:d'], ['parse', 'not a urn'], ['parse', 'urn:x:a:b:c:d'], ['parse', 'urn:nl-or:::::']]],
			'resolve url' => [[], [['resolve', $urn], ['resolve', 'urn:nl-or:elsewhere:decidesk:meeting:x'], ['resolve', 'urn:nl-or:cloud-example-com:missing:meeting:x'], ['resolve', 'urn:nl-or:cloud-example-com:decidesk:broken:x'], ['resolve', 'garbage'], ['resolve', 'urn:nl-or:cloud-example-com:1:2:a b/c']]],
			'urn from url' => [[], [
				['fromUrl', "{$base}/apps/openregister/#/registers/1/schemas/2/objects/{$lu}"],
				['fromUrl', "{$base}/index.php/apps/openregister/api/objects/decidesk/meeting/{$u}"],
				['fromUrl', "{$base}/apps/openregister/objects/1/meeting/{$lu}"],
				['fromUrl', "{$base}/apps/openregister/objects/missing/meeting/{$lu}"],
				['fromUrl', "{$base}/apps/openregister/objects/1/broken/{$lu}"],
				['fromUrl', "{$base}/apps/openregister/objects/1/2/not-a-uuid"],
				['fromUrl', "https://other.example/apps/openregister/objects/1/2/{$lu}"],
				['fromUrl', "{$base}/apps/openregister/objects/1/2/{$lu}/extra"],
			]],
			'resolve bulk' => [[], [['bulk', [$urn, '', 5, 'garbage', $urn]]]],
		];
	}//end scenarios()

	/**
	 * @dataProvider scenarios
	 *
	 * @param array<string, mixed>     $options Scenario options.
	 * @param array<int, array<mixed>> $calls   Calls to run.
	 *
	 * @return void
	 */
	public function testUrnServiceMatchesGolden(array $options, array $calls): void {
		$this->log = [];
		$options = array_merge(['config' => '', 'base' => 'https://Cloud.Example.com'], $options);
		$service = new UrnService(
			$this->registerMapper(),
			$this->schemaMapper(),
			$this->urlGenerator($options['base']),
			$this->appConfig($options['config']),
			$this->logger()
		);

		$results = [];
		foreach ($calls as $call) {
			$results[] = $this->runCall($service, $call);
		}

		$actual = ['results' => $results, 'log' => $this->log];
		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);

		$file = __DIR__.'/fixtures/urn-service-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $actual);
	}//end testUrnServiceMatchesGolden()

	/**
	 * Run one call.
	 *
	 * @param UrnService        $service The service.
	 * @param array<int, mixed> $call    The call.
	 *
	 * @return mixed
	 */
	private function runCall(UrnService $service, array $call): mixed {
		$this->log[] = ['call', $call];
		try {
			return match ($call[0]) {
				'build' => $service->build($call[1], $call[2], $call[3]),
				'instance' => $service->getInstanceSlug(),
				'object' => $service->buildForObject($this->object($call[1], $call[2], $call[3])),
				'parse' => $service->parse($call[1]),
				'resolve' => $service->resolveUrl($call[1]),
				'fromUrl' => $service->urnFromUrl($call[1]),
				'bulk' => $service->resolveBulk($call[1]),
			};
		} catch (Throwable $e) {
			return ['exception' => get_class($e), 'message' => $e->getMessage()];
		}
	}//end runCall()

	/**
	 * Build an object with the given identity.
	 *
	 * @param string|null $uuid     The uuid.
	 * @param string|null $register The register ref.
	 * @param string|null $schema   The schema ref.
	 *
	 * @return ObjectEntity
	 */
	private function object(?string $uuid, ?string $register, ?string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister($register);
		$object->setSchema($schema);
		return $object;
	}//end object()

	/**
	 * Lookup behaviour shared by both mappers: "missing" does not exist, "broken" throws.
	 *
	 * @param string $kind The mapper kind.
	 * @param mixed  $ref  The reference.
	 * @param array  $rest Remaining positional/named arguments.
	 *
	 * @return string The slug for the reference.
	 */
	private function lookup(string $kind, mixed $ref, array $rest): string {
		$this->log[] = [$kind.'.find', $ref, $rest];
		if ($ref === 'missing') {
			throw new DoesNotExistException('not found');
		}

		if ($ref === 'broken') {
			throw new RuntimeException('db down');
		}

		$slugs = ['register' => ['1' => 'DeciDesk'], 'schema' => ['2' => 'meeting']];
		return ($slugs[$kind][(string)$ref] ?? (string)$ref);
	}//end lookup()

	/**
	 * Register mapper fake.
	 *
	 * @return RegisterMapperInterface
	 */
	private function registerMapper(): RegisterMapperInterface {
		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function ($id, ...$rest): Register {
				$register = new Register();
				$register->setSlug($this->lookup('register', $id, $rest));
				return $register;
			}
		);
		return $mapper;
	}//end registerMapper()

	/**
	 * Schema mapper fake.
	 *
	 * @return SchemaMapperInterface
	 */
	private function schemaMapper(): SchemaMapperInterface {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function ($id, ...$rest): Schema {
				$schema = new Schema();
				$schema->setSlug($this->lookup('schema', $id, $rest));
				return $schema;
			}
		);
		return $mapper;
	}//end schemaMapper()

	/**
	 * URL generator prefixing a base.
	 *
	 * @param string $base The base url, or "throws".
	 *
	 * @return IURLGenerator
	 */
	private function urlGenerator(string $base): IURLGenerator {
		$generator = $this->createMock(IURLGenerator::class);
		$generator->method('getAbsoluteURL')->willReturnCallback(
			function (string $url) use ($base): string {
				$this->log[] = ['url.getAbsoluteURL', $url];
				if ($base === 'throws') {
					throw new RuntimeException('no url');
				}

				return $base.$url;
			}
		);
		return $generator;
	}//end urlGenerator()

	/**
	 * App config returning the instance override.
	 *
	 * @param string $value The configured value, or "throws".
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $value): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (...$args) use ($value): string {
				$this->log[] = ['config.getValueString', $args];
				if ($value === 'throws') {
					throw new RuntimeException('no config');
				}

				return $value;
			}
		);
		return $config;
	}//end appConfig()

	/**
	 * Logger recording every call.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					$this->log[] = ['log.'.$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
