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

	public function testStatusReportsBothDemoDataSteps(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->method('listChoices')->willReturn([]);

		$data = $this->controller->status()->getData();

		// Absence is the defect this guards: a step the wizard is never told
		// about cannot be offered and cannot be completed.
		$this->assertArrayHasKey('demo-data', $data['steps']);
		$this->assertArrayHasKey('load-demo-data', $data['steps']);
		$this->assertFalse($data['steps']['demo-data']['done']);
		$this->assertFalse($data['steps']['load-demo-data']['done']);
		// This app declares no REQUIRED step, so setup must never gate the app.
		$this->assertTrue($data['completed']);
		$this->assertSame(1, $data['version']);
	}

	public function testStatusCarriesTheOptionListTheChoiceStepReads(): void {
		// 🔴 THIS RESPONSE *IS* THE OPTION LIST. The step declares
		// `optionsSource: datasets` and carries no options of its own, so a
		// dataset missing here is a dataset nobody can pick.
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => 'Nothing.', 'objectCount' => 0, 'icon' => 'CloseCircleOutline'],
			['id' => 'demo', 'label' => 'Example data', 'description' => 'Sample values.', 'objectCount' => 66, 'icon' => 'DatabaseOutline'],
		]);

		$data = $this->controller->status()->getData();

		$this->assertSame(['none', 'demo'], array_column($data['datasets'], 'id'));
		// A card renders all three; an entry missing one renders a blank card.
		$this->assertSame('Sample values.', $data['datasets'][1]['description']);
		$this->assertSame(66, $data['datasets'][1]['objectCount']);
		$this->assertSame('DatabaseOutline', $data['datasets'][1]['icon']);
	}

	public function testChoosingNoneClosesBothStepsWithoutRunningAnything(): void {
		// 🔴 THE DEFECT THIS FIXES. Every app in this fleet implemented
		// `skip-demo-data` and NO manifest step could reach it, so declining was
		// unsayable: the step stayed `done: false` and CnAppRoot reopened the
		// wizard over every page, for ever, unless the operator imported data
		// they did not want.
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string
				=> ($key === 'demo_dataset' ? 'none' : ''));
		$this->demoData->method('listChoices')->willReturn([]);

		$data = $this->controller->status()->getData();

		$this->assertTrue($data['steps']['demo-data']['done']);
		$this->assertTrue($data['steps']['load-demo-data']['done']);
	}

	public function testTheChoiceIsPersisted(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('demo');
		$controller = new SetupController($request, $this->appConfig, $this->logger, $this->demoData);
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => ''],
			['id' => 'demo', 'label' => 'Example data', 'description' => '', 'objectCount' => 66, 'icon' => ''],
		]);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'demo_dataset', 'demo');

		$data = $controller->saveConfig()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('demo', $data['config']['demo_dataset']);
	}

	public function testAnUnknownDatasetIsRejectedRatherThanStored(): void {
		// Storing it would leave the load step pointing at nothing, so the
		// failure would surface one step later with no clue why.
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('atlantis');
		$controller = new SetupController($request, $this->appConfig, $this->logger, $this->demoData);
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => ''],
		]);

		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $controller->saveConfig();

		$this->assertSame(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testPostingNothingIsNotAnAnswerAndStoresNothing(): void {
		// The wizard posts the whole config patch, so a step that has not been
		// answered posts no key at all. That is not an error and it is not a
		// choice either.
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$controller = new SetupController($request, $this->appConfig, $this->logger, $this->demoData);

		$this->appConfig->expects($this->never())->method('setValueString');

		$data = $controller->saveConfig()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame([], $data['config']);
	}

	public function testAListIsAcceptedBecauseTheWizardContractAllowsOne(): void {
		// The step is not `multiple`, but the same endpoint serves steps that
		// are, so an array must not reach `(string)` and become "Array".
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(['demo']);
		$controller = new SetupController($request, $this->appConfig, $this->logger, $this->demoData);
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'demo', 'label' => 'Example data', 'description' => '', 'objectCount' => 1, 'icon' => ''],
		]);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'demo_dataset', 'demo');

		$this->assertTrue($controller->saveConfig()->getData()['success']);
	}

	public function testAValueThatIsNotAStringIsRefused(): void {
		// The body is whatever the browser posted. A nested array would
		// otherwise reach `(string)` and raise a fatal.
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn([['demo']]);
		$controller = new SetupController($request, $this->appConfig, $this->logger, $this->demoData);

		$this->appConfig->expects($this->never())->method('setValueString');

		$this->assertSame(400, $controller->saveConfig()->getStatus());
	}

	public function testChoosingNoneAndThenRunningImportsNothing(): void {
		// 🔴 THE LOAD STEP STILL RUNS AFTER "None". It must record the decision
		// and import nothing, rather than refusing: refusing would leave the
		// step open and reopen the wizard.
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string
				=> ($key === 'demo_dataset' ? 'none' : ''));
		$this->demoData->expects($this->never())->method('install');

		$data = $this->controller->runAction('load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('No example data', $data['message']);
	}

	public function testLoadingWithoutAChoiceRefusesRatherThanGuessing(): void {
		// 🔴 NO SILENT DEFAULT. Importing because the operator clicked Run one
		// step early would plant example objects nobody asked for.
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->expects($this->never())->method('install');

		$response = $this->controller->runAction('load-demo-data');

		$this->assertSame(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testTheLegacyActionStillImportsTheShippedDataset(): void {
		// `install-demo-data` was the id before the step asked WHICH dataset. A
		// runbook or script that still posts it must keep working, and it names
		// the shipped set by naming itself.
		$this->appConfig->method('getValueString')->willReturn('');
		$this->demoData->method('install')
			->willReturn(['objects' => 30, 'registers' => 1, 'schemas' => 4]);

		$data = $this->controller->runAction('install-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('30', $data['message']);
	}

	public function testSkippingClosesBOTHStepsOrTheWizardNeverCloses(): void {
		// Declining must be persisted, otherwise the wizard re-offers the import
		// on every visit and "no thanks" is impossible to express.
		//
		// AND IT MUST ANSWER BOTH STEPS. Splitting the single `demo-data` step
		// into a choice plus a run-action gives the wizard two outstanding
		// steps, and CnAppRoot opens the wizard while ANY optional step is
		// outstanding — so closing only the second is the same bug in a new
		// shape.
		$written = [];
		$this->appConfig->method('setValueString')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;

				return true;
			});

		$response = $this->controller->runAction('skip-demo-data');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('skipped', $written['demo_data_decided'] ?? null);
		$this->assertSame('none', $written['demo_dataset'] ?? null, 'skipping IS choosing none');
	}

	public function testUnknownActionIs404(): void {
		$response = $this->controller->runAction('not-an-action');

		$this->assertSame(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testLoadReportsHowMuchLanded(): void {
		$this->appConfig->method('getValueString')->willReturn('demo');
		$this->demoData->method('install')
			->willReturn(['objects' => 30, 'registers' => 1, 'schemas' => 4]);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'demo_data_decided', 'installed');

		$data = $this->controller->runAction('load-demo-data')->getData();

		$this->assertTrue($data['success']);
		// A success message that names no count cannot be told apart from an
		// import that wrote nothing — the defect this programme already shipped.
		$this->assertStringContainsString('30', $data['message']);
	}

	public function testAFailedLoadIsReportedAndLeavesTheStepUNDECIDED(): void {
		$this->appConfig->method('getValueString')->willReturn('demo');
		$this->demoData->method('install')
			->willThrowException(new RuntimeException('OpenRegister is not installed.'));

		// 🔴 THE POINT OF THIS TEST. Recording the decision here would close the
		// step for an operator who asked for demo data and received none: the
		// wizard would never offer it again, and nothing would have been
		// imported.
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->runAction('load-demo-data');

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('OpenRegister is not installed.', $response->getData()['message']);
	}
}
