<?php

/**
 * Golden test for the repair steps that import one register descriptor each.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClassConstant;
use RuntimeException;

/**
 * Pins name, output, log and import call of each step, for a missing, a broken,
 * a good and a failing import, against a golden recorded before the steps shared a base.
 *
 * @covers \OCA\OpenRegister\Repair\AbstractRegisterImportStep
 * @covers \OCA\OpenRegister\Repair\ImportCredentialBrokerRegister
 * @covers \OCA\OpenRegister\Repair\ImportDecisionTableRegister
 * @covers \OCA\OpenRegister\Repair\ImportDestructionListRegister
 * @covers \OCA\OpenRegister\Repair\ImportDismissedPairRegister
 * @covers \OCA\OpenRegister\Repair\ImportEdepotTransferRegister
 * @covers \OCA\OpenRegister\Repair\ImportFlowRegister
 * @covers \OCA\OpenRegister\Repair\ImportMergeOperationRegister
 * @covers \OCA\OpenRegister\Repair\ImportSurveyRegister
 * @covers \OCA\OpenRegister\Repair\ImportTrustConfigurationRegister
 * @covers \OCA\OpenRegister\Repair\ImportRedactionPolicyRegister
 * @uses \OCA\OpenRegister\Repair\RegisterDescriptorImport
 */
class RegisterImportStepsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/Golden/register-import-steps.json';

	private const STEPS = [
		'ImportCredentialBrokerRegister',
		'ImportDecisionTableRegister',
		'ImportDestructionListRegister',
		'ImportDismissedPairRegister',
		'ImportEdepotTransferRegister',
		'ImportFlowRegister',
		'ImportMergeOperationRegister',
		'ImportRedactionPolicyRegister',
		'ImportSurveyRegister',
		'ImportTrustConfigurationRegister',
	];

	private string $root = '';

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/or-import-steps-' . getmypid();
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	/**
	 * Run one step in one situation and record what it did.
	 *
	 * @param string $step The step's short class name.
	 * @param string $case missing, broken, good or failing.
	 *
	 * @return array<int, string>
	 */
	private function runStep(string $step, string $case): array {
		$class = 'OCA\\OpenRegister\\Repair\\' . $step;
		$relative = (string)(new ReflectionClassConstant($class, 'REGISTER_PATH'))->getValue();
		$app = $this->root . '/' . $step . '-' . $case;
		if ($case !== 'missing') {
			@mkdir(dirname($app . $relative), 0777, true);
			file_put_contents($app . $relative, $case === 'broken' ? 'not json' : '{"x-openregister":{"app":"t"},"n":1}');
		}

		$seen = [];
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturnCallback(function (string $id) use ($app, &$seen) {
			$seen[] = 'appPath ' . $id;
			return $app;
		});
		$config = $this->createMock(ConfigurationServiceInterface::class);
		$config->method('importFromApp')->willReturnCallback(
			function (string $appId, array $data, string $version, bool $force = false) use ($case, &$seen) {
				$seen[] = 'import ' . $appId . ' ' . json_encode($data) . ' ' . $version . ' ' . var_export($force, true);
				if ($case === 'failing') {
					throw new RuntimeException('importer down');
				}

				return [];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message) use (&$seen) {
			$seen[] = 'log.warning ' . $message;
		});
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(function (string $message) use ($level, $app, &$seen) {
				$seen[] = 'out.' . $level . ' ' . str_replace($app, '<app>', $message);
			});
		}

		$instance = new $class(new RegisterDescriptorImport($config, $apps, $logger));
		$seen[] = 'name ' . $instance->getName();
		$instance->run($output);
		return $seen;
	}

	/**
	 * Every step behaves as recorded, in every situation.
	 *
	 * @return void
	 */
	public function testStepsMatchTheGolden(): void {
		$actual = [];
		foreach (self::STEPS as $step) {
			foreach (['missing', 'broken', 'good', 'failing'] as $case) {
				$actual[$step][$case] = $this->runStep(step: $step, case: $case);
			}
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}
}
