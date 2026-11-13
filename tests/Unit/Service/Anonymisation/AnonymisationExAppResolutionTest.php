<?php

/**
 * Which OpenAnonymiser ExApp the anonymiser talks to, and what it does without one.
 *
 * The full variant wins over the light one, nothing is chosen without AppAPI,
 * and a request with no ExApp answers null without reaching AppAPI.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Anonymisation;

use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendCollaborators;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendPlatform;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\Anonymisation\AnonymiserCall;
use OCA\OpenRegister\Service\Anonymisation\ProbeResultCache;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(AnonymisationBackendService::class)]
#[UsesClass(AnonymisationBackendPlatform::class)]
#[UsesClass(AnonymisationBackendCollaborators::class)]
#[UsesClass(AnonymiserCall::class)]
#[UsesClass(ProbeResultCache::class)]
final class AnonymisationExAppResolutionTest extends TestCase {

	/**
	 * The service over an app manager that reports these apps enabled.
	 *
	 * @param list<string> $enabled The enabled app ids.
	 *
	 * @return AnonymisationBackendService
	 */
	private function service(array $enabled): AnonymisationBackendService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(
			static fn (string $app): bool => in_array($app, $enabled, true)
		);

		return new AnonymisationBackendService(
			new AnonymisationBackendPlatform($apps, $this->createMock(IAppConfig::class), $this->createMock(ICacheFactory::class), $this->createMock(IClientService::class)),
			new AnonymisationBackendCollaborators(
				$this->createMock(FileSettingsHandler::class),
				$this->createMock(LoggerInterface::class),
				$this->createMock(ConnectionReporter::class)
			)
		);
	}

	/**
	 * @return array<string, array{list<string>, string|null}>
	 */
	public static function installations(): array {
		return [
			'both variants: the full one' => [['app_api', 'openanonymiser', 'openanonymiser_light'], 'openanonymiser'],
			'only the light variant' => [['app_api', 'openanonymiser_light'], 'openanonymiser_light'],
			'only the full variant' => [['app_api', 'openanonymiser'], 'openanonymiser'],
			'AppAPI without a variant' => [['app_api'], null],
			'a variant without AppAPI' => [['openanonymiser', 'openanonymiser_light'], null],
			'nothing' => [[], null],
		];
	}

	/**
	 * @param list<string> $enabled  The enabled app ids.
	 * @param string|null  $expected The ExApp chosen.
	 */
	#[DataProvider('installations')]
	public function testTheActiveExAppFollowsWhatIsEnabled(array $enabled, ?string $expected): void {
		$this->assertSame($expected, $this->service($enabled)->resolveActiveExAppId());
	}

	public function testARequestWithoutAnExAppAnswersNull(): void {
		$call = new AnonymiserCall(route: '/api/v1/analyze', params: ['text' => 'Jan'], method: 'POST');

		$this->assertNull($this->service(['app_api'])->requestOpenAnonymiser($call));
	}
}
