<?php

/**
 * The setup status document: what `GET /api/setup/status` answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/shell-reads-from-initial-state/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\DemoDataServiceInterface;
use OCP\IAppConfig;

/**
 * Builds the setup status document for the page that carries it as initial
 * state. It must answer exactly what `SetupController::status()` answers; a
 * unit test holds the two to the same document (the controller lives in
 * another area and may not reference this class, ADR-013).
 *
 * @spec openspec/changes/shell-reads-from-initial-state/specs/first-time-setup/spec.md
 */
final class SetupStatusReader {
	/**
	 * Bumped when the wizard gains a step every install must see again.
	 */
	public const SETUP_VERSION = 1;

	/**
	 * Set once an admin answered the example-data step, declining included.
	 */
	public const DEMO_DECIDED_KEY = 'demo_data_decided';

	/**
	 * The example dataset an admin picked.
	 */
	public const DATASET_KEY = 'demo_dataset';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig               $appConfig       App configuration.
	 * @param DemoDataServiceInterface $demoDataService The example datasets on offer.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly DemoDataServiceInterface $demoDataService,
	) {
	}//end __construct()

	/**
	 * The status document: version, completed, the datasets the choice step
	 * offers and the per-step done flags.
	 *
	 * @return array{version: int, completed: true, datasets: array, steps: array<string, array{done: bool}>}
	 *
	 * @spec openspec/changes/shell-reads-from-initial-state/specs/first-time-setup/spec.md
	 */
	public function read(): array {
		$demoDecided = $this->appConfig->getValueString(AppIdentity::APP_ID, self::DEMO_DECIDED_KEY, '') !== '';
		$picked      = $this->appConfig->getValueString(AppIdentity::APP_ID, self::DATASET_KEY, '');

		return [
			'version'   => self::SETUP_VERSION,
			'completed' => true,
			// The choice step reads its options from here: it declares
			// `optionsSource: datasets` and no options of its own, so a
			// dataset missing from this list is a dataset nobody can pick.
			'datasets'  => $this->demoDataService->listChoices(),
			'steps'     => [
				'welcome'   => ['done' => true],
				'demo-data' => ['done' => ($demoDecided === true || $picked !== '')],
				'done'      => ['done' => true],
			],
		];
	}//end read()
}//end class
