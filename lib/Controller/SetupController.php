<?php
/**
 * Openregister SetupController.
 *
 * The ADR-042 first-time setup contract, in its smallest honest form:
 *
 *   GET  /api/setup/status            per-step state
 *   POST /api/setup/action/{actionId} run a privileged server-side action
 *
 * This app declares no configuration of its own yet, so the wizard orients and
 * offers the demo data the app ALREADY ships — a dataset generated from its own
 * schemas that no operator could previously reach. It deliberately does not
 * invent configuration steps: a wizard that asks questions the app does not act
 * on is worse than none.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\DemoDataService;

/**
 * First-time setup wizard endpoints.
 *
 * @spec exclude First-time-setup action dispatch; ADR-042 contract, no per-app behavioural spec.
 */
class SetupController extends Controller {
	/**
	 * Setup contract version; matches manifest.setup.version.
	 *
	 * @var integer
	 */
	private const SETUP_VERSION = 1;

	/**
	 * App-config key recording that the demo-data step was DEALT WITH.
	 *
	 * Not "objects exist": an operator who declines has finished the step, and
	 * re-offering the import on every visit would make "no thanks" impossible to
	 * express. Since @conduction/nextcloud-vue 2.21 that also matters visually —
	 * an OUTSTANDING OPTIONAL step opens the wizard over every page
	 * (nextcloud-vue#806), so a step that can never be marked done is a dialog
	 * that never closes.
	 *
	 * @var string
	 */
	private const DEMO_DECIDED_KEY = 'demo_data_decided';

	/**
	 * App-config key holding the dataset the operator picked.
	 *
	 * The wizard's `choice` step writes it through `POST /api/setup/config`, and
	 * the `run-action` step that follows reads it back. Two steps rather than
	 * one because `CnSetupWizard::runAction()` posts to
	 * `/api/setup/action/{action}` with no body: an action cannot carry the
	 * answer, so the answer has to be stored before the action runs.
	 *
	 * @var string
	 */
	private const DATASET_KEY = 'demo_dataset';

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request         The request.
	 * @param IAppConfig      $appConfig       Records the demo-data decision.
	 * @param LoggerInterface $logger          Records a failed import.
	 * @param DemoDataService $demoDataService Imports the shipped demo dataset.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly DemoDataService $demoDataService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Report per-step setup status for the wizard.
	 *
	 * `completed` is deliberately TRUE: this app declares no REQUIRED step, so
	 * setup must never gate the app. The demo-data step is reported so the wizard
	 * can stop asking once it has an answer.
	 *
	 * @return JSONResponse The status document.
	 *
	 * @spec exclude Setup status document; ADR-042 contract, no per-app behavioural spec.
	 */
	#[AuthorizedAdminSetting(OpenRegisterAdmin::class)]
	public function status(): JSONResponse {
		$demoDecided = $this->appConfig->getValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, '') !== '';
		$picked      = $this->appConfig->getValueString(Application::APP_ID, self::DATASET_KEY, '');

