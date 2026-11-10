<?php

/**
 * Golden test for the translatable group-key projection of AggregationRunner.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Object\TranslationScope;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\ResultGrouping;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Each case hands projectTranslatableGroupKeys() an envelope and a group
 * spec, with `title` and `label` translatable. The returned envelope and
 * every translation call are compared with
 * fixtures/translatable-group-keys-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite it.
 */
class AggregationRunnerTranslatableKeysGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/translatable-group-keys-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/**
	 * The cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$map = ['nl' => 'Hallo', 'en' => 'Hello'];
		$json = '{"nl":"Doei","en":"Bye"}';
		$single = ['groupBy' => ['field' => 'title']];
		$one = ['groups' => [['key' => $map, 'value' => 1]]];
		return [
			'no groups' => [['envelope' => ['value' => 3]] + $single],
			'groups not array' => [['envelope' => ['groups' => 'x']] + $single],
			'no group spec' => [['envelope' => $one, 'groupBy' => null]],
			'empty group spec' => [['envelope' => $one, 'groupBy' => []]],
			'not translatable' => [['envelope' => $one, 'groupBy' => ['field' => 'status']]],
			'all translations' => [['envelope' => $one, 'all' => true] + $single],
			'single keys' => [
				[
					'envelope' => [
						'backend' => 'php-fallback',
						'groups' => [
							['key' => $map, 'value' => 1],
							['key' => $json, 'value' => 2],
							['key' => 'plain', 'value' => 3],
							['key' => '[1,2]', 'value' => 4],
							['key' => null, 'value' => 5],
							['key' => ['de' => 'Tschuess'], 'value' => 6],
							'not a row',
							['value' => 7],
						],
						'cached' => true,
					],
				] + $single,
			],
			'composite keys' => [
				[
					'envelope' => [
						'groups' => [
							['keys' => ['status' => 'open', 'title' => $map, 'label' => $json], 'value' => 1],
							['keys' => ['status' => 'done', 'title' => 'plain'], 'value' => 2],
							['keys' => 'x', 'key' => $map, 'value' => 3],
						],
					],
					'groupBy' => ['fields' => ['status', 'title', 'label']],
				],
			],
			'composite non translatable first' => [['envelope' => $one, 'groupBy' => ['fields' => ['status', 'title']]]],
			'list group spec' => [['envelope' => ['groups' => [['key' => $map, 'values' => ['count' => 1]]]], 'groupBy' => ['label']]],
		];
	}//end cases()

	#[DataProvider('cases')]
	public function testProjectionMatchesTheGolden(array $case): void {
		$name = (string)$this->dataName();
		$actual = $this->observe(case: $case);
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'no golden recorded for ' . $name);
		$this->assertSame(json_encode($golden[$name]), json_encode($actual));
	}//end testProjectionMatchesTheGolden()

	/**
	 * Project the case's envelope and record the result and every translation call.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(array $case): array {
		$this->calls = [];
		$register = new Register();
		$register->setSlug('reg');
		$schema = new Schema();
		$schema->setSlug('things');
		$method = new ReflectionMethod(AggregationRunner::class, 'projectTranslatableGroupKeys');
		$grouping = new ResultGrouping(table: new RegisterSchema(register: $register, schema: $schema), groupBy: $case['groupBy']);
		$result   = $method->invoke($this->runner(case: $case), $case['envelope'], $grouping);
		return ['result' => $result, 'calls' => $this->calls];
	}//end observe()

	/**
	 * A runner whose translation collaborators record their calls.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return AggregationRunner
	 */
	private function runner(array $case): AggregationRunner {
		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('getTranslatableProperties')->willReturnCallback(
			function (Schema $schema): array {
				$this->calls[] = ['translatable' => $schema->getSlug()];
				return ['title', 'label'];
			}
		);
		$translations->method('resolveTranslationsForRender')->willReturnCallback(
			function (array $objectData, TranslationScope $scope): array {
				$this->calls[] = ['resolve' => [$objectData, $scope->schema->getSlug(), $scope->register?->getSlug()]];
				$resolved = [];
				foreach ($objectData as $field => $value) {
					if (is_array($value) === true && isset($value['nl']) === true) {
						$resolved[$field] = $value['nl'];
					}
				}

				return $resolved;
			}
		);

		$languages = $this->createMock(LanguageService::class);
		$languages->method('shouldReturnAllTranslations')->willReturnCallback(
			function () use ($case): bool {
				$this->calls[] = ['allTranslations' => true];
				return ($case['all'] ?? false);
			}
		);

		$session = $this->createMock(IUserSession::class);
		return new AggregationRunner(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapper::class)),
			placeholders: new PlaceholderResolver($session),
			db: $this->createMock(IDBConnection::class),
			cache: $this->createMock(AggregationCache::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			userSession: $session,
			organisationService: $this->createMock(OrganisationService::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			translationHandler: $translations,
			languageService: $languages
		);
	}//end runner()
}//end class
