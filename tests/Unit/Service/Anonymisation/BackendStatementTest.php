<?php

/**
 * Training use and retention per detection backend (REQ-ADI-003): the
 * product declares its own backends, an external one reads unknown until an
 * administrator states it, and a change to a statement is audited.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-training-use-and-retention-are-stated-per-backend-req-adi-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendCollaborators;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendPlatform;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\BackendStatement;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\OwnSettingsChangeRecorder;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Anonymisation\BackendStatement
 * @covers \OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService
 * @covers \OCA\OpenRegister\Service\Anonymisation\BackendInfo
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendCollaborators
 * @uses   \OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendPlatform
 * @uses   \OCA\OpenRegister\Service\Anonymisation\BackendState
 * @uses   \OCA\OpenRegister\Service\Anonymisation\CapacityPolicy
 * @uses   \OCA\OpenRegister\Service\Anonymisation\DetectorVersion
 * @uses   \OCA\OpenRegister\Service\Anonymisation\DetectorVersionHistory
 * @uses   \OCA\OpenRegister\Service\Anonymisation\DetectorVersionReader
 * @uses   \OCA\OpenRegister\Service\Anonymisation\OfficialsSource
 * @uses   \OCA\OpenRegister\Service\Anonymisation\ProbeResult
 * @uses   \OCA\OpenRegister\Service\Anonymisation\ProbeResultCache
 * @uses   \OCA\OpenRegister\Service\File\ArchiveBounds
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Files\Service\File\MaskForm
 * @uses   \OCA\OpenRegister\Service\Settings\EntityPatternSetSetting
 */
final class BackendStatementTest extends TestCase {
	use InMemoryAppConfigTrait;

	public function testTheInternalExAppIsDeclaredExcluded(): void {
		$backends = $this->backendState(settings: [
			'openAnonymiserSource' => 'internal',
			'anonymisation' => ['statements' => ['openanonymiser' => ['trainingUse' => 'permitted', 'retention' => 'P1Y']]],
		])['backends'];

		$declared = ['trainingUse' => 'excluded', 'retention' => 'none', 'declaredBy' => 'product'];
		self::assertSame($declared, $backends['openanonymiser']['statement']);
		self::assertSame($declared, $backends['regex']['statement']);
	}//end testTheInternalExAppIsDeclaredExcluded()

	public function testAnExternalBackendDefaultsToUnknown(): void {
		$backends = $this->backendState(settings: ['openAnonymiserSource' => 'external'])['backends'];

		$unknown = ['trainingUse' => 'unknown', 'retention' => 'unknown', 'declaredBy' => 'administrator'];
		foreach (['presidio', 'openanonymiser', 'llm', 'hybrid'] as $backend) {
			self::assertSame($unknown, $backends[$backend]['statement'], $backend);
		}

		self::assertSame('product', $backends['regex']['statement']['declaredBy']);
	}//end testAnExternalBackendDefaultsToUnknown()

	public function testAStatedExternalBackendReportsTheStatement(): void {
		$backends = $this->backendState(settings: [
			'openAnonymiserSource' => 'external',
			'anonymisation' => ['statements' => [
				'presidio' => ['trainingUse' => 'excluded', 'retention' => 'P30D'],
				'openanonymiser' => ['trainingUse' => 'permitted', 'retention' => 'none'],
			]],
		])['backends'];

		self::assertSame(['trainingUse' => 'excluded', 'retention' => 'P30D', 'declaredBy' => 'administrator'], $backends['presidio']['statement']);
		self::assertSame(['trainingUse' => 'permitted', 'retention' => 'none', 'declaredBy' => 'administrator'], $backends['openanonymiser']['statement']);
	}//end testAStatedExternalBackendReportsTheStatement()

	public function testAValueThatIsNotAnAllowedFormReadsUnknown(): void {
		self::assertSame(['trainingUse' => 'unknown', 'retention' => 'unknown'], BackendStatement::stated(stored: ['trainingUse' => 'sometimes', 'retention' => '30 days'])->toStored());
		self::assertSame(['trainingUse' => 'unknown', 'retention' => 'unknown'], BackendStatement::stated(stored: ['trainingUse' => 1, 'retention' => ['P1D']])->toStored());
		self::assertSame(['trainingUse' => 'unknown', 'retention' => 'unknown'], BackendStatement::stated(stored: 'excluded')->toStored());
		self::assertSame('unknown', BackendStatement::stated(stored: ['retention' => 'P'])->retention);
		self::assertSame('unknown', BackendStatement::stated(stored: ['retention' => 'PT'])->retention);
		self::assertSame('PT12H', BackendStatement::stated(stored: ['retention' => ' PT12H '])->retention);
		self::assertSame('P1Y2M3W4DT5H6M7S', BackendStatement::stated(stored: ['retention' => 'P1Y2M3W4DT5H6M7S'])->retention);
		self::assertTrue(BackendStatement::isProductDeclared(backend: 'regex', openAnonymiserSource: 'external'));
		self::assertFalse(BackendStatement::isProductDeclared(backend: 'openanonymiser', openAnonymiserSource: 'external'));
		self::assertFalse(BackendStatement::isProductDeclared(backend: 'presidio', openAnonymiserSource: 'internal'));
	}//end testAValueThatIsNotAnAllowedFormReadsUnknown()

