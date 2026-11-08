<?php

/**
 * Golden for the tenancy and RBAC outcomes of MappingMapper.
 *
 * Pins, per caller, the queries findAll(), find() and createFromArray() build and
 * whether each call was admitted or refused, so a change to how the mapper is
 * wired to its tenancy services cannot change who may read or write a mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversNothing
 */
final class MappingMapperTenancyGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/mapping-mapper-tenancy-golden.json';

	/**
	 * The callers a call is made as.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function personas(): array {
		return [
			'admin' => ['uid' => 'root', 'admin' => true, 'groups' => ['admin']],
			'granted user' => ['uid' => 'alice', 'admin' => false, 'groups' => ['writers']],
			'reader' => ['uid' => 'carol', 'admin' => false, 'groups' => ['readers']],
			'refused user' => ['uid' => 'bob', 'admin' => false, 'groups' => ['others']],
			'anonymous' => null,
		];
	}//end personas()

	/**
	 * Every outcome matches the recording.
	 *
	 * @return void
	 */
	public function testOutcomesMatchGolden(): void {
		$actual = $this->outcomes(authorization: self::authorization());

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	/**
	 * The golden holds admitted and refused reads and writes, so it can tell them apart.
	 *
	 * @return void
	 */
	public function testGoldenHoldsAllowedAndRefusedOutcomes(): void {
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertArrayNotHasKey('thrown', $expected['create as granted user']);
		$this->assertArrayNotHasKey('thrown', $expected['create as admin']);
		$this->assertArrayHasKey('thrown', $expected['create as refused user']);
		$this->assertArrayHasKey('thrown', $expected['create as reader']);
		$this->assertArrayNotHasKey('thrown', $expected['findAll as reader']);
	}//end testGoldenHoldsAllowedAndRefusedOutcomes()

	/**
	 * Mutation check: withdrawing the create grant turns the admitted write into a refusal.
	 *
	 * @return void
	 */
	public function testWithdrawnGrantChangesTheOutcome(): void {
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$mutated = $this->outcomes(authorization: ['mapping' => ['read' => ['readers', 'writers'], 'create' => ['others']]]);
		$this->assertArrayHasKey('thrown', $mutated['create as granted user']);
		$this->assertNotSame($expected['create as granted user'], json_decode((string)json_encode($mutated['create as granted user']), true));
	}//end testWithdrawnGrantChangesTheOutcome()

	/**
	 * The organisation authorization the golden is recorded under.
	 *
	 * @return array<string, mixed>
	 */
	private static function authorization(): array {
		return ['mapping' => ['read' => ['readers', 'writers'], 'create' => ['writers']]];
	}//end authorization()

	/**
	 * Record every call for every caller.
	 *
	 * @param array<string, mixed> $authorization The active organisation's authorization.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function outcomes(array $authorization): array {
		$actual = [];
		foreach (self::personas() as $who => $persona) {
			$actual['findAll as ' . $who] = $this->recorded(fn () => $this->mapper(persona: $persona, authorization: $authorization)->findAll(limit: 5, offset: 10));
			$actual['find as ' . $who] = $this->recorded(fn () => $this->mapper(persona: $persona, authorization: $authorization)->find(id: 'slug-1', includeNullOrg: false));
			$actual['create as ' . $who] = $this->recorded(fn () => $this->mapper(persona: $persona, authorization: $authorization)->createFromArray(data: ['name' => 'M', 'slug' => 'm', 'uuid' => 'mapping-uuid-1']));
		}

		return $actual;
	}//end outcomes()

	/**
	 * The queries a call made and what it answered or threw.
	 *
	 * @param callable $call The call.
	 *
	 * @return array<string, mixed>
	 */
	private function recorded(callable $call): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [];
		$outcome = [];
		try {
			$result = $call();
			if ($result instanceof Mapping) {
				$outcome['organisation'] = $result->getOrganisation();
			} elseif (is_array($result) === true) {
				$outcome['rows'] = count($result);
			}
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e);
			$outcome['message'] = $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end recorded()

	/**
	 * A mapping mapper for one caller.
	 *
	 * @param array<string, mixed>|null $persona       The caller, null for no session user.
	 * @param array<string, mixed>      $authorization The active organisation's authorization.
	 *
	 * @return MappingMapper
	 */
	private function mapper(?array $persona, array $authorization): MappingMapper {
		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);
		if ($persona !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($persona['uid']);
			$session->method('getUser')->willReturn($user);
			$groups->method('isAdmin')->willReturn($persona['admin']);
			$groups->method('getUserGroupIds')->willReturn($persona['groups']);
		}

		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setAuthorization($authorization);
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('isEntityRbacEnforcementEnabled')->willReturn(true);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');
		$organisations->method('findByUuid')->willReturn($organisation);
		$organisations->method('findParentChain')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new MappingMapper($db, $organisations, $session, $groups, $caches, new NullLogger());
	}//end mapper()
}//end class
