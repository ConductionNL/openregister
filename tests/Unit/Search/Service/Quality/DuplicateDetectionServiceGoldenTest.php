<?php

/**
 * Golden test for DuplicateDetectionService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Search\Service\Quality
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Service\Quality\DismissedPairStore;
use OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService;
use OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each case configures a schema annotation, a stored object set and the
 * active dismissals, then runs one public method. The recording holds the
 * result, every findAll query, every dismissal lookup and every log call.
 * Compared with fixtures/duplicate-detection-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Quality\DuplicateDetectionService
 */
class DuplicateDetectionServiceGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/duplicate-detection-golden.json';

	/**
	 * The annotation most cases share.
	 */
	private const ANNOTATION = [
		'matchRules'   => [
			['field' => 'email', 'method' => 'exact', 'weight' => 0.6],
			['field' => 'name', 'method' => 'normalized', 'weight' => '0.2'],
			['field' => 'address.city', 'method' => 'levenshtein'],
			['field' => 'kvk', 'method' => 'exact', 'weight' => 0],
			['field' => 'nickname', 'method' => ''],
			'not a rule',
			['method' => 'exact'],
		],
		'blockingKeys' => ['address.zip', '', 5],
		'threshold'    => '0.5',
		'onCreate'     => 'warn',
	];

	/**
	 * @var array<string, mixed>
	 */
	private static array $golden = [];

	/**
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * The service under test, read back by the dismissal store to fingerprint a current pair.
	 *
	 * @var DuplicateDetectionService|null
	 */
	private ?DuplicateDetectionService $service = null;

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rules = [
			['field' => 'name', 'method' => 'levenshtein'],
			['field' => 'email', 'method' => 'normalized', 'weight' => 2],
		];
		return [
			'sweep with annotation and blocking' => [['call' => 'find']],
			'sweep with caller rules and threshold' => [['call' => 'find', 'rules' => $rules, 'threshold' => 0.3]],
			'sweep, caller rules, no annotation' => [['call' => 'find', 'annotation' => null, 'rules' => $rules]],
			'sweep with no usable rules' => [['call' => 'find', 'annotation' => ['matchRules' => [['field' => 'x']]]]],
			'sweep with non-array rules' => [['call' => 'find', 'annotation' => ['matchRules' => 'email']]],
			'sweep on unresolvable register' => [['call' => 'find', 'register' => 99, 'rules' => $rules]],
			'sweep when the read fails' => [['call' => 'find', 'readFails' => true]],
			'sweep with one object' => [['call' => 'find', 'objects' => ['a1']]],
			'sweep with dismissals' => [['call' => 'find', 'annotation' => null, 'rules' => $rules, 'dismiss' => [
				'a2|a1' => 'current', 'b1|b2' => 'stale', 'x|y' => 'stale',
			]]],
			'sweep with no matching dismissal' => [['call' => 'find', 'annotation' => null, 'rules' => $rules, 'dismiss' => ['x|y' => 'stale']]],
			'sweep with string blocking keys and bad threshold' => [[
				'call'       => 'find',
				'annotation' => ['matchRules' => self::ANNOTATION['matchRules'], 'blockingKeys' => 'zip', 'threshold' => 'high'],
			]],
			'check candidate with blocking' => [['call' => 'check', 'candidate' => [
				'name' => 'acme bv', 'email' => 'INFO@acme.test', 'address' => ['zip' => '1234ab', 'city' => 'Amsterdam'],
			]]],
			'check candidate with empty blocking field' => [['call' => 'check', 'candidate' => ['name' => 'Acme', 'address' => ['zip' => ' ']]]],
			'check candidate without blocking keys' => [['call' => 'check', 'annotation' => null, 'rules' => $rules, 'threshold' => 0.2, 'candidate' => [
				'name' => 'Acme B.V.', 'email' => 'info@acme.test',
			]]],
			'check candidate with no rules' => [['call' => 'check', 'annotation' => null, 'candidate' => ['name' => 'Acme']]],
			'check candidate when the read fails' => [['call' => 'check', 'readFails' => true, 'candidate' => [
				'address' => ['zip' => '1234AB'],
			]]],
			'effective threshold from annotation' => [['call' => 'threshold']],
			'effective threshold override' => [['call' => 'threshold', 'threshold' => 0.7]],
			'effective threshold without rules' => [['call' => 'threshold', 'annotation' => null, 'threshold' => 0.7]],
			'effective rules' => [['call' => 'rules']],
			'effective rules unresolvable' => [['call' => 'rules', 'register' => 99]],
			'dedup annotation' => [['call' => 'annotation']],
			'dedup annotation not an array' => [['call' => 'annotation', 'annotation' => 'yes']],
			'pair fingerprint' => [['call' => 'fingerprint']],
			'pair fingerprint without rules' => [['call' => 'fingerprint', 'fingerprintRules' => [['field' => 'name']]]],
		];
	}

	/**
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testGolden(array $case): void {
		$service = $this->service($case);
		$result = $this->invoke($service, $case);
		$recording = json_decode(json_encode(['result' => $result, 'log' => $this->log]), true);

		$name = (string) $this->dataName();
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$golden[$name] = $recording;
			$this->assertNotSame([], $recording);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertArrayHasKey($name, $expected);
		$this->assertSame($expected[$name], $recording);
	}

	public static function tearDownAfterClass(): void {
		if (getenv('UPDATE_GOLDEN') === '1' && self::$golden !== []) {
			file_put_contents(self::FIXTURE, json_encode(self::$golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
	}

	/**
	 * @param DuplicateDetectionService $service The service.
	 * @param array<string, mixed>      $case    The case.
	 *
	 * @return mixed
	 */
	private function invoke(DuplicateDetectionService $service, array $case): mixed {
		$register = ($case['register'] ?? 1);
		return match ($case['call']) {
			'find' => $service->findDuplicates($register, 1, ($case['rules'] ?? null), ($case['threshold'] ?? null)),
			'check' => $service->checkCandidate($register, 1, $case['candidate'], ($case['rules'] ?? null), ($case['threshold'] ?? null)),
			'threshold' => $service->effectiveThreshold($register, 1, ($case['threshold'] ?? null)),
			'rules' => $service->effectiveRules($register, 1),
			'annotation' => $service->dedupAnnotation($register, 1),
			'fingerprint' => [
				$service->pairFingerprint(self::payloads()['a1'], self::payloads()['a2'], ($case['fingerprintRules'] ?? self::ANNOTATION['matchRules'])),
				$service->pairFingerprint(self::payloads()['a2'], self::payloads()['a1'], ($case['fingerprintRules'] ?? self::ANNOTATION['matchRules'])),
			],
			default => throw new RuntimeException('unknown call'),
		};
	}

	/**
	 * @return array<string, array<string, mixed>|null>
	 */
	private static function payloads(): array {
		return [
			'a1' => ['name' => 'Acme BV', 'email' => 'info@acme.test', 'kvk' => '1', 'address' => ['zip' => '1234 AB', 'city' => 'Amsterdam']],
			'a2' => ['name' => 'ACME  bv', 'email' => 'info@acme.test', 'kvk' => '2', 'address' => ['zip' => '1234ab', 'city' => 'Amsterdm']],
			'a3' => ['name' => 'Acme Holding', 'email' => 'info@acme.test', 'address' => ['zip' => '1234AB', 'city' => 'Rotterdam']],
			'a4' => ['name' => 'Acme BV', 'email' => '', 'address' => ['zip' => '1234AB']],
			'b1' => ['name' => 'Globex', 'email' => 'hi@globex.test', 'address' => ['zip' => '9999 ZZ', 'city' => 'Utrecht']],
			'b2' => ['name' => 'Globex', 'email' => 'hi@globex.test', 'address' => ['zip' => '9999zz', 'city' => 'Utrecht']],
			'c1' => ['name' => 'Initech', 'email' => 'x@initech.test', 'address' => ['zip' => '5555 CC']],
			'n1' => ['name' => 'No Address', 'email' => 'info@acme.test', 'address' => 'n/a'],
			'e1' => null,
		];
	}

	/**
	 * @param array<string, mixed> $case The case.
	 *
	 * @return DuplicateDetectionService
	 */
	private function service(array $case): DuplicateDetectionService {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');
		$register->setSchemas([1]);
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('party');
		$annotation = self::ANNOTATION;
		if (array_key_exists('annotation', $case) === true) {
			$annotation = $case['annotation'];
		}
		$configuration = ['other' => true];
		if ($annotation !== null) {
			$configuration['x-openregister-dedup'] = $annotation;
		}
		$schema->setConfiguration($configuration);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(static function ($id) use ($register) {
			if ($id === 99) {
				throw new DoesNotExistException('gone');
			}
			return $register;
		});
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn($schema);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(function (array $query) use ($case) {
			$this->log[] = ['findAll' => $query];
			if (($case['readFails'] ?? false) === true) {
				throw new RuntimeException('read down');
			}
			return $this->objects($case['objects'] ?? array_keys(self::payloads()));
		});

		$store = $this->createMock(DismissedPairStore::class);
		$store->method('activeFor')->willReturnCallback(function (string $registerSlug, string $schemaSlug) use ($case) {
			$this->log[] = ['activeFor' => [$registerSlug, $schemaSlug]];
			$out = [];
			foreach (($case['dismiss'] ?? []) as $pairKey => $kind) {
				[$first, $second] = explode('|', $pairKey);
				$fingerprint = 'stale';
				if ($kind === 'current' && $this->service !== null) {
					$fingerprint = $this->service->pairFingerprint(self::payloads()[$first], self::payloads()[$second], $case['rules']);
				}
				$out[DismissedPairStore::key($second, $first)] = ['fingerprint' => $fingerprint];
			}
			return $out;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message): void {
			$this->log[] = ['log' => ['warning', (string) $message]];
		});

		$this->service = new DuplicateDetectionService($objectService, $schemaMapper, $registerMapper, new SimilarityCalculator(), $store, $logger);
		return $this->service;
	}

	/**
	 * @param list<string> $uuids The object uuids.
	 *
	 * @return list<mixed>
	 */
	private function objects(array $uuids): array {
		$objects = ['not an entity'];
		foreach ($uuids as $uuid) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setObject(self::payloads()[$uuid]);
			$objects[] = $object;
		}
		return $objects;
	}
}
