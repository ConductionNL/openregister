<?php

/**
 * The draft expiry job, reached from where OpenRegister registers its jobs.
 *
 * A job with a full test suite and no registration never runs. So the tests
 * here do not name the job class: they read `appinfo/info.xml`, take the job
 * that file declares, build THAT class and run it down to the delete.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-one-background-job-must-remove-drafts-past-their-schemas-lifetime
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\DraftExpiry;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Decision 216: declared, daily, counted, and a failure surfaces.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\DraftExpiryJob
  * @uses \OCA\OpenRegister\Service\Object\DraftExpiry
 * @uses \OCA\OpenRegister\Service\Object\DraftRemovalRecord
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\AuditTrail
 */
class DraftExpiryJobTest extends TestCase {

	/**
	 * The one job class `appinfo/info.xml` declares whose constructor takes a DraftExpiry.
	 *
	 * Found by what the job is wired to, not by its name, so renaming the
	 * class without the declaration (or the other way round) fails here.
	 *
	 * @return class-string<TimedJob> The declared class.
	 */
	private function declaredJobClass(): string {
		$info = simplexml_load_string((string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml'));
		$this->assertNotFalse($info, 'appinfo/info.xml could not be read');

		$found = [];
		foreach ($info->{'background-jobs'}->job as $job) {
			$class = trim((string)$job);
			if (class_exists($class) === false) {
				continue;
			}

			$constructor = (new ReflectionClass($class))->getConstructor();
			foreach (($constructor?->getParameters() ?? []) as $parameter) {
				$type = $parameter->getType();
				if ($type instanceof ReflectionNamedType && $type->getName() === DraftExpiry::class) {
					$found[] = $class;
				}
			}
		}

		$this->assertCount(1, $found, 'appinfo/info.xml must declare exactly one background job that runs the draft expiry; without it a draft lifetime is never acted on.');
		$this->assertTrue(is_subclass_of($found[0], TimedJob::class), 'The declared draft expiry job is not a timed job.');

		return $found[0];
	}//end declaredJobClass()

	/**
	 * Build the declared job the way the container does: by its constructor's types.
	 *
	 * @param array<class-string, object> $services The services to hand in.
	 */
	private function build(string $class, array $services): TimedJob {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type, 'A constructor parameter without one named type cannot be autowired.');
			$this->assertArrayHasKey($type->getName(), $services, 'The declared job asks for ' . $type->getName() . ', which this test does not provide.');
			$arguments[$parameter->getName()] = $services[$type->getName()];
		}

		return new $class(...$arguments);
	}//end build()

	/**
	 * A clock fixed at 12 October 2026, 03:00 UTC.
	 */
	private function clock(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-12T03:00:00+00:00'));

		return $time;
	}//end clock()

