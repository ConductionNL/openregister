<?php

/**
 * Golden test for SemanticTypeResolver.
 *
 * Runs a sequence of resolveSchemaByImplements / findRegisterForSchema /
 * clearCache calls over a fixed schema + register set, per scenario, and
 * records each result plus every mapper, app-manager and logger call in
 * fixtures/semantic-type-resolver-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\SemanticTypeResolver;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\SemanticTypeResolver
 */
class SemanticTypeResolverGoldenTest extends TestCase {

	private const ORG = 'https://schema.org/Organization';

	private const PERSON = 'https://schema.org/Person';

	private const THING = 'https://schema.org/Thing';

	/**
	 * Ordered call log.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * Schema definitions: [id|null, slug, uuid, application, configuration, allOf].
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private static function schemaRows(): array {
		return [
			[1, 'org-a', 'u1', 'appa', ['jsonld' => ['type' => self::ORG]], null],
			[2, 'org-b', 'u2', null, ['implements' => [self::ORG, 'not-absolute', self::ORG]], []],
			[3, '', 'u3', '', ['x-schema-org' => 'schema:Thing'], [1, '', 7.5, ['x']]],
			[4, 'person', 'u4', null, ['x-schema-org' => ['schema:Person', 'schema:', 'https://example.org/P', 3]], [5, 'missing']],
			[5, 'person-child', 'u5', null, [], [4]],
			[6, 'disabled-org', 'u6', 'offapp', ['implements' => [self::ORG]], null],
			[7, 'throwy-org', 'u7', 'throwapp', ['implements' => [self::ORG]], null],
			[8, 'doublethrow-org', 'u8', 'doublethrow', ['implements' => [self::ORG]], null],
			[9, 'core-thing', 'u9', 'openregister', ['implements' => [self::THING]], null],
			[10, '', '', null, ['implements' => [self::THING]], ['org-a']],
			[11, 'reg-app-person', 'u11', null, ['implements' => [self::PERSON]], null],
			[null, 'no-id', 'u-none', null, ['implements' => ['https://schema.org/Event']], ['org-a']],
		];
	}//end schemaRows()

	/**
	 * Register definitions: [id, application, schema ids].
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private static function registerRows(): array {
		return [
			[10, 'appb', [2, '3']],
			[20, null, [4, 5]],
			[30, 'offapp', [11, 9]],
			[40, '', [10, 1]],
		];
	}//end registerRows()

	/**
	 * Scenarios: [mode flags, call list].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scenarios(): array {
		$org = ['resolve', self::ORG, null];
		return [
			'org twice without consumer' => [[], [$org, $org]],
			'org biased to consumer 10' => [[], [['resolve', self::ORG, 10]]],
			'org consumer 40' => [[], [['resolve', self::ORG, 40]]],
			'org consumer unknown' => [[], [['resolve', self::ORG, 99]]],
			'person through allOf cycle' => [[], [['resolve', self::PERSON, null]]],
			'thing candidates' => [[], [['resolve', self::THING, null], ['resolve', self::THING, 40]]],
			'event via self allOf' => [[], [['resolve', 'https://schema.org/Event', null]]],
			'empty uri' => [[], [['resolve', '', null]]],
			'unknown uri cached' => [[], [['resolve', 'https://schema.org/None', null], ['resolve', 'https://schema.org/None', null]]],
			'clear cache re-enumerates' => [[], [$org, ['clear'], $org]],
			'schema enumeration fails' => [['schemasThrow'], [$org, $org]],
			'register enumeration fails' => [['registersThrow'], [$org, ['resolve', self::PERSON, null], ['register', 3]]],
			'no app manager' => [['noAppManager'], [$org, ['resolve', self::PERSON, null]]],
			'find register for schemas' => [[], [['register', 3], ['register', 5], ['register', 12], ['register', 1]]],
		];
	}//end scenarios()

	/**
	 * @dataProvider scenarios
	 *
	 * @param array<int, string>       $modes Mode flags.
	 * @param array<int, array<mixed>> $calls Calls to run in order.
	 *
	 * @return void
	 */
	public function testResolverMatchesGolden(array $modes, array $calls): void {
		$this->log = [];
		$schemas = $this->schemas();
		$resolver = new SemanticTypeResolver(
			$this->schemaMapper($schemas, in_array('schemasThrow', $modes, true)),
			$this->registerMapper(in_array('registersThrow', $modes, true)),
			new JsonLdContextService($this->createMock(IURLGenerator::class)),
			$this->logger(),
			in_array('noAppManager', $modes, true) ? null : $this->appManager()
		);

		$results = [];
		foreach ($calls as $call) {
			$results[] = $this->runCall($resolver, $call, $schemas);
		}

		$actual = ['results' => $results, 'log' => $this->log];
		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);

