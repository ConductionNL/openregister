<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\AggregationThresholdJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\ThresholdEvaluationService;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deferred threshold evaluation: schema reload + fresh evaluation per live
 * entry; stale entries no-op; per-entry failures logged not fatal.
 */
class AggregationThresholdJobTest extends TestCase {
	private IUserManager&MockObject $userManager;
	private DeferredEntryObjectResolver&MockObject $resolver;
	private SchemaMapper&MockObject $schemaMapper;
	private ThresholdEvaluationService&MockObject $evaluator;
	private LoggerInterface&MockObject $logger;
	private AggregationThresholdJob $job;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->resolver = $this->createMock(DeferredEntryObjectResolver::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->evaluator = $this->createMock(ThresholdEvaluationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new AggregationThresholdJob(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->userManager,
			organisation: $this->createMock(OrganisationService::class),
			logger: $this->logger,
			resolver: $this->resolver,
			schemaMapper: $this->schemaMapper,
			evaluator: $this->evaluator,
		);
	}

	private function runJob(array $entries): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->willReturn($user);

		$argument = (new DeferredListenerContext(userId: 'alice', orgUuid: null, entries: $entries))
			->toJobArguments();

		$method = (new \ReflectionClass($this->job))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, $argument);
	}

	public function testEvaluatesTheEntrySchemaAgainstTheRefetchedObject(): void {
		$object = new ObjectEntity();
		$object->setUuid('u1');
		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('test-schema');

		$this->resolver->method('resolve')->willReturn($object);
		$this->schemaMapper->expects($this->once())->method('find')
			->with('test-schema')->willReturn($schema);
		$this->evaluator->expects($this->once())->method('evaluateSchema')
			->with($schema, $object);

		$this->runJob([['uuid' => 'u1', 'register' => 'r', 'schema' => 'test-schema']]);
	}

	public function testStaleEntrySkipsEvaluation(): void {
		$this->resolver->method('resolve')->willReturn(null);
		$this->schemaMapper->expects($this->never())->method('find');
		$this->evaluator->expects($this->never())->method('evaluateSchema');

		$this->runJob([['uuid' => 'gone', 'register' => 'r', 'schema' => 's']]);
	}

	public function testSchemaLookupFailureIsLoggedAndDoesNotAbortTheChunk(): void {
		$objectA = new ObjectEntity();
		$objectA->setUuid('a');
		$objectB = new ObjectEntity();
		$objectB->setUuid('b');
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('ok-schema');

		$this->resolver->method('resolve')->willReturnOnConsecutiveCalls($objectA, $objectB);
		$this->schemaMapper->method('find')->willReturnCallback(
			function (string $ref) use ($schema): Schema {
				if ($ref === 'broken-schema') {
					throw new \RuntimeException('gone');
				}

				return $schema;
			}
		);
		$this->logger->expects($this->once())->method('warning');
		$this->evaluator->expects($this->once())->method('evaluateSchema')
			->with($schema, $objectB);

		$this->runJob([
			['uuid' => 'a', 'register' => 'r', 'schema' => 'broken-schema'],
			['uuid' => 'b', 'register' => 'r', 'schema' => 'ok-schema'],
		]);
	}
}
