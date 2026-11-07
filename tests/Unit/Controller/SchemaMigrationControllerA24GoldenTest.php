<?php

/**
 * Golden outcomes of the schema migration REST surface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\SchemaRunJob;
use OCA\OpenRegister\Controller\SchemaMigrationController;
use OCA\OpenRegister\Controller\Support\SchemaMigrationControllerServices;
use OCA\OpenRegister\Controller\Support\SchemaMigrationDomain;
use OCA\OpenRegister\Controller\Support\SchemaMigrationMappers;
use OCA\OpenRegister\Controller\Support\SchemaMigrationPlatform;
use OCA\OpenRegister\Db\OutcomeFilter;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaChangelogMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunEntryMapper;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Exception\SchemaRunConcurrencyException;
use OCA\OpenRegister\Service\Schema\PropertyConversionServiceInterface;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Service\Schema\SchemaObjectReader;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins status + body of every SchemaMigrationController action, before it is restructured.
 */
class SchemaMigrationControllerA24GoldenTest extends TestCase {
	/**
	 * The params of the case.
	 *
	 * @var array
	 */
	private array $params = [];
	/**
	 * The schemas of the case.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;
	/**
	 * The changelog of the case.
	 *
	 * @var SchemaChangelogMapper&MockObject
	 */
	private SchemaChangelogMapper&MockObject $changelog;
	/**
	 * The runs of the case.
	 *
	 * @var SchemaRunMapper&MockObject
	 */
	private SchemaRunMapper&MockObject $runs;
	/**
	 * The entries of the case.
	 *
	 * @var SchemaRunEntryMapper&MockObject
	 */
	private SchemaRunEntryMapper&MockObject $entries;
	/**
	 * The revalidation of the case.
	 *
	 * @var SchemaRevalidationService&MockObject
	 */
	private SchemaRevalidationService&MockObject $revalidation;
	/**
	 * The migration of the case.
	 *
	 * @var SchemaMigrationService&MockObject
	 */
	private SchemaMigrationService&MockObject $migration;
	/**
	 * The reader of the case.
	 *
	 * @var SchemaObjectReader&MockObject
	 */
	private SchemaObjectReader&MockObject $reader;
	/**
	 * The jobs of the case.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobs;
	/**
	 * The userSession of the case.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;
	/**
	 * The conversions of the case.
	 *
	 * @var PropertyConversionServiceInterface&MockObject
	 */
	private PropertyConversionServiceInterface&MockObject $conversions;

	private function controller(bool $withConversions = true): SchemaMigrationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->changelog = $this->createMock(SchemaChangelogMapper::class);
		$this->runs = $this->createMock(SchemaRunMapper::class);
		$this->entries = $this->createMock(SchemaRunEntryMapper::class);
		$this->revalidation = $this->createMock(SchemaRevalidationService::class);
		$this->migration = $this->createMock(SchemaMigrationService::class);
		$this->reader = $this->createMock(SchemaObjectReader::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->conversions = $this->createMock(PropertyConversionServiceInterface::class);
		$conversions = null;
		if ($withConversions === true) {
			$conversions = $this->conversions;
		}

		return new SchemaMigrationController(
			'openregister',
			$request,
			new SchemaMigrationControllerServices(
				mappers: new SchemaMigrationMappers($this->schemas, $this->changelog, $this->runs, $this->entries),
				domain: new SchemaMigrationDomain($this->revalidation, $this->migration, $this->reader, $conversions),
				platform: new SchemaMigrationPlatform(jobList: $this->jobs, userSession: $this->userSession, logger: new NullLogger())
			)
		);
	}

	private function missing(): \PHPUnit\Framework\MockObject\Stub\Exception {
		return $this->throwException(new DoesNotExistException('x'));
	}

	private function pair($response): array {
		return [$response->getStatus(), $response->getData()];
	}

	private function aRun(int $id, int $schemaId): SchemaRun {
		$run = new SchemaRun();
		$run->setId($id);
		$run->setSchemaId($schemaId);
		return $run;
	}

	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testConversionsListOrNothing(): void {
		$this->assertSame([200, ['results' => [], 'total' => 0]], $this->pair($this->controller(withConversions: false)->conversions()));

		$controller = $this->controller();
		$this->conversions->method('supported')->willReturn([['from' => 'a'], ['from' => 'b']]);
		$this->assertSame([200, ['results' => [['from' => 'a'], ['from' => 'b']], 'total' => 2]], $this->pair($controller->conversions()));
	}

