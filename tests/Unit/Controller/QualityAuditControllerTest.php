<?php

/**
 * Unit tests for QualityAuditController — administrators only, faults named, runs kept.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\QualityAuditController;
use OCA\OpenRegister\Controller\Support\AdministratorOnlyGate;
use OCA\OpenRegister\Db\QualityAudit;
use OCA\OpenRegister\Db\QualityAuditFailure;
use OCA\OpenRegister\Db\QualityAuditMapperInterface;
use OCA\OpenRegister\Db\QualityAuditRun;
use OCA\OpenRegister\Db\QualityAuditRunMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Quality\QualityAuditEvaluator;
use OCA\OpenRegister\Service\Quality\QualityAuditService;
use OCA\OpenRegister\Service\Quality\QualityAuditStores;
use OCA\OpenRegister\Service\Quality\QualityScopeMappers;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class QualityAuditControllerTest extends TestCase {

	private array $params = [];

	private IRequest $request;

	private IUserSession $userSession;

	private IGroupManager $groupManager;

	private QualityAuditMapperInterface $audits;

	private QualityAuditRunMapperInterface $runs;

	private ObjectOperationsInterface $objects;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->audits = $this->createMock(QualityAuditMapperInterface::class);
		$this->runs = $this->createMock(QualityAuditRunMapperInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
	}

	private function signIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($admin);
	}

	private function controller(): QualityAuditController {
		$stores = new QualityAuditStores($this->audits, $this->runs);
		$service = new QualityAuditService(
			$stores,
			new QualityAuditEvaluator(new QualityScorer()),
			$this->objects,
			new QualityScopeMappers(
				$this->createMock(SchemaMapperInterface::class),
				$this->createMock(RegisterMapperInterface::class)
			),
			new NullLogger()
		);

		return new QualityAuditController(
			$this->request,
			new AdministratorOnlyGate($this->userSession, $this->groupManager),
			$service,
			$stores,
			$this->userSession
		);
	}

	private function audit(): QualityAudit {
		$audit = new QualityAudit();
		$audit->setId(3);
		$audit->setUuid('audit-3');
		$audit->setRegisterRef('bag');
		$audit->setSchemaRef('address');
		$audit->setRules([['type' => 'required', 'field' => 'postcode']]);
		$audit->setTolerance(0.9);

		return $audit;
	}

	public function testANonAdministratorIsRefusedEveryEndpoint(): void {
		$this->signIn('alice', false);
		$this->audits->expects($this->never())->method($this->anything());
		$this->runs->expects($this->never())->method($this->anything());

		$controller = $this->controller();
		$statuses = [
			$controller->index()->getStatus(),
			$controller->create()->getStatus(),
			$controller->show(3)->getStatus(),
			$controller->update(3)->getStatus(),
			$controller->destroy(3)->getStatus(),
			$controller->run(3)->getStatus(),
			$controller->runs(3)->getStatus(),
			$controller->failures(11)->getStatus(),
			$controller->failuresCsv(11)->getStatus(),
		];

		$this->assertSame(array_fill(0, 9, 403), $statuses);
	}

	public function testAFaultyDeclarationIsRefusedNamingItsFaults(): void {
		$this->signIn('root', true);
		$this->params = [
			'name' => 'Addresses',
			'population' => ['register' => 'bag', 'schema' => 'address'],
			'rules' => [],
			'tolerance' => 0.95,
		];
		$this->audits->expects($this->never())->method('persist');

		$response = $this->controller()->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['rules: an audit needs at least one rule.'], $response->getData()['faults']);
	}

	public function testAnUnknownAuditIsNotFound(): void {
		$this->signIn('root', true);
		$this->audits->method('find')->willThrowException(new DoesNotExistException('no'));

		$this->assertSame(404, $this->controller()->show(99)->getStatus());
	}

	public function testAnAuditReadsWithItsRunsInSequence(): void {
		$this->signIn('root', true);
		$this->audits->method('find')->with(3)->willReturn($this->audit());
		$march = new QualityAuditRun();
		$march->setScore(0.91);
		$june = new QualityAuditRun();
		$june->setScore(0.87);
		$this->runs->method('findByAudit')->willReturn([$march, $june]);

		$data = $this->controller()->show(3)->getData();

		$this->assertSame('audit-3', $data['uuid']);
		$this->assertSame([0.91, 0.87], array_map(static fn (QualityAuditRun $run): ?float => $run->getScore(), $data['runs']));
	}

	public function testARunOnDemandIsStartedByTheAdministratorAndKept(): void {
		$this->signIn('root', true);
		$this->audits->method('find')->willReturn($this->audit());
		$this->audits->method('persist')->willReturnArgument(0);
		$this->objects->method('findAll')->willReturn([['id' => 'a', 'postcode' => '1234AB'], ['id' => 'b', 'postcode' => '']]);
		$this->runs->method('persist')->willReturnCallback(
			static function (QualityAuditRun $run): QualityAuditRun {
				$run->setId(11);

				return $run;
			}
		);
		$this->runs->expects($this->once())->method('addFailures')
			->with(11, [['record' => 'b', 'rules' => ['required:postcode']]]);

		$response = $this->controller()->run(3);

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('root', $response->getData()['startedBy']);
		$this->assertSame('fail', $response->getData()['verdict']);
		$this->assertSame(0.9, $response->getData()['tolerance']);
	}

	public function testTheFailuresOfARunExportAsCsv(): void {
		$this->signIn('root', true);
		$run = new QualityAuditRun();
		$run->setId(11);
		$run->setUuid('run-11');
		$this->runs->method('find')->with(11)->willReturn($run);
		$failure = new QualityAuditFailure();
		$failure->setRecord('b');
		$failure->setRules(['required:postcode']);
		$this->runs->method('findFailures')->willReturn([$failure]);

		$response = $this->controller()->failuresCsv(11);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame("record,rules\nb,required:postcode\n", $response->render());
		$this->assertStringContainsString('quality-audit-run-run-11-failures.csv', (string)$response->getHeaders()['Content-Disposition']);
	}

	public function testDeletingAnAuditAnswersNoContent(): void {
		$this->signIn('root', true);
		$this->audits->method('find')->willReturn($this->audit());
		$this->runs->expects($this->once())->method('deleteByAudit')->with(3);
		$this->audits->expects($this->once())->method('remove');

		$this->assertSame(204, $this->controller()->destroy(3)->getStatus());
	}
}
