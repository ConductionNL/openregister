<?php

/**
 * Golden test pinning what ManifestService exposes and withholds.
 *
 * Pins, on the code as it was before its units were split, both directions:
 * the profile fields that DO reach runtime.user (allowlisted, default-safe,
 * materialised calculations, evaluated calculations) and the ones that are
 * REFUSED (any other stored field, invalid slugs, anonymous users, failed
 * lookups). Also pins that the profile lookup keeps RBAC and multitenancy on
 * and that a rejected slug or anonymous request never reaches a lookup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\ManifestService;
use OCA\OpenRegister\Service\ManifestSources;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Golden outcomes of ManifestService::getEnrichedManifest().
 */
class ManifestServiceAccessGoldenTest extends TestCase {

	/**
	 * Arguments every findAll() call received.
	 *
	 * @var list<array>
	 */
	private array $findAllCalls = [];

	/**
	 * Arguments every findBySlug() call received.
	 *
	 * @var list<array>
	 */
	private array $findBySlugCalls = [];

	/**
	 * Expressions the evaluator received, with the data map.
	 *
	 * @var list<array>
	 */
	private array $evaluated = [];

	/**
	 * Build the service.
	 *
	 * @param string|null           $uid      Logged-in user id, null for anonymous.
	 * @param array|null            $config   Schema configuration; null = no schema found.
	 * @param ObjectEntity|null     $profile  The profile found, or null.
	 * @param array<string, string> $failures Which lookup throws: 'schema', 'profile', 'eval'.
	 *
	 * @return ManifestService
	 */
	private function service(?string $uid, ?array $config, ?ObjectEntity $profile, array $failures=[]): ManifestService {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findBySlug')->willReturnCallback(
			function (...$args) use ($config, $failures): array {
				$this->findBySlugCalls[] = [$args[0], $args[1]->limit, $args[1]->offset ?? 0, $args[2], $args[3]];
				if (isset($failures['schema']) === true) {
					throw new RuntimeException('schema down');
				}

				if ($config === null) {
					return [];
				}

				$schema = $this->createMock(Schema::class);
				$schema->method('getConfiguration')->willReturn($config);
				return [$schema];
			}
		);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (...$args) use ($profile, $failures): array {
				$this->findAllCalls[] = $args;
				if (isset($failures['profile']) === true) {
					throw new RuntimeException('objects down');
				}

				return $profile === null ? [] : [$profile];
			}
		);

		$evaluator = $this->createMock(CalculationEvaluatorInterface::class);
		$evaluator->method('evaluate')->willReturnCallback(
			function (array $data, mixed $expr) use ($failures): mixed {
				$this->evaluated[] = [$data, $expr];
				if (isset($failures['eval']) === true && $expr === $failures['eval']) {
					throw new EvaluationException('bad');
				}

				if ($expr === 'date') {
					return new DateTime('2020-01-02T03:04:05+00:00');
				}

				return 'calc:' . (string) $expr;
			}
		);

		return new ManifestService(new ManifestSources($objects, $schemas), $evaluator, $session, new NullLogger());
	}//end service()

	/**
	 * A profile entity carrying PII next to safe fields.
	 *
	 * @return ObjectEntity
	 */
	private function profile(): ObjectEntity {
		$profile = new ObjectEntity();
		$profile->setObject(
			[
				'id'          => 'stored-id',
				'roles'       => ['editor'],
				'email'       => 'secret@example.com',
				'bsn'         => '123456782',
				'primaryRole' => 'officer',
				'stored'      => 'materialised-value',
				'7'           => 'numeric-key',
			]
		);
		$profile->setUuid('profile-uuid');
		$profile->setRegister('reg-1');
		$profile->setSchema('schema-1');
		$profile->setOwner('alice');
		$profile->setCreated(new DateTime('2020-01-01T00:00:00+00:00'));
		return $profile;
	}//end profile()

	/**
	 * Run the service for a manifest naming the given slug.
	 *
	 * @param ManifestService $service The service.
	 * @param mixed           $slug    The currentUserSchema value.
	 *
	 * @return array The enriched manifest.
	 */
	private function enrich(ManifestService $service, mixed $slug='user-profile'): array {
		return $service->getEnrichedManifest(['currentUserSchema' => $slug, 'pages' => ['p']]);
	}//end enrich()

	/**
	 * ALLOWED: allowlisted, default-safe, materialised and evaluated fields reach runtime.user;
	 * REFUSED: every other stored field (PII) is withheld.
	 *
	 * @return void
	 */
	public function testExposesOnlyAllowedFields(): void {
		$config = [
			'x-openregister-manifest-user-fields' => ['primaryRole', '', 42, 'missing'],
			'x-openregister-calculations'         => [
				'stored'  => ['materialise' => true, 'expression' => 'stored'],
				'shown'   => ['materialise' => false, 'expression' => 'shown'],
				'when'    => ['expression' => 'date'],
				'broken'  => ['expression' => 'broken'],
				'skipped' => 'not-an-array',
				7         => ['materialise' => true, 'expression' => 'seven'],
			],
		];
		$result = $this->enrich(service: $this->service(uid: 'alice', config: $config, profile: $this->profile(), failures: ['eval' => 'broken']));

		$this->assertSame(
			[
				'id'          => 'alice',
				'roles'       => ['editor'],
				'primaryRole' => 'officer',
				'stored'      => 'materialised-value',
				'shown'       => 'calc:shown',
				'when'        => '2020-01-02T03:04:05+00:00',
			],
			$result['runtime']['user']
		);
		$this->assertSame(['p'], $result['pages']);
	}//end testExposesOnlyAllowedFields()

	/**
	 * The evaluator sees the full profile plus @self metadata, in calculation order.
	 *
	 * @return void
	 */
	public function testEvaluatorReceivesProfileWithSelfMeta(): void {
		$config = ['x-openregister-calculations' => ['a' => ['expression' => 'x']]];
		$this->enrich(service: $this->service(uid: 'alice', config: $config, profile: $this->profile()));

		$this->assertCount(1, $this->evaluated);
		[$data, $expr] = $this->evaluated[0];
		$this->assertSame('x', $expr);
		$this->assertSame('secret@example.com', $data['email']);
		$this->assertSame(
			[
				'id'       => 'profile-uuid',
				'uuid'     => 'profile-uuid',
				'register' => 'reg-1',
				'schema'   => 'schema-1',
				'owner'    => 'alice',
				'created'  => '2020-01-01T00:00:00+00:00',
				'updated'  => null,
			],
			$data['@self']
		);
	}//end testEvaluatorReceivesProfileWithSelfMeta()

	/**
	 * Without an allowlist or calculations only the default-safe fields surface.
	 *
	 * @return void
	 */
	public function testNoAllowlistExposesDefaultsOnly(): void {
		$result = $this->enrich(service: $this->service(uid: 'alice', config: [], profile: $this->profile()));

		$this->assertSame(['id' => 'alice', 'roles' => ['editor']], $result['runtime']['user']);
		$this->assertSame([], $this->evaluated);
	}//end testNoAllowlistExposesDefaultsOnly()

	/**
	 * The profile lookup is narrowed to the user and keeps RBAC and multitenancy on.
	 *
	 * @return void
	 */
	public function testProfileLookupKeepsRbacAndTenancy(): void {
		$this->enrich(service: $this->service(uid: 'alice', config: [], profile: $this->profile()));

		$this->assertSame(
			[[['limit' => 1, 'filters' => ['schema' => 'user-profile', 'ncUserId' => 'alice']], true, true]],
			$this->findAllCalls
		);
		foreach ($this->findBySlugCalls as $call) {
			$this->assertSame(['user-profile', 1, 0, true, true], $call);
		}
	}//end testProfileLookupKeepsRbacAndTenancy()

	/**
	 * Slugs that are rejected before any lookup.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalidSlugs(): array {
		return [
			'too long'     => [str_repeat('a', 129)],
			'bad charset'  => ['user profile'],
			'injection'    => ["user\0profile"],
			'path'         => ['../etc'],
			'not a string' => [['user-profile']],
			'integer'      => [5],
		];
	}//end invalidSlugs()

	/**
	 * REFUSED: an invalid slug yields a null user without any lookup.
	 *
	 * @param mixed $slug The slug.
	 *
	 * @dataProvider invalidSlugs
	 *
	 * @return void
	 */
	public function testInvalidSlugIsRefusedBeforeLookup(mixed $slug): void {
		$result = $this->enrich(service: $this->service(uid: 'alice', config: [], profile: $this->profile()), slug: $slug);

		$this->assertArrayHasKey('user', $result['runtime']);
		$this->assertNull($result['runtime']['user']);
		$this->assertSame([], $this->findAllCalls);
		$this->assertSame([], $this->findBySlugCalls);
	}//end testInvalidSlugIsRefusedBeforeLookup()

	/**
	 * ALLOWED: the longest valid slug passes validation.
	 *
	 * @return void
	 */
	public function testMaximumLengthSlugIsAccepted(): void {
		$slug   = str_repeat('a', 128);
		$result = $this->enrich(service: $this->service(uid: 'alice', config: null, profile: null), slug: $slug);

		$this->assertSame(['id' => 'alice', 'roles' => ['learner']], $result['runtime']['user']);
	}//end testMaximumLengthSlugIsAccepted()

	/**
	 * REFUSED: an anonymous request gets a null user without any lookup.
	 *
	 * @return void
	 */
	public function testAnonymousIsRefusedBeforeLookup(): void {
		$result = $this->enrich(service: $this->service(uid: null, config: [], profile: $this->profile()));

		$this->assertNull($result['runtime']['user']);
		$this->assertSame([], $this->findAllCalls);
		$this->assertSame([], $this->findBySlugCalls);
	}//end testAnonymousIsRefusedBeforeLookup()

	/**
	 * Manifests without a usable slug are returned unchanged.
	 *
	 * @return void
	 */
	public function testNoSlugLeavesManifestUnchanged(): void {
		$service = $this->service(uid: 'alice', config: [], profile: $this->profile());

		$this->assertSame(['pages' => []], $service->getEnrichedManifest(['pages' => []]));
		$this->assertSame(['currentUserSchema' => null], $service->getEnrichedManifest(['currentUserSchema' => null]));
		$this->assertSame(['currentUserSchema' => ''], $service->getEnrichedManifest(['currentUserSchema' => '']));
		$this->assertSame([], $this->findAllCalls);
	}//end testNoSlugLeavesManifestUnchanged()

	/**
	 * Failed or empty lookups fall back to the minimal context, never to profile data.
	 *
	 * @return void
	 */
	public function testFailedLookupsFallBack(): void {
		$fallback = ['id' => 'alice', 'roles' => ['learner']];

		$this->assertSame($fallback, $this->enrich(service: $this->service(uid: 'alice', config: null, profile: $this->profile()))['runtime']['user']);
		$this->assertSame([], $this->findAllCalls, 'no schema, no profile lookup');

		$failSchema = $this->service(uid: 'alice', config: [], profile: $this->profile(), failures: ['schema' => 'x']);
		$this->assertSame($fallback, $this->enrich(service: $failSchema)['runtime']['user']);

		$failProfile = $this->service(uid: 'alice', config: [], profile: $this->profile(), failures: ['profile' => 'x']);
		$this->assertSame($fallback, $this->enrich(service: $failProfile)['runtime']['user']);

		$this->assertSame($fallback, $this->enrich(service: $this->service(uid: 'alice', config: [], profile: null))['runtime']['user']);
	}//end testFailedLookupsFallBack()
}//end class
