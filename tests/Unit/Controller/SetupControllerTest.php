<?php

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SetupController;
use OCA\OpenRegister\Service\DemoDataService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-042 / ADR-111 setup contract.
 *
 * The assertions here are about what the wizard can OBSERVE. A step the status
 * document never mentions resolves to `done: false` forever, and an optional
 * step that can never be marked done keeps the wizard open over every page —
 * so "the step is reported" and "a decision closes it" are the contract, not
 * incidental detail.
 */
class SetupControllerTest extends TestCase {
	private IAppConfig $appConfig;
	private LoggerInterface $logger;
	private DemoDataService $demoData;
	private SetupController $controller;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->demoData = $this->createMock(DemoDataService::class);

		$this->controller = new SetupController(
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->logger,
			$this->demoData
		);
	}

	public function testStatusReportsTheDemoDataStep(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$data = $this->controller->status()->getData();

		// Absence is the defect this guards: a step the wizard is never told
		// about cannot be offered and cannot be completed.
		$this->assertArrayHasKey('demo-data', $data['steps']);
		$this->assertFalse($data['steps']['demo-data']['done']);
		// This app declares no REQUIRED step, so setup must never gate the app.
		$this->assertTrue($data['completed']);
		$this->assertSame(1, $data['version']);
	}

	public function testStatusReportsTheStepDoneOnceDecided(): void {
		$this->appConfig->method('getValueString')->willReturn('skipped');

		$data = $this->controller->status()->getData();

		$this->assertTrue($data['steps']['demo-data']['done']);
	}

	public function testSkippingIsAnAnswerAndIsRecorded(): void {
		// Declining must be persisted, otherwise the wizard re-offers the import
		// on every visit and "no thanks" is impossible to express.
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'demo_data_decided', 'skipped');

		$response = $this->controller->runAction('skip-demo-data');

		$this->assertTrue($response->getData()['success']);
	}

	public function testUnknownActionIs404(): void {
		$response = $this->controller->runAction('not-an-action');

		$this->assertSame(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testInstallReportsHowMuchLanded(): void {
		$this->demoData->method('install')
			->willReturn(['objects' => 30, 'registers' => 1, 'schemas' => 4]);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'demo_data_decided', 'installed');

		$data = $this->controller->runAction('install-demo-data')->getData();

		$this->assertTrue($data['success']);
		// A success message that names no count cannot be told apart from an
		// import that wrote nothing — the defect this programme already shipped.
		$this->assertStringContainsString('30', $data['message']);
	}

	public function testAFailedInstallIsReportedAndLeavesTheStepUNDECIDED(): void {
		$this->demoData->method('install')
			->willThrowException(new RuntimeException('OpenRegister is not installed.'));

		// 🔴 THE POINT OF THIS TEST. Recording the decision here would close the
		// step for an operator who asked for demo data and received none: the
		// wizard would never offer it again, and nothing would have been
		// imported.
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->runAction('install-demo-data');

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('OpenRegister is not installed.', $response->getData()['message']);
	}
}