		return new JSONResponse(
			data: [
				'version'   => self::SETUP_VERSION,
				'completed' => true,
				// The choice step reads its options from here: it declares
				// `optionsSource: datasets` and no options of its own, so a
				// dataset missing from this list is a dataset nobody can pick.
				'datasets'  => $this->demoDataService->listChoices(),
				'steps'     => [
					'demo-data' => ['done' => ($picked !== '')],
					// "None" is an ANSWER, so the load step is finished the moment
					// it is chosen: there is nothing left for the operator to run.
					'load-demo-data' => [
						'done' => ($demoDecided === true || $picked === DemoDataService::NONE_DATASET),
					],
				],
			]
		);

	}//end status()

	/**
	 * Persist the wizard's `choice` answer.
	 *
	 * @return JSONResponse `{ success, config }`.
	 *
	 * @spec exclude Setup config write; ADR-042 contract, no per-app behavioural spec.
	 */
	#[AuthorizedAdminSetting(OpenRegisterAdmin::class)]
	public function saveConfig(): JSONResponse {
		// 🔴 ONE NAMED KEY, NEVER A CALLER-SUPPLIED ONE. The body arrives from
		// the browser and this app's own settings share the appconfig namespace,
		// so looping over the posted keys would let this endpoint write any of
		// them. The key is written in the source; only its value comes from the
		// request.
		$value = $this->request->getParam(self::DATASET_KEY);
		if ($value === null) {
			return new JSONResponse(data: ['success' => true, 'config' => []]);
		}

		// The step is not `multiple`, but the wizard's contract allows a list, so
		// both shapes are read rather than one of them reaching `(string)`.
		$submitted = $value;
		if (is_array($value) === true) {
			$submitted = ($value[0] ?? null);
		}

		if (is_scalar($submitted) === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'A dataset is named by a string.'],
				statusCode: Http::STATUS_BAD_REQUEST,
			);
		}

		$datasetId = (string)$submitted;
		$known     = array_column($this->demoDataService->listChoices(), 'id');
		if (in_array($datasetId, $known, true) === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'No dataset is called "' . $datasetId . '".'],
				statusCode: Http::STATUS_BAD_REQUEST,
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, $datasetId);

		return new JSONResponse(data: ['success' => true, 'config' => [self::DATASET_KEY => $datasetId]]);

	}//end saveConfig()

	/**
	 * Run a privileged server-side setup action.
	 *
	 * Admin-only by Nextcloud's default for an un-attributed method.
	 *
	 * @param string $actionId One of `install-demo-data` | `skip-demo-data`.
	 *
	 * @return JSONResponse `{ success, message }`.
	 *
	 * @spec exclude Setup action dispatch; ADR-042 contract, no per-app behavioural spec.
	 */
	#[AuthorizedAdminSetting(OpenRegisterAdmin::class)]
	public function runAction(string $actionId): JSONResponse {
		// `install-demo-data` is the id the step used before it asked WHICH
		// dataset, and it still means "import the one this app ships". Kept so
		// an older manifest, a runbook or a script that posts it keeps working.
		if ($actionId === 'load-demo-data' || $actionId === 'install-demo-data') {
			return $this->loadDataset(actionId: $actionId);
		}

		// DECLINING IS AN ANSWER — see DEMO_DECIDED_KEY.
		//
		// 🔴 AND IT ANSWERS *BOTH* STEPS. The wizard now has a choice step and a
		// run-action step; closing only the second leaves the first outstanding,
		// and CnAppRoot opens the wizard while ANY optional step is outstanding.
		if ($actionId === 'skip-demo-data') {
			$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, DemoDataService::NONE_DATASET);
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'skipped');

			return new JSONResponse(data: ['success' => true, 'message' => 'No example data was loaded.']);
		}

		return new JSONResponse(
			data: ['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			statusCode: Http::STATUS_NOT_FOUND,
		);

	}//end runAction()

	/**
	 * Import the dataset the operator picked in the previous step.
	 *
	 * @param string $actionId The action that asked, which decides whether an
	 *                         unanswered choice is refused or means the shipped set.
	 *
	 * Reports the FAILURE rather than a quiet success: an operator who asked for
	 * demo data and got none must be told, which is why DemoDataService::install()
	 * throws instead of returning an empty result.
	 *
	 * @return JSONResponse `{ success, message }`.
	 */
	private function loadDataset(string $actionId): JSONResponse {
		$picked = $this->appConfig->getValueString(Application::APP_ID, self::DATASET_KEY, '');

		// The legacy id carries no answer, so it means the shipped dataset. A
		// caller that posts it has said which one by posting it.
		if ($actionId === 'install-demo-data' && $picked === '') {
			$picked = DemoDataService::DEMO_DATASET;
		}

		// 🔴 NO SILENT DEFAULT. Importing here because the operator clicked Run
		// one step early would plant example objects nobody asked for, which is
		// the failure this whole step exists to avoid.
		if ($picked === '') {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Pick a dataset first.'],
				statusCode: Http::STATUS_BAD_REQUEST,
			);
		}

		if ($picked === DemoDataService::NONE_DATASET) {
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'skipped');

			return new JSONResponse(data: ['success' => true, 'message' => 'No example data was loaded.']);
		}

		try {
			$imported = $this->demoDataService->install();
		} catch (\Throwable $e) {
			$this->logger->error(
				'Setup install-demo-data failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e]
			);

			return new JSONResponse(
				data: ['success' => false, 'message' => 'Could not import the demo data: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'installed');

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'Imported ' . $imported['objects'] . ' demo object(s).',
			]
		);

	}//end loadDataset()
}//end class
