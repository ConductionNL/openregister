<?php

/**
 * OrganisationMapperTenancyGoldenTest — pins the tenancy decisions of the organisation mapper.
 *
 * Covers the allowed and refused outcomes of validateParentAssignment(), the merge
 * resolution of resolveMergeTarget() (including its fail-closed cycle and hop-limit
 * stops), the insert/update split and refusals of save(), the depth calculation used by
 * the hierarchy limit, and the preference read behind getActiveOrganisationUuidForUser().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Exception\OrganisationSlugConflictException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\OrganisationMapper
 */
final class OrganisationMapperTenancyGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const A = '11111111-1111-4111-8111-111111111111';
	private const B = '22222222-2222-4222-8222-222222222222';
	private const C = '33333333-3333-4333-8333-333333333333';
	private const D = '44444444-4444-4444-8444-444444444444';

	/**
	 * Log lines written by the mapper under test, as "level message".
	 *
	 * @var array<int, string>
	 */
	private array $logLines = [];

	/**
	 * Removing a parent is always allowed, without any lookup.
	 *
	 * @return void
	 */
	public function testANullParentIsAllowedWithoutLookups(): void {
		$mapper = $this->stubbedMapper();
		$mapper->validateParentAssignment(self::A, null);
		$this->assertSame([], $mapper->calls);
	}//end testANullParentIsAllowedWithoutLookups()

	/**
	 * An organisation may not be its own parent; nothing is looked up.
	 *
	 * @return void
	 */
	public function testSelfParentIsRefused(): void {
		$mapper = $this->stubbedMapper();
		$this->assertRefused(
			fn () => $mapper->validateParentAssignment(self::A, self::A),
			'Organisation cannot be its own parent.'
		);
		$this->assertSame([], $mapper->calls);
	}//end testSelfParentIsRefused()

	/**
	 * A parent that cannot be loaded is refused, whatever the lookup threw.
	 *
	 * @return void
	 */
	public function testAnUnloadableParentIsRefused(): void {
		foreach ([new DoesNotExistException('x'), new MultipleObjectsReturnedException('x'), new RuntimeException('db')] as $thrown) {
			$mapper = $this->stubbedMapper();
			$mapper->organisations[self::B] = $thrown;
			$this->assertRefused(
				fn () => $mapper->validateParentAssignment(self::A, self::B),
				'Parent organisation not found.'
			);
			$this->assertSame(['findByUuid ' . self::B], $mapper->calls);
		}
	}//end testAnUnloadableParentIsRefused()

	/**
	 * A parent that already descends from the organisation is refused.
	 *
	 * @return void
	 */
	public function testACircularParentIsRefused(): void {
		$mapper = $this->stubbedMapper();
		$mapper->organisations[self::B] = $this->organisation(self::B);
		$mapper->parentChains[self::B] = [self::C, self::A];
		$this->assertRefused(
			fn () => $mapper->validateParentAssignment(self::A, self::B),
			'Circular reference detected: The new parent organisation is already a descendant of this organisation.'
		);
		$this->assertSame(['findByUuid ' . self::B, 'findParentChain ' . self::B], $mapper->calls);
	}//end testACircularParentIsRefused()

	/**
	 * The circular check compares loosely, as in_array() without strict did.
	 *
	 * @return void
	 */
	public function testTheCircularCheckIsLoose(): void {
		$mapper = $this->stubbedMapper();
		$mapper->organisations['7'] = $this->organisation('7');
		$mapper->parentChains['7'] = [5];
		$this->assertRefused(
			fn () => $mapper->validateParentAssignment('5', '7'),
			'Circular reference detected: The new parent organisation is already a descendant of this organisation.'
		);
	}//end testTheCircularCheckIsLoose()

	/**
	 * A valid parent passes and is logged with the computed depth.
	 *
	 * @return void
	 */
	public function testAValidParentIsAllowedAndLogged(): void {
		$mapper = $this->stubbedMapper();
		$mapper->organisations[self::B] = $this->organisation(self::B);
		$mapper->organisations[self::C] = $this->organisation(self::C, self::A);
		$mapper->organisations[self::D] = $this->organisation(self::D, self::C);
		$mapper->parentChains[self::B] = [self::D . 'x'];
		$mapper->childrenChains[self::A] = [self::C, self::D];
		$mapper->validateParentAssignment(self::A, self::B);
		$this->assertSame(
			[
				'findByUuid ' . self::B,
				'findParentChain ' . self::B,
				'findChildrenChain ' . self::A,
				'findByUuid ' . self::C,
				'findByUuid ' . self::D,
			],
			$mapper->calls
		);
		$this->assertSame(['debug [OrganisationMapper] Parent assignment validated'], $this->logLines);
		$this->assertSame(4, $mapper->lastDebugContext['totalDepth']);
		$this->assertSame([self::D . 'x'], $mapper->lastDebugContext['parentChain']);
	}//end testAValidParentIsAllowedAndLogged()

	/**
	 * Depth exactly at the limit is allowed; one more is refused with the total in the message.
	 *
	 * @return void
	 */
	public function testTheDepthLimitBoundary(): void {
		$atLimit = $this->stubbedMapper();
		$atLimit->organisations[self::B] = $this->organisation(self::B);
		$atLimit->parentChains[self::B] = array_fill(0, 9, 'p');
		$atLimit->validateParentAssignment(self::A, self::B);
		$this->assertSame(10, $atLimit->lastDebugContext['totalDepth']);

		$over = $this->stubbedMapper();
		$over->organisations[self::B] = $this->organisation(self::B);
		$over->organisations[self::C] = $this->organisation(self::C, self::A);
		$over->parentChains[self::B] = array_fill(0, 9, 'p');
		$over->childrenChains[self::A] = [self::C];
		$this->assertRefused(
			fn () => $over->validateParentAssignment(self::A, self::B),
			'Maximum hierarchy depth exceeded. Total depth would be 11 levels (max 10 allowed).'
		);
	}//end testTheDepthLimitBoundary()

	/**
	 * The depth below a root: unloadable and parentless children count as zero, the
	 * walk stops at the root and is capped at twenty.
	 *
	 * @return void
	 */
	public function testMaxDepthInChain(): void {
		$depth = new ReflectionMethod(OrganisationMapper::class, 'getMaxDepthInChain');

		$mapper = $this->stubbedMapper();
		$this->assertSame(0, $depth->invoke($mapper, [], self::A));
		$this->assertSame([], $mapper->calls);

		$mapper = $this->stubbedMapper();
		$mapper->organisations[self::B] = $this->organisation(self::B, self::A);
		$mapper->organisations[self::C] = $this->organisation(self::C, self::B);
		$mapper->organisations[self::D] = new DoesNotExistException('gone');
		$mapper->organisations['x'] = $this->organisation('x');
		$this->assertSame(2, $depth->invoke($mapper, [self::B, self::C, self::D, 'x'], self::A));
		$this->assertSame(
			['findByUuid ' . self::B, 'findByUuid ' . self::C, 'findByUuid ' . self::D, 'findByUuid x'],
			$mapper->calls
		);

		$cycle = $this->stubbedMapper();
		$cycle->organisations[self::B] = $this->organisation(self::B, self::C);
		$cycle->organisations[self::C] = $this->organisation(self::C, self::B);
		$this->assertSame(20, $depth->invoke($cycle, [self::B, self::C], self::A));

		$rootReached = $this->stubbedMapper();
		$rootReached->organisations[self::B] = $this->organisation(self::B, self::A);
		$rootReached->organisations[self::A] = $this->organisation(self::A, self::D);
		$this->assertSame(1, $depth->invoke($rootReached, [self::B, self::A], self::A));
	}//end testMaxDepthInChain()

	/**
	 * Merge resolution follows the chain and stops on everything it cannot verify.
	 *
	 * @return void
	 */
	public function testResolveMergeTarget(): void {
		$mapper = $this->stubbedMapper();
		$mapper->organisations[self::A] = $this->organisation(self::A, null, self::B);
		$mapper->organisations[self::B] = $this->organisation(self::B, null, self::C);
		$mapper->organisations[self::C] = $this->organisation(self::C);
		$this->assertSame(self::C, $mapper->resolveMergeTarget(self::A));
		$this->assertSame([], $this->logLines);

		// Unresolvable start and unresolvable link stay on the last verified UUID.
		$missing = $this->stubbedMapper();
		$missing->organisations[self::A] = new DoesNotExistException('x');
		$this->assertSame(self::A, $missing->resolveMergeTarget(self::A));
		$missing->organisations[self::B] = $this->organisation(self::B, null, self::D);
		$missing->organisations[self::D] = new MultipleObjectsReturnedException('x');
		$this->assertSame(self::D, $missing->resolveMergeTarget(self::B));

		// Any other failure is not swallowed.
		$broken = $this->stubbedMapper();
		$broken->organisations[self::A] = new RuntimeException('db down');
		try {
			$broken->resolveMergeTarget(self::A);
			$this->fail('expected the lookup failure to propagate');
		} catch (RuntimeException $e) {
			$this->assertSame('db down', $e->getMessage());
		}

		// Empty and self-pointing merges end the walk.
		$ends = $this->stubbedMapper();
		$ends->organisations[self::A] = $this->organisation(self::A, null, '');
		$ends->organisations[self::B] = $this->organisation(self::B, null, self::B);
		$this->assertSame(self::A, $ends->resolveMergeTarget(self::A));
		$this->assertSame(self::B, $ends->resolveMergeTarget(self::B));
		$this->assertSame([], $this->logLines);
	}//end testResolveMergeTarget()

	/**
	 * A merge cycle stops on the current node with a warning.
	 *
	 * @return void
	 */
	public function testResolveMergeTargetStopsOnACycle(): void {
		$mapper = $this->stubbedMapper();
		$mapper->organisations[self::A] = $this->organisation(self::A, null, self::B);
		$mapper->organisations[self::B] = $this->organisation(self::B, null, self::C);
		$mapper->organisations[self::C] = $this->organisation(self::C, null, self::A);
		$this->assertSame(self::C, $mapper->resolveMergeTarget(self::A));
		$this->assertSame(['warning Organisation merge chain contains a cycle; stopping resolution'], $this->logLines);
		$this->assertSame(['organisation' => self::A, 'at' => self::C], $mapper->lastWarningContext);
	}//end testResolveMergeTargetStopsOnACycle()

	/**
	 * A chain longer than the hop limit stops after sixteen loads with a warning.
	 *
	 * @return void
	 */
	public function testResolveMergeTargetStopsAtTheHopLimit(): void {
		$mapper = $this->stubbedMapper();
		for ($i = 0; $i < 20; $i++) {
			$mapper->organisations['n' . $i] = $this->organisation('n' . $i, null, 'n' . ($i + 1));
		}

		$this->assertSame('n16', $mapper->resolveMergeTarget('n0'));
		$this->assertCount(16, $mapper->calls);
		$this->assertSame(['warning Organisation merge chain exceeded the hop limit; stopping resolution'], $this->logLines);
		$this->assertSame(['organisation' => 'n0', 'at' => 'n16'], $mapper->lastWarningContext);
	}//end testResolveMergeTargetStopsAtTheHopLimit()

	/**
	 * A new organisation gets a UUID and is inserted; a taken slug is refused before the insert.
	 *
	 * @return void
	 */
	public function testSaveInsertsANewOrganisation(): void {
		$mapper = $this->stubbedMapper();
		$organisation = new Organisation();
		$organisation->setSlug('free');
		$saved = $mapper->save($organisation);
		$this->assertSame($organisation, $saved);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$organisation->getUuid());
		$this->assertNotNull($organisation->getCreated());
		$this->assertNotNull($organisation->getUpdated());
		$this->assertSame(['findBySlug free', 'insert'], $mapper->calls);

		$noSlug = $this->stubbedMapper();
		$noSlug->save(new Organisation());
		$this->assertSame(['insert'], $noSlug->calls);

		$taken = $this->stubbedMapper();
		$taken->slugs['used'] = $this->organisation(self::B);
		$organisation = new Organisation();
		$organisation->setSlug('used');
		try {
			$taken->save($organisation);
			$this->fail('expected a slug conflict');
		} catch (OrganisationSlugConflictException $e) {
			$this->assertNull($e->getPrevious());
		}

		$this->assertSame(['findBySlug used'], $taken->calls);
	}//end testSaveInsertsANewOrganisation()

	/**
	 * An insert failure is re-checked for a slug race, otherwise logged and rethrown.
	 *
	 * @return void
	 */
	public function testSaveInsertFailure(): void {
		$race = $this->stubbedMapper();
		$failure = new Exception('unique violation');
		$race->insertFailure = $failure;
		$race->slugsAfterInsert['raced'] = $this->organisation(self::B);
		$organisation = new Organisation();
		$organisation->setSlug('raced');
		try {
			$race->save($organisation);
			$this->fail('expected a slug conflict');
		} catch (OrganisationSlugConflictException $e) {
			$this->assertSame($failure, $e->getPrevious());
		}

		$this->assertSame([], $this->logLines);

		$plain = $this->stubbedMapper();
		$plain->insertFailure = $failure;
		$organisation = new Organisation();
		$organisation->setSlug('fine');
		try {
			$plain->save($organisation);
			$this->fail('expected the insert failure');
		} catch (Exception $e) {
			$this->assertSame($failure, $e);
		}

		$this->assertSame(['findBySlug fine', 'insert', 'findBySlug fine'], $plain->calls);
		$this->assertSame(['error [OrganisationMapper] insert() failed: unique violation'], $this->logLines);
	}//end testSaveInsertFailure()

	/**
	 * An existing organisation is updated, keeps its created date and never checks the slug.
	 *
	 * @return void
	 */
	public function testSaveUpdatesAnExistingOrganisation(): void {
		$mapper = $this->stubbedMapper();
		$organisation = $this->organisation(self::A);
		$organisation->setId(9);
		$organisation->setSlug('used');
		$mapper->slugs['used'] = $this->organisation(self::B);
		$mapper->save($organisation);
		$this->assertNull($organisation->getCreated());
		$this->assertNotNull($organisation->getUpdated());
		$this->assertSame(['uuidExists ' . self::A . ' 9', 'update'], $mapper->calls);

		// An existing organisation without a UUID gets one.
		$noUuid = $this->stubbedMapper();
		$organisation = new Organisation();
		$organisation->setId(4);
		$noUuid->save($organisation);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$organisation->getUuid());
		$this->assertSame(['update'], $noUuid->calls);
	}//end testSaveUpdatesAnExistingOrganisation()

	/**
	 * An invalid or duplicate UUID is refused before anything is written.
	 *
	 * @return void
	 */
	public function testSaveRefusesABadUuid(): void {
		$mapper = $this->stubbedMapper();
		$organisation = new Organisation();
		$organisation->setUuid('not-a-uuid');
		$this->assertRefused(
			fn () => $mapper->save($organisation),
			'Invalid UUID format. UUID must be a valid RFC 4122 UUID.'
		);
		$this->assertSame([], $mapper->calls);

		$dupe = $this->stubbedMapper();
		$dupe->existingUuids[self::A] = true;
		$organisation = new Organisation();
		$organisation->setUuid(self::A);
		$this->assertRefused(
			fn () => $dupe->save($organisation),
			'UUID already exists. Please use a different UUID.'
		);
		$this->assertSame(['uuidExists ' . self::A . ' NULL'], $dupe->calls);
	}//end testSaveRefusesABadUuid()

	/**
	 * The active organisation is read from preferences once per user and memoised.
	 *
	 * @return void
	 */
	public function testActiveOrganisationIsReadFromPreferences(): void {
		$mapper = $this->recordingMapper();
		$this->queuedRows = [[['configvalue' => self::A]], [], [['other' => 'x']]];
		$this->assertSame(self::A, $mapper->getActiveOrganisationUuidForUser('alice'));
		$this->assertSame(self::A, $mapper->getActiveOrganisationUuidForUser('alice'));
		$this->assertNull($mapper->getActiveOrganisationUuidForUser('bob'));
		$this->assertNull($mapper->getActiveOrganisationUuidForUser('bob'));
		$this->assertNull($mapper->getActiveOrganisationUuidForUser('carol'));
		$this->assertSame(
			[
				'#1 select(configvalue)',
				'#1 from(preferences, NULL)',
				'#1 param :p1 = alice, 2',
				'#1 expr.eq(userid, :p1, NULL)',
				'#1 where(eq(userid, :p1, NULL))',
				'#1 param :p2 = openregister, 2',
				'#1 expr.eq(appid, :p2, NULL)',
				'#1 andWhere(eq(appid, :p2, NULL))',
				'#1 param :p3 = active_organisation, 2',
				'#1 expr.eq(configkey, :p3, NULL)',
				'#1 andWhere(eq(configkey, :p3, NULL))',
				'#1 executeQuery()',
				'#2 select(configvalue)',
				'#2 from(preferences, NULL)',
				'#2 param :p4 = bob, 2',
				'#2 expr.eq(userid, :p4, NULL)',
				'#2 where(eq(userid, :p4, NULL))',
				'#2 param :p5 = openregister, 2',
				'#2 expr.eq(appid, :p5, NULL)',
				'#2 andWhere(eq(appid, :p5, NULL))',
				'#2 param :p6 = active_organisation, 2',
				'#2 expr.eq(configkey, :p6, NULL)',
				'#2 andWhere(eq(configkey, :p6, NULL))',
				'#2 executeQuery()',
				'#3 select(configvalue)',
				'#3 from(preferences, NULL)',
				'#3 param :p7 = carol, 2',
				'#3 expr.eq(userid, :p7, NULL)',
				'#3 where(eq(userid, :p7, NULL))',
				'#3 param :p8 = openregister, 2',
				'#3 expr.eq(appid, :p8, NULL)',
				'#3 andWhere(eq(appid, :p8, NULL))',
				'#3 param :p9 = active_organisation, 2',
				'#3 expr.eq(configkey, :p9, NULL)',
				'#3 andWhere(eq(configkey, :p9, NULL))',
				'#3 executeQuery()',
			],
			$this->queryLog
		);
	}//end testActiveOrganisationIsReadFromPreferences()

	/**
	 * A failed preference read returns null, is logged and is not memoised.
	 *
	 * @return void
	 */
	public function testAFailedPreferenceReadIsNotMemoised(): void {
		$attempts = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$attempts): IQueryBuilder {
				$attempts++;
				$qb = $this->recordingBuilder();
				if ($attempts === 1) {
					// Rebuild so executeQuery throws on the first attempt only.
					$failing = $this->createMock(IQueryBuilder::class);
					foreach (['select', 'from', 'where', 'andWhere'] as $name) {
						$failing->method($name)->willReturnSelf();
					}

					$failing->method('expr')->willReturn($qb->expr());
					$failing->method('createNamedParameter')->willReturn(':p');
					$failing->method('executeQuery')->willThrowException(new Exception('db gone'));
					return $failing;
				}

				return $qb;
			}
		);
		$mapper = new OrganisationMapper(
			$db,
			$this->recordingLogger(),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IConfig::class)
		);
		$this->queuedRows = [[['configvalue' => self::B]]];
		$this->assertNull($mapper->getActiveOrganisationUuidForUser('alice'));
		$this->assertSame(['warning [OrganisationMapper] Failed to get active organisation for user: db gone'], $this->logLines);
		$this->assertSame(self::B, $mapper->getActiveOrganisationUuidForUser('alice'));
		$this->assertSame(2, $attempts);
	}//end testAFailedPreferenceReadIsNotMemoised()

	/**
	 * Assert the callable throws a plain Exception with this message.
	 *
	 * @param callable $call The call.
	 * @param string $message The expected message.
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, string $message): void {
		try {
			$call();
		} catch (Exception $e) {
			$this->assertSame(Exception::class, get_class($e));
			$this->assertSame($message, $e->getMessage());
			return;
		}

		$this->fail('expected a refusal: ' . $message);
	}//end assertRefused()

	/**
	 * An organisation with a UUID, parent and merge target.
	 *
	 * @param string $uuid The UUID.
	 * @param string|null $parent The parent UUID.
	 * @param string|null $mergedInto The merge target.
	 *
	 * @return Organisation
	 */
	private function organisation(string $uuid, ?string $parent = null, ?string $mergedInto = null): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setParent($parent);
		$organisation->setMergedInto($mergedInto);
		return $organisation;
	}//end organisation()

	/**
	 * A logger that records "level message" lines into $logLines.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		$lines = &$this->logLines;
		return new class($lines) extends AbstractLogger {
			/**
			 * Context of the last debug line.
			 *
			 * @var array<string, mixed>
			 */
			public array $debugContext = [];

			/**
			 * Context of the last warning line.
			 *
			 * @var array<string, mixed>
			 */
			public array $warningContext = [];

			/**
			 * @param array<int, string> $lines The shared line log.
			 */
			public function __construct(
				private array &$lines,
			) {
			}

			/**
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = $level . ' ' . $message;
				if ($level === 'debug') {
					$this->debugContext = $context;
				}

				if ($level === 'warning') {
					$this->warningContext = $context;
				}
			}
		};
	}//end recordingLogger()

	/**
	 * A mapper on a recording connection.
	 *
	 * @return OrganisationMapper
	 */
	private function recordingMapper(): OrganisationMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new OrganisationMapper(
			$db,
			$this->recordingLogger(),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IConfig::class)
		);
	}//end recordingMapper()

	/**
	 * A mapper whose finders and writes are served from in-memory tables.
	 *
	 * @return OrganisationMapper The mapper, with public stub tables and a call log.
	 */
	private function stubbedMapper(): OrganisationMapper {
		$this->logLines = [];
		$logger = $this->recordingLogger();

		return new class($this->createMock(IDBConnection::class), $logger, $this->createMock(IEventDispatcher::class), $this->createMock(IAppConfig::class), $this->createMock(IConfig::class)) extends OrganisationMapper {
			/** @var array<int, string> */
			public array $calls = [];

			/** @var array<string, Organisation|\Throwable> */
			public array $organisations = [];

			/** @var array<string, Organisation> */
			public array $slugs = [];

			/** @var array<string, Organisation> */
			public array $slugsAfterInsert = [];

			/** @var array<string, array<int, mixed>> */
			public array $parentChains = [];

			/** @var array<string, array<int, mixed>> */
			public array $childrenChains = [];

			/** @var array<string, bool> */
			public array $existingUuids = [];

			/** @var Exception|null */
			public ?Exception $insertFailure = null;

			/** @var array<string, mixed> */
			public array $lastDebugContext = [];

			/** @var array<string, mixed> */
			public array $lastWarningContext = [];

			/** @var AbstractLogger */
			private AbstractLogger $recordingLogger;

			/**
			 * @param IDBConnection $db Connection.
			 * @param AbstractLogger $logger Logger.
			 * @param IEventDispatcher $dispatcher Dispatcher.
			 * @param IAppConfig $appConfig App config.
			 * @param IConfig $config Config.
			 */
			public function __construct(IDBConnection $db, AbstractLogger $logger, IEventDispatcher $dispatcher, IAppConfig $appConfig, IConfig $config) {
				parent::__construct($db, $logger, $dispatcher, $appConfig, $config);
				$this->recordingLogger = $logger;
			}

			public function findByUuid(string $uuid): Organisation {
				$this->calls[] = 'findByUuid ' . $uuid;
				$this->syncContexts();
				$found = $this->organisations[$uuid] ?? new DoesNotExistException('missing');
				if ($found instanceof \Throwable) {
					throw $found;
				}

				return $found;
			}

			public function findBySlug(string $slug): Organisation {
				$this->calls[] = 'findBySlug ' . $slug;
				if (isset($this->slugs[$slug]) === true) {
					return $this->slugs[$slug];
				}

				throw new DoesNotExistException('no slug');
			}

			public function findParentChain(string $organisationUuid): array {
				$this->calls[] = 'findParentChain ' . $organisationUuid;
				return $this->parentChains[$organisationUuid] ?? [];
			}

			public function findChildrenChain(string $organisationUuid): array {
				$this->calls[] = 'findChildrenChain ' . $organisationUuid;
				return $this->childrenChains[$organisationUuid] ?? [];
			}

			public function uuidExists(string $uuid, ?int $excludeId = null): bool {
				$this->calls[] = 'uuidExists ' . $uuid . ' ' . var_export($excludeId, true);
				return $this->existingUuids[$uuid] ?? false;
			}

			public function insert(Entity $entity): Entity {
				$this->calls[] = 'insert';
				if ($this->insertFailure !== null) {
					$this->slugs = $this->slugsAfterInsert;
					throw $this->insertFailure;
				}

				return $entity;
			}

			public function update(Entity $entity): Entity {
				$this->calls[] = 'update';
				return $entity;
			}

			/**
			 * Copy the last logged contexts into public fields.
			 *
			 * @return void
			 */
			public function syncContexts(): void {
				$this->lastDebugContext = $this->recordingLogger->debugContext;
				$this->lastWarningContext = $this->recordingLogger->warningContext;
			}

			public function validateParentAssignment(string $organisationUuid, ?string $newParentUuid): void {
				parent::validateParentAssignment($organisationUuid, $newParentUuid);
				$this->syncContexts();
			}

			public function resolveMergeTarget(string $organisationUuid): string {
				$result = parent::resolveMergeTarget($organisationUuid);
				$this->syncContexts();
				return $result;
			}
		};
	}//end stubbedMapper()
}//end class