	public function testPreviewConversionRefusals(): void {
		$unconfigured = $this->controller(withConversions: false)->previewConversion(1);
		$this->assertSame([501, ['error' => 'Property conversion is not configured']], $this->pair($unconfigured));

		$controller = $this->controller();
		$this->reader->method('canReadValues')->willReturnOnConsecutiveCalls(false, true, true, true, true);
		$this->assertSame([501, ['error' => 'Property conversion is not configured']], $this->pair($controller->previewConversion(1)));

		$schema = new Schema();
		$schema->setProperties(['naam' => ['type' => 'integer'], 'los' => 'x']);
		$this->schemas->method('find')->willReturnOnConsecutiveCalls($this->throwException(new DoesNotExistException('x')), $schema, $schema, $schema);
		$this->assertSame([404, ['error' => 'Schema not found']], $this->pair($controller->previewConversion(1)));

		$this->params = ['property' => ' naam ', 'to' => ' '];
		$this->assertSame([422, ['error' => 'Both "property" and "to" are required']], $this->pair($controller->previewConversion(1)));

		$this->params = ['property' => 'los', 'to' => 'string'];
		$this->assertSame([404, ['error' => 'The schema has no property "los"']], $this->pair($controller->previewConversion(1)));

		$this->params = ['property' => 'naam', 'to' => 'boolean'];
		$this->conversions->method('isSupported')->with('integer', 'boolean')->willReturn(false);
		$this->conversions->method('refusalReason')->willReturn('nope');
		$this->assertSame(
			[422, ['error' => 'nope', 'property' => 'naam', 'from' => 'integer', 'to' => 'boolean', 'supported' => false]],
			$this->pair($controller->previewConversion(1))
		);
	}

	public function testPreviewConversionAnswersThePreviewWithItsTriple(): void {
		$controller = $this->controller();
		$this->reader->method('canReadValues')->willReturn(true);
		$schema = new Schema();
		$schema->setProperties(['naam' => []]);
		$this->schemas->method('find')->with(3)->willReturn($schema);
		$this->params = ['property' => 'naam', 'to' => 'integer'];
		$this->conversions->method('isSupported')->with('string', 'integer')->willReturn(true);
		$this->reader->method('storedValues')->with(3, 'naam')->willReturn(['1']);
		$this->conversions->method('preview')->with(['1'], 'string', 'integer')->willReturn(['converts' => 1]);

		$this->assertSame(
			[200, ['converts' => 1, 'property' => 'naam', 'from' => 'string', 'to' => 'integer']],
			$this->pair($controller->previewConversion(3))
		);
	}

	public function testChangelogAndRunsPassNumericPagingOnly(): void {
		$controller = $this->controller();
		$this->params = ['_limit' => '5', '_offset' => 'x'];
		$this->changelog->expects($this->once())->method('findBySchema')->with(3, 5, null)->willReturn([]);
		$this->runs->expects($this->once())->method('findBySchema')->with(3, 5, null)->willReturn([$this->aRun(1, 3)]);

		$this->assertSame([200, ['results' => []]], $this->pair($controller->changelog(3)));
		$this->assertSame([200, ['results' => [$this->aRun(1, 3)->jsonSerialize()]]], $this->pair($controller->runs(3)));
	}

	public function testRevalidateOutcomes(): void {
		$controller = $this->controller();
		$this->signIn();
		$this->schemas->method('find')->willReturnOnConsecutiveCalls($this->missing(), new Schema(), new Schema(), new Schema());
		$this->reader->method('resolveRegisterId')->willReturnOnConsecutiveCalls(null, 9, 9);
		$this->params = ['proposedDefinition' => 'bad'];
		$this->revalidation->method('start')->with(3, 9, null, 'alice')->willReturnOnConsecutiveCalls(
			$this->throwException(new SchemaRunConcurrencyException('busy')),
			$this->aRun(11, 3)
		);
		$this->jobs->expects($this->once())->method('add')->with(SchemaRunJob::class, ['run_id' => 11]);

		$this->assertSame([404, ['error' => 'Schema not found']], $this->pair($controller->revalidate(3)));
		$this->assertSame([422, ['error' => 'No register contains this schema']], $this->pair($controller->revalidate(3)));
		$this->assertSame([409, ['error' => 'busy']], $this->pair($controller->revalidate(3)));
		$this->assertSame([201, $this->aRun(11, 3)->jsonSerialize()], $this->pair($controller->revalidate(3)));
	}