		$file = __DIR__.'/fixtures/semantic-type-resolver-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $actual);
	}//end testResolverMatchesGolden()

	/**
	 * Run one call and describe its outcome.
	 *
	 * @param SemanticTypeResolver  $resolver The resolver.
	 * @param array<int, mixed>     $call     The call.
	 * @param array<string, Schema> $schemas  Schemas by slug-or-id key.
	 *
	 * @return mixed
	 */
	private function runCall(SemanticTypeResolver $resolver, array $call, array $schemas): mixed {
		$this->log[] = ['call', $call];
		if ($call[0] === 'clear') {
			$resolver->clearCache();
			return 'cleared';
		}

		if ($call[0] === 'register') {
			$register = $resolver->findRegisterForSchema($this->schemaById($schemas, $call[1]));
			return $register === null ? null : $register->getId();
		}

		$schema = $resolver->resolveSchemaByImplements($call[1], $call[2]);
		return $schema === null ? null : [$schema->getId(), $schema->getSlug()];
	}//end runCall()

	/**
	 * Build the schema set, keyed by id (or slug when id-less).
	 *
	 * @return array<string, Schema>
	 */
	private function schemas(): array {
		$out = [];
		foreach (self::schemaRows() as [$id, $slug, $uuid, $app, $configuration, $allOf]) {
			$schema = new Schema();
			if ($id !== null) {
				$schema->setId($id);
			}

			$schema->setSlug($slug);
			$schema->setUuid($uuid);
			$schema->setApplication($app);
			$schema->setConfiguration($configuration);
			$schema->setAllOf($allOf);
			$out[(string)($id ?? $slug)] = $schema;
		}

		return $out;
	}//end schemas()

	/**
	 * A schema by id, or a fresh orphan schema with that id.
	 *
	 * @param array<string, Schema> $schemas The schema set.
	 * @param int                   $id      The id.
	 *
	 * @return Schema
	 */
	private function schemaById(array $schemas, int $id): Schema {
		if (isset($schemas[(string)$id]) === true) {
			return $schemas[(string)$id];
		}

		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}//end schemaById()

	/**
	 * Schema mapper fake.
	 *
	 * @param array<string, Schema> $schemas The schema set.
	 * @param bool                  $throws  Whether findAll throws.
	 *
	 * @return SchemaMapperInterface
	 */
	private function schemaMapper(array $schemas, bool $throws): SchemaMapperInterface {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('findAll')->willReturnCallback(
			function () use ($schemas, $throws): array {
				$this->log[] = ['schemas.findAll'];
				if ($throws === true) {
					throw new RuntimeException('schema db down');
				}

				return array_values($schemas);
			}
		);
		$mapper->method('find')->willReturnCallback(
			function ($id) use ($schemas): Schema {
				$this->log[] = ['schemas.find', $id];
				foreach ($schemas as $schema) {
					if ((string)$schema->getId() === (string)$id || $schema->getSlug() === $id) {
						return $schema;
					}
				}

				throw new RuntimeException('schema '.$id.' not found');
			}
		);
		return $mapper;
	}//end schemaMapper()

	/**
	 * Register mapper fake.
	 *
	 * @param bool $throws Whether findAll throws.
	 *
	 * @return RegisterMapperInterface
	 */
	private function registerMapper(bool $throws): RegisterMapperInterface {
		$registers = [];
		foreach (self::registerRows() as [$id, $app, $schemaIds]) {
			$register = new Register();
			$register->setId($id);
			$register->setApplication($app);
			$register->setSchemas($schemaIds);
			$registers[(string)$id] = $register;
		}

		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->method('findAll')->willReturnCallback(
			function () use ($registers, $throws): array {
				$this->log[] = ['registers.findAll'];
				if ($throws === true) {
					throw new RuntimeException('register db down');
				}

				return array_values($registers);
			}
		);
		$mapper->method('find')->willReturnCallback(
			function ($id) use ($registers): Register {
				$this->log[] = ['registers.find', $id];
				if (isset($registers[(string)$id]) === false) {
					throw new RuntimeException('register '.$id.' not found');
				}

				return $registers[(string)$id];
			}
		);
		return $mapper;
	}//end registerMapper()

	/**
	 * App manager fake: offapp disabled, throwapp throws then not installed, doublethrow throws twice.
	 *
	 * @return IAppManager
	 */
	private function appManager(): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(
			function ($appId): bool {
				$this->log[] = ['apps.isEnabledForUser', $appId];
				if ($appId === 'throwapp' || $appId === 'doublethrow') {
					throw new RuntimeException('no user');
				}

				return $appId !== 'offapp';
			}
		);
		$apps->method('isInstalled')->willReturnCallback(
			function ($appId): bool {
				$this->log[] = ['apps.isInstalled', $appId];
				if ($appId === 'doublethrow') {
					throw new RuntimeException('no install state');
				}

				return false;
			}
		);
		return $apps;
	}//end appManager()

	/**
	 * Logger recording every call without file/line context.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->log[] = ['log.'.$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
