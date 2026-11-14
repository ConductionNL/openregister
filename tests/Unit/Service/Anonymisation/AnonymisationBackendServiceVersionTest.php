<?php

/**
 * Backend-state carries what each detection backend is built from: the
 * pattern set's version and change day, the ExApp's installed version, and
 * `unknown` for whatever a backend does not expose.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlPatternSet;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendCollaborators;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendPlatform;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\Anonymisation\DetectorVersionHistory;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService
 * @covers \OCA\OpenRegister\Service\Anonymisation\DetectorVersionReader
 * @covers \OCA\OpenRegister\Service\Anonymisation\BackendInfo
 * @uses   \OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendCollaborators
 * @uses   \OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendPlatform
 * @uses   \OCA\OpenRegister\Service\Anonymisation\BackendState
 * @uses   \OCA\OpenRegister\Service\Anonymisation\DetectorVersion
 * @uses   \OCA\OpenRegister\Service\Anonymisation\DetectorVersionHistory
 * @uses   \OCA\OpenRegister\Service\Anonymisation\ProbeResult
 * @uses   \OCA\OpenRegister\Service\Anonymisation\ProbeResultCache
 */
final class AnonymisationBackendServiceVersionTest extends TestCase {
	use InMemoryAppConfigTrait;

	/**
	 * The backend-state, as the admin API serialises it.
	 *
	 * @param IAppManager $apps The app manager.
	 *
	 * @return array<string, mixed>
	 */
	private function backendState(IAppManager $apps): array {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);
		$settings = $this->getMockBuilder(FileSettingsHandler::class)->disableOriginalConstructor()->onlyMethods(['getFileSettingsOnly'])->getMock();
		$settings->method('getFileSettingsOnly')->willReturn(['entityRecognitionEnabled' => true, 'entityRecognitionMethod' => 'auto', 'presidioApiEndpoint' => '']);

		$service = new AnonymisationBackendService(
			new AnonymisationBackendPlatform($apps, $this->inMemoryAppConfig(), $caches, $this->createMock(IClientService::class)),
			new AnonymisationBackendCollaborators($settings, $this->createMock(LoggerInterface::class), $this->createMock(ConnectionReporter::class))
		);

		return $service->getState()->jsonSerialize();
	}//end backendState()

	/**
	 * An app manager with AppAPI and the full OpenAnonymiser ExApp enabled, at the given versions.
	 *
	 * @param array<string, string|RuntimeException> $versions Version, or failure, per app id.
	 *
	 * @return IAppManager
	 */
	private function apps(array $versions): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $id): bool => in_array($id, ['app_api', 'openanonymiser'], true));
		$apps->method('getAppVersion')->willReturnCallback(static function (string $id) use ($versions): string {
			$version = ($versions[$id] ?? '0');
			if ($version instanceof RuntimeException) {
				throw $version;
			}

			return $version;
		});

		return $apps;
	}//end apps()

	public function testThePatternSetReportsItsVersionAndChangeDate(): void {
		$detector = $this->backendState($this->apps(['openregister' => '0.2.13']))['backends'][BackendState::METHOD_REGEX]['detector'];

		$this->assertSame(
			[
				'engine' => 'openregister-patterns',
				'engineVersion' => '0.2.13',
				'model' => 'unknown',
				'modelVersion' => 'unknown',
				'patternSetVersion' => NlPatternSet::VERSION,
				'datasets' => [],
				'changedAt' => NlPatternSet::CHANGED_AT,
			],
			$detector
		);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', NlPatternSet::VERSION);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', NlPatternSet::CHANGED_AT);
	}

	public function testAnUnexposedModelIsReportedAsUnknown(): void {
		$backends = $this->backendState($this->apps(['openregister' => '0.2.13', 'openanonymiser' => '1.3.0']))['backends'];

		$openAnonymiser = $backends[BackendState::METHOD_OPENANONYMISER]['detector'];
		$this->assertSame(['openanonymiser', '1.3.0'], [$openAnonymiser['engine'], $openAnonymiser['engineVersion']]);
		$this->assertArrayHasKey('modelVersion', $openAnonymiser);
		$this->assertSame('unknown', $openAnonymiser['modelVersion']);
		$this->assertSame('unknown', $openAnonymiser['model']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $openAnonymiser['changedAt'], 'a reachable backend without a change day of its own is dated by its first probe');

		$presidio = $backends[BackendState::METHOD_PRESIDIO]['detector'];
		$this->assertSame(['presidio', 'unknown', 'unknown', 'unknown'], [$presidio['engine'], $presidio['engineVersion'], $presidio['modelVersion'], $presidio['changedAt']]);
		$this->assertSame('llm', $backends[BackendState::METHOD_LLM]['detector']['engine']);
		$this->assertSame(NlPatternSet::VERSION, $backends[BackendState::METHOD_HYBRID]['detector']['patternSetVersion']);
	}

	public function testAnAppVersionThatCannotBeReadIsUnknown(): void {
		$backends = $this->backendState($this->apps(['openregister' => new RuntimeException('no app'), 'openanonymiser' => '']))['backends'];

		$this->assertSame('unknown', $backends[BackendState::METHOD_REGEX]['detector']['engineVersion']);
		$this->assertSame('unknown', $backends[BackendState::METHOD_OPENANONYMISER]['detector']['engineVersion']);
	}

	public function testOnlyReachableBackendsAreNotedInTheHistory(): void {
		$this->backendState($this->apps(['openregister' => '0.2.13', 'openanonymiser' => '1.3.0']));

		$stored = json_decode($this->configValues['openregister/' . DetectorVersionHistory::CONFIG_KEY], true);
		$this->assertSame([BackendState::METHOD_REGEX, BackendState::METHOD_OPENANONYMISER], array_keys($stored));
		$this->assertSame('1.3.0', $stored[BackendState::METHOD_OPENANONYMISER][0]['record']['engineVersion']);
	}
}