	public function testRunReportOutcomes(): void {
		$controller = $this->controller();
		$this->runs->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('x')),
			$this->aRun(4, 99),
			$this->aRun(4, 3)
		);
		$this->params = ['outcome' => 'failed', '_limit' => '2'];
		$this->entries->expects($this->once())->method('findByRun')->with(4, $this->isInstanceOf(OutcomeFilter::class))->willReturn([]);

		$this->assertSame([404, ['error' => 'Run not found']], $this->pair($controller->run(3, 4)));
		$this->assertSame([404, ['error' => 'Run does not belong to this schema']], $this->pair($controller->run(3, 4)));
		$expected = $this->aRun(4, 3)->jsonSerialize();
		$expected['entries'] = [];
		$this->assertSame([200, $expected], $this->pair($controller->run(3, 4)));
	}

	public function testPreviewMigrationOutcomes(): void {
		$controller = $this->controller();
		$this->schemas->method('find')->willReturnOnConsecutiveCalls($this->missing(), new Schema(), new Schema(), new Schema(), new Schema());
		$this->assertSame([404, ['error' => 'Schema not found']], $this->pair($controller->previewMigration(3)));

		$this->assertSame([422, ['error' => 'A "plan" array is required']], $this->pair($controller->previewMigration(3)));

		$this->params = ['plan' => [['op' => 'x']], 'sample' => '4'];
		$this->migration->method('validatePlan')->willReturnOnConsecutiveCalls(['bad op'], [], []);
		$this->assertSame([422, ['error' => 'Invalid migration plan', 'problems' => ['bad op']]], $this->pair($controller->previewMigration(3)));

		$this->reader->method('resolveRegisterId')->willReturnOnConsecutiveCalls(null, 9);
		$this->assertSame([422, ['error' => 'No register contains this schema']], $this->pair($controller->previewMigration(3)));

		$this->migration->method('preview')->with(3, 9, [['op' => 'x']], 4)->willReturn([['before' => 1]]);
		$this->assertSame([200, ['results' => [['before' => 1]]]], $this->pair($controller->previewMigration(3)));
	}

	public function testMigrateOutcomes(): void {
		$controller = $this->controller();
		$this->schemas->method('find')->willReturnOnConsecutiveCalls($this->missing(), ...array_fill(0, 5, new Schema()));
		$this->assertSame([404, ['error' => 'Schema not found']], $this->pair($controller->migrate(3)));
		$this->assertSame([422, ['error' => 'A "plan" array is required']], $this->pair($controller->migrate(3)));

		$this->params = ['plan' => ['p'], 'options' => 'x'];
		$this->reader->method('resolveRegisterId')->willReturnOnConsecutiveCalls(null, 9, 9, 9);
		$this->assertSame([422, ['error' => 'No register contains this schema']], $this->pair($controller->migrate(3)));

		$this->migration->method('start')->with(3, 9, ['p'], [], null)->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('bad')),
			$this->throwException(new SchemaRunConcurrencyException('busy')),
			$this->aRun(12, 3)
		);
		$this->jobs->expects($this->once())->method('add')->with(SchemaRunJob::class, ['run_id' => 12]);
		$this->assertSame([422, ['error' => 'Invalid migration plan', 'problems' => ['bad']]], $this->pair($controller->migrate(3)));
		$this->assertSame([409, ['error' => 'busy']], $this->pair($controller->migrate(3)));
		$this->assertSame([201, $this->aRun(12, 3)->jsonSerialize()], $this->pair($controller->migrate(3)));
	}

	public function testRollbackOutcomes(): void {
		$controller = $this->controller();
		$this->signIn();
		$this->runs->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('x')),
			$this->aRun(4, 99),
			$this->aRun(4, 3),
			$this->aRun(4, 3),
			$this->aRun(4, 3)
		);
		$this->migration->method('rollback')->with(4, 'alice')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('not a migration')),
			$this->throwException(new SchemaRunConcurrencyException('busy')),
			$this->aRun(5, 3)
		);

		$this->assertSame([404, ['error' => 'Run not found']], $this->pair($controller->rollback(3, 4)));
		$this->assertSame([404, ['error' => 'Run does not belong to this schema']], $this->pair($controller->rollback(3, 4)));
		$this->assertSame([422, ['error' => 'not a migration']], $this->pair($controller->rollback(3, 4)));
		$this->assertSame([409, ['error' => 'busy']], $this->pair($controller->rollback(3, 4)));
		$this->assertSame([200, $this->aRun(5, 3)->jsonSerialize()], $this->pair($controller->rollback(3, 4)));
	}
}