	/**
	 * Run the protected run().
	 */
	private function runJob(TimedJob $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * From the registration to the delete: the declared job removes an expired draft of a schema that sets a lifetime.
	 */
	public function testTheDeclaredJobRemovesAnExpiredDraft(): void {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('ticket');
		$schema->setConfiguration(['draftLifetimeDays' => 7]);
		$kept = new Schema();
		$kept->setId(2);
		$kept->setSlug('case');

		$register = new Register();
		$register->setId(10);
		$register->setSlug('service');
		$register->setSchemas([1, 2]);

		$expired = new ObjectEntity();
		$expired->setUuid('expired');
		$expired->setStatus('draft');
		$expired->setUpdated(new DateTime('2026-10-01T00:00:00+00:00'));
		$fresh = new ObjectEntity();
		$fresh->setUuid('fresh');
		$fresh->setStatus('draft');
		$fresh->setUpdated(new DateTime('2026-10-11T00:00:00+00:00'));

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$register]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(static fn (string|int $id): Schema => [1 => $schema, 2 => $kept][(int)$id]);

		$deleted = [];
		$actors = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(
			static fn (array $query = []): array => $query['@self']['schema'] === '1' ? [$expired, $fresh] : []
		);
		// The sweep reads each object again just before deleting it.
		$objects->method('find')->willReturnCallback(
			static fn (string|int $id): ?ObjectEntity => (array_values(array_filter([$expired, $fresh], static fn (ObjectEntity $entity): bool => $entity->getUuid() === (string)$id))[0] ?? null)
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid) use (&$deleted, &$actors): bool {
				$deleted[] = $uuid;
				// What the real delete path does for a draft: ask the removal record to write its entry.
				$audit = $this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class);
				$audit->method('createAuditTrailEntry')->willReturnCallback(
					static function (ObjectEntity $object, string $action, array $context = [], ?string $actorId = null) use (&$actors): \OCA\OpenRegister\Db\AuditTrail {
						$actors[] = $actorId;

						return new \OCA\OpenRegister\Db\AuditTrail();
					}
				);
				(new \OCA\OpenRegister\Service\Object\DraftRemovalRecord(auditTrail: $audit, logger: $this->createMock(LoggerInterface::class)))->write(draft: new ObjectEntity(), register: null, schema: null);

				return true;
			}
		);

				// One line for the schema that lost a draft, then the totals (decision 228).
		$logged = [];
		$levels = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$logged, &$levels, $level): void {
					$logged[] = [$message, $context];
					$levels[] = $level;
				}
			);
		}

		$job = $this->build(
			class: $this->declaredJobClass(),
			services: [
				ITimeFactory::class => $this->clock(),
				LoggerInterface::class => $logger,
				DraftExpiry::class => new DraftExpiry(objects: $objects, registers: $registers, schemas: $schemas, logger: $this->createMock(LoggerInterface::class)),
			]
		);

		$this->assertSame(86400, $job->getInterval());
		$this->runJob(job: $job);

				$this->assertSame(['expired'], $deleted);
		// The removal ran under the job's name, so its audit-trail entry says which job removed the draft (decision 226).
		$this->assertSame(['job:DraftExpiryJob'], $actors);
		$this->assertCount(2, $logged);
		$this->assertStringContainsString('drafts past the lifetime of a schema', $logged[0][0]);
		$this->assertSame(
			['register' => 'service', 'schema' => 'ticket', 'lifetimeDays' => 7, 'expired' => 1, 'removed' => 1, 'refused' => 0, 'deferred' => 0],
			$logged[0][1]
		);
		// The second schema sets nothing, so it gets 30 days: looked at, nothing expired, no line of its own.
		$this->assertStringContainsString('swept drafts', $logged[1][0]);
		$this->assertSame(['schemas' => 2, 'expired' => 1, 'removed' => 1, 'refused' => 0, 'deferred' => 0], $logged[1][1]);
		// A sweep that removed a draft is logged where the default log level (warning) shows it.
		$this->assertSame(['warning', 'warning'], $levels);
	}//end testTheDeclaredJobRemovesAnExpiredDraft()

	/**
	 * A sweep that finds nothing still logs its counts: a counted zero is not a job that never ran.
	 */
	public function testAZeroIsLogged(): void {
		$expiry = $this->createMock(DraftExpiry::class);
		$expiry->expects($this->once())->method('sweep')->willReturn(['schemas' => 0, 'expired' => 0, 'removed' => 0, 'refused' => 0, 'deferred' => 0, 'perSchema' => []]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->stringContains('swept drafts'),
			$this->equalTo(['schemas' => 0, 'expired' => 0, 'removed' => 0, 'refused' => 0, 'deferred' => 0])
		);

		$job = $this->build(
			class: $this->declaredJobClass(),
			services: [ITimeFactory::class => $this->clock(), LoggerInterface::class => $logger, DraftExpiry::class => $expiry]
		);
		$this->runJob(job: $job);
	}//end testAZeroIsLogged()

	/**
	 * A failing sweep is logged as an error and rethrown, so the job does not report success.
	 */
	public function testAFailingSweepSurfaces(): void {
		$expiry = $this->createMock(DraftExpiry::class);
		$expiry->method('sweep')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$logger->expects($this->never())->method('info');

		$job = $this->build(
			class: $this->declaredJobClass(),
			services: [ITimeFactory::class => $this->clock(), LoggerInterface::class => $logger, DraftExpiry::class => $expiry]
		);

		$this->expectException(RuntimeException::class);
		$this->runJob(job: $job);
	}//end testAFailingSweepSurfaces()

	/**
	 * Drafts that were removed or refused are logged at warning, so the default log level shows them; a zero stays at info.
	 */
	public function testARefusalIsLoggedWhereTheDefaultLevelShowsIt(): void {
		$expiry = $this->createMock(DraftExpiry::class);
		$expiry->method('sweep')->willReturn(
			[
				'schemas' => 1, 'expired' => 2, 'removed' => 0, 'refused' => 2, 'deferred' => 0,
				'perSchema' => [['register' => 'service', 'schema' => 'ticket', 'lifetimeDays' => 7, 'expired' => 2, 'removed' => 0, 'refused' => 2, 'deferred' => 0]],
			]
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');
		$logger->expects($this->never())->method('info');

		$job = $this->build(
			class: $this->declaredJobClass(),
			services: [ITimeFactory::class => $this->clock(), LoggerInterface::class => $logger, DraftExpiry::class => $expiry]
		);
		$this->runJob(job: $job);
	}//end testARefusalIsLoggedWhereTheDefaultLevelShowsIt()
}//end class