	public function testAChangedStatementIsAudited(): void {
		$recorder = $this->createMock(OwnSettingsChangeRecorder::class);
		$auditKeys = ['fileManagement', OwnSettingsChangeRecorder::PLAIN_PREFIX . 'openAnonymiserPassword'];
		$recorder->expects(self::once())->method('snapshot')->with($auditKeys)->willReturn(['settings' => [], 'security' => []]);
		$recorder->expects(self::once())->method('record')->with(['settings' => [], 'security' => []], $auditKeys)->willReturn(1);
		$handler = new FileSettingsHandler(appConfig: $this->inMemoryAppConfig(), changeRecorder: $recorder);

		$saved = $handler->updateFileSettingsOnly(fileData: [
			'anonymisation' => [
				'confidenceThreshold' => 0.5,
				'statements' => [
					'presidio' => ['trainingUse' => 'excluded', 'retention' => 'P30D'],
					'regex' => ['trainingUse' => 'permitted', 'retention' => 'P1Y'],
					'llm' => ['trainingUse' => 'maybe'],
				],
			],
		]);

		$expected = [
			'presidio' => ['trainingUse' => 'excluded', 'retention' => 'P30D'],
			'llm' => ['trainingUse' => 'unknown', 'retention' => 'unknown'],
		];
		self::assertSame($expected, $saved['anonymisation']['statements']);
		$stored = json_decode($this->configValues['openregister/fileManagement'], true);
		self::assertSame($expected, $stored['anonymisation']['statements']);
		self::assertSame($expected, $handler->getFileSettingsOnly()['anonymisation']['statements']);
	}//end testAChangedStatementIsAudited()

	public function testASaveThatDoesNotNameTheStatementsKeepsThem(): void {
		$handler = new FileSettingsHandler(appConfig: $this->inMemoryAppConfig());
		$handler->updateFileSettingsOnly(fileData: ['anonymisation' => ['statements' => ['presidio' => ['trainingUse' => 'excluded', 'retention' => 'none']]]]);

		$saved = $handler->updateFileSettingsOnly(fileData: ['anonymisation' => ['confidenceThreshold' => 0.7]]);

		self::assertSame(['presidio' => ['trainingUse' => 'excluded', 'retention' => 'none']], $saved['anonymisation']['statements']);
		self::assertSame(0.7, $saved['anonymisation']['confidenceThreshold']);
	}//end testASaveThatDoesNotNameTheStatementsKeepsThem()

	public function testWithoutStatementsTheSettingsCarryNone(): void {
		$handler = new FileSettingsHandler(appConfig: $this->inMemoryAppConfig());

		$saved = $handler->updateFileSettingsOnly(fileData: ['anonymisation' => ['statements' => ['regex' => ['trainingUse' => 'permitted']]]]);

		self::assertArrayNotHasKey('statements', $saved['anonymisation']);
		self::assertArrayNotHasKey('statements', $handler->getFileSettingsOnly()['anonymisation']);
	}//end testWithoutStatementsTheSettingsCarryNone()

	/**
	 * The backend-state over the given file settings, as the admin API serialises it.
	 *
	 * @param array<string, mixed> $settings The file settings the service reads.
	 *
	 * @return array<string, mixed>
	 */
	private function backendState(array $settings): array {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);
		$files = $this->getMockBuilder(FileSettingsHandler::class)->disableOriginalConstructor()->onlyMethods(['getFileSettingsOnly'])->getMock();
		$files->method('getFileSettingsOnly')->willReturn(array_merge(['entityRecognitionEnabled' => true, 'entityRecognitionMethod' => 'regex', 'presidioApiEndpoint' => ''], $settings));
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);

		$service = new AnonymisationBackendService(
			new AnonymisationBackendPlatform($apps, $this->inMemoryAppConfig(), $caches, $this->createMock(IClientService::class)),
			new AnonymisationBackendCollaborators($files, $this->createMock(LoggerInterface::class), $this->createMock(ConnectionReporter::class))
		);

		return json_decode((string) json_encode($service->getState()), true);
	}//end backendState()
}//end class
