<?php
/**
 * Openregister DemoDataService.
 *
 * Imports `lib/Settings/openregister_mock_register.json` — a `type: mock` descriptor generated from this
 * app's own schemas by `hydra-gates/scripts/lib/generate_mock_register.py`, so
 * every value is conformant BY CONSTRUCTION rather than written to look
 * plausible.
 *
 * 🔴 ON DEMAND ONLY, NEVER ON INSTALL. A mock register has no Repair step: demo
 * data is something an operator asks for from the setup wizard, and an install
 * that silently seeds example objects into a production instance is a defect,
 * not a convenience.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Imports the shipped demo dataset on request.
 *
 * @spec exclude Demo-data import; ADR-111 rule 1, no per-app behavioural spec.
 */
class DemoDataService {
	/**
	 * App-relative path to the generated mock descriptor.
	 *
	 * @var string
	 */
	private const DESCRIPTOR = '/lib/Settings/openregister_mock_register.json';

	/**
	 * Configuration identity for the demo import.
	 *
	 * 🔴 ITS OWN NAMESPACE, not the app id. Sharing the app's identity would make
	 * the demo import and the real configuration import share one version gate, so
	 * installing demo data could mask a pending configuration update — or be
	 * masked by one.
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = Application::APP_ID . '.demo';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Resolves this app's path and version.
	 * @param ContainerInterface $container  Resolves OpenRegister's importer.
	 * @param LoggerInterface    $logger     Records what was imported.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this app ships a demo dataset at all.
	 *
	 * @return boolean True when the descriptor is present on disk.
	 *
	 * @spec exclude Demo-data availability probe; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function isAvailable(): bool {
		return is_file($this->descriptorPath()) === true;
	}//end isAvailable()

	/**
	 * The answer that means "plant nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_DATASET = 'none';

	/**
	 * The id of the dataset this app ships.
	 *
	 * @var string
	 */
	public const DEMO_DATASET = 'demo';

	/**
	 * Every answer the wizard's choice step may offer, declining included.
	 *
	 * 🔴 THE SERVER OWNS THIS LIST, AND THAT IS THE POINT. The step declares
	 * `optionsSource: datasets` and no options of its own, so the label, the
	 * description and the object count come from the descriptor that will
	 * actually be imported. A manifest that restated them could disagree with
	 * what lands, and nothing would notice.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec exclude Demo-data choice list; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function listChoices(): array {
		$choices = [
			[
				'id'          => self::NONE_DATASET,
				'label'       => 'None, I will set this up myself',
				'description' => 'Nothing is imported. You start with an empty app and add your own data.',
				'objectCount' => 0,
				'icon'        => 'CloseCircleOutline',
			],
		];

		$objects = $this->shippedObjectCount();
		if ($objects !== null) {
			$choices[] = [
				'id'    => self::DEMO_DATASET,
				'label' => 'Example data',
				// 🔴 NO NUMBER IN THIS SENTENCE. The wizard runs a card's
				// description through the app's translation function, which is a
				// literal lookup, so an interpolated count would make the string
				// untranslatable and leave a Dutch operator reading English. The
				// count travels as `objectCount` and the card renders it as a
				// stat, with a label the library translates.
				'description' => (
					'Sample values for every schema this app supplies, generated from the schemas '
					. 'themselves. It shows the lists, detail pages and dashboards working rather '
					. 'than telling a story. Safe to run more than once, and you can delete it '
					. 'afterwards.'
				),
				'objectCount' => $objects,
				'icon'        => 'DatabaseOutline',
			];
		}

		return $choices;

	}//end listChoices()

	/**
	 * How many objects the shipped descriptor carries, or null when it ships none.
	 *
	 * Counted from the FILE, so the card promises the number that will actually
	 * be imported. A missing or malformed descriptor returns null and the app
	 * then offers only "None" — honest, rather than an import that cannot run.
	 *
	 * @return integer|null The object count, or null when there is no usable descriptor.
	 */
	private function shippedObjectCount(): ?int {
		$path = $this->descriptorPath();
		if (is_file($path) === false) {
			return null;
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			return null;
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			return null;
		}

		$components = ($data['components'] ?? []);
		if (is_array($components) === false || is_array(($components['objects'] ?? null)) === false) {
			return 0;
		}

		return count($components['objects']);

	}//end shippedObjectCount()

	/**
	 * Import the demo dataset.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. The caller reports the
	 * outcome to an operator who just asked for this, so "nothing happened" must
	 * not be presentable as success.
	 *
	 * @return array{objects: integer, registers: integer, schemas: integer} What was imported.
	 *
	 * @throws RuntimeException When the descriptor is missing, unreadable, or OpenRegister is absent.
	 *
	 * @spec exclude Demo-data import; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function install(): array {
		$path = $this->descriptorPath();
		if (is_file($path) === false) {
			throw new RuntimeException('No demo dataset ships with this app (' . self::DESCRIPTOR . ' not found).');
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('The demo dataset could not be read: ' . $path);
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			throw new RuntimeException('The demo dataset is not valid JSON: ' . $path);
		}

		// Counted from the FILE, not the importer's reply, so the number reported
		// is the number ASKED FOR. An object whose schema does not resolve is
		// SKIPPED rather than errored, so a discrepancy here is a real condition
		// an operator should be able to see.
		$objects = 0;
		$components = ($data['components'] ?? []);
		if (is_array($components) === true && is_array(($components['objects'] ?? null)) === true) {
			$objects = count($components['objects']);
		}

		$result = $this->configurationService()->importFromApp(
			appId: self::CONFIG_APP_ID,
			data: $data,
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		$imported = [
			'objects'   => $objects,
			'registers' => count((array)($result['registers'] ?? [])),
			'schemas'   => count((array)($result['schemas'] ?? [])),
		];

		$this->logger->info(
			'[DemoDataService] imported demo data: '
			. $imported['objects'] . ' object(s), '
			. $imported['registers'] . ' register(s), '
			. $imported['schemas'] . ' schema(s).',
			['app' => Application::APP_ID]
		);

		return $imported;
	}//end install()

	/**
	 * Absolute path to the shipped descriptor.
	 *
	 * @return string The path.
	 */
	private function descriptorPath(): string {
		return $this->appManager->getAppPath(Application::APP_ID) . self::DESCRIPTOR;
	}//end descriptorPath()

	/**
	 * OpenRegister's configuration importer.
	 *
	 * 🔴 A CROSS-APP CLASS IS A RUNTIME LOOKUP. OpenRegister may not be installed,
	 * and asking the container for a class from a missing app raises something the
	 * caller cannot act on. Check first and say which app is missing.
	 *
	 * 🔴 THE RETURN TYPE IS `object`, NOT THE CLASS, AND THAT IS THE POINT. Naming
	 * a class from an OPTIONAL app in a native return type makes PHP resolve it
	 * whenever this method returns, so on an instance without OpenRegister the
	 * failure is a TypeError about a class nobody mentioned instead of the
	 * RuntimeException above that names the missing app.
	 *
	 * @return object The importer — an OCA\OpenRegister\Service\ConfigurationService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ConfigurationService
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function configurationService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('Demo data needs OpenRegister, which is not installed.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
	}//end configurationService()
}//end class
