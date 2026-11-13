<?php

/**
 * POST /api/objects/{register}/{schema}/recompute: counts, dry run, the update rule per object,
 * and a queued run above 500 objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\BackgroundJob\CalculationRecomputeJob;
use OCA\OpenRegister\Controller\ObjectRecomputeController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Calculation\CalculationRematerialiser;
use OCA\OpenRegister\Service\Calculation\RecomputeAccess;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Tests\Unit\Service\Calculation\Support\RecomputeHarness;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectRecomputeController
 * @covers \OCA\OpenRegister\Service\Calculation\RecomputeAccess
 * @uses   \OCA\OpenRegister\Service\Calculation\CalculationRematerialiser
 * @uses   \OCA\OpenRegister\Service\Calculation\RecomputeSelection
 * @uses   \OCA\OpenRegister\Service\Calculation\RecomputeResult
 * @uses   \OCA\OpenRegister\Command\RematerialiseRun
 */
final class RecomputeEndpointTest extends TestCase {
	use RecomputeHarness;

	/**
	 * The request body.
	 *
	 * @var array<string, mixed>
	 */
	private array $body = [];

	/**
	 * The update checks asked, as `uid:owner`.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * The controller, `jan` signed in, who may update every case but the ones listed.
	 *
	 * @param list<string>                   $forbidden      Owners of the cases `jan` may not update.
	 * @param CalculationRematerialiser|null $rematerialiser The service; the harness's by default.
	 * @param IJobList|null                  $jobs           The job list.
	 * @param bool                           $schemaMissing  Whether the schema cannot be found.
	 *
	 * @return ObjectRecomputeController
	 */
	private function controller(array $forbidden = [], ?CalculationRematerialiser $rematerialiser = null, ?IJobList $jobs = null, bool $schemaMissing = false): ObjectRecomputeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->body[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function ($schema, string $action, ?string $userId = null, ?string $objectOwner = null) use ($forbidden): bool {
				$this->asked[] = $action . ':' . $userId . ':' . $objectOwner;
				return in_array($objectOwner, $forbidden, true) === false;
			}
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($this->register());
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($this->caseSchema());
		if ($schemaMissing === true) {
			$schemas = $this->createMock(SchemaMapperInterface::class);
			$schemas->method('find')->willThrowException(new DoesNotExistException('no'));
		}

		return new ObjectRecomputeController(
			request: $request,
			registers: $registers,
			schemas: $schemas,
			rematerialiser: ($rematerialiser ?? $this->rematerialiser()),
			access: new RecomputeAccess(permissions: $permissions),
			jobs: ($jobs ?? $this->createMock(IJobList::class)),
			userSession: $session
		);
	}//end controller()

	/**
	 * The counts are returned, and only the stale objects are written.
	 *
	 * @return void
	 */
	public function testCountsAreReturned(): void {
		$this->body = ['ids' => ['case-A', 'case-B', 'case-C']];

		$response = $this->controller()->recompute('zaken', 'case');

		$this->assertSame([200, ['touched' => 2, 'unchanged' => 1, 'failed' => []]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['case-A', 'case-B'], $this->savedUuids);
		$this->assertSame(['update:jan:owner-A', 'update:jan:owner-B', 'update:jan:owner-C'], $this->asked);
	}//end testCountsAreReturned()

	/**
	 * A dry run reports the same counts and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$this->body = ['filters' => [], 'dryRun' => 'true'];

		$response = $this->controller()->recompute('zaken', 'case');

		$this->assertSame([200, ['touched' => 2, 'unchanged' => 1, 'failed' => []]], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->savedUuids);
	}//end testADryRunWritesNothing()

	/**
	 * An object the caller may not update is failed as forbidden and never written.
	 *
	 * @return void
	 */
	public function testAnObjectTheCallerMayNotUpdateIsFailedNotWritten(): void {
		$this->body = ['ids' => ['case-A', 'case-B']];

		$response = $this->controller(forbidden: ['owner-B'])->recompute('zaken', 'case');

		$this->assertSame(
			[200, ['touched' => 1, 'unchanged' => 0, 'failed' => [['id' => 'case-B', 'reason' => 'forbidden']]]],
			[$response->getStatus(), $response->getData()]
		);
		$this->assertSame(['case-A'], $this->savedUuids);
	}//end testAnObjectTheCallerMayNotUpdateIsFailedNotWritten()

	/**
	 * Above 500 objects a run is queued with the caller as its actor, and nothing is recomputed in the request.
	 *
	 * @return void
	 */
	public function testAboveFiveHundredARunIsQueued(): void {
		$this->body = ['filters' => ['caseType' => 'permit'], 'dryRun' => false];

		$rematerialiser = $this->createMock(CalculationRematerialiser::class);
		$rematerialiser->method('select')->willReturn(array_fill(0, 501, new ObjectEntity()));
		$rematerialiser->expects($this->never())->method('run');

		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(
			CalculationRecomputeJob::class,
			['register' => 5, 'schema' => 7, 'ids' => null, 'filters' => ['caseType' => 'permit'], 'dryRun' => false, 'actor' => 'jan']
		);

		$response = $this->controller(rematerialiser: $rematerialiser, jobs: $jobs)->recompute('zaken', 'case');

		$this->assertSame(
			[202, ['run' => ['status' => 'queued', 'job' => CalculationRecomputeJob::class, 'objects' => 501]]],
			[$response->getStatus(), $response->getData()]
		);
	}//end testAboveFiveHundredARunIsQueued()

	/**
	 * Both selections, or none, are refused.
	 *
	 * @return void
	 */
	public function testExactlyOneSelectionIsRequired(): void {
		foreach ([[], ['ids' => ['case-A'], 'filters' => []], ['ids' => 'case-A']] as $body) {
			$this->body = $body;
			$response = $this->controller()->recompute('zaken', 'case');
			$this->assertSame(400, $response->getStatus(), json_encode($body));
		}

		$this->assertSame([], $this->savedUuids);
	}//end testExactlyOneSelectionIsRequired()

	/**
	 * A schema the caller cannot find is 404.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaIs404(): void {
		$this->body = ['ids' => ['case-A']];
		$response = $this->controller(schemaMissing: true)->recompute('zaken', 'nope');

		$this->assertSame([404, ['error' => 'Register or schema not found']], [$response->getStatus(), $response->getData()]);
	}//end testAnUnknownSchemaIs404()
}//end class
