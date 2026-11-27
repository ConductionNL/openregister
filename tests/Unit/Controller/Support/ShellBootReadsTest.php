<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/shell-reads-from-initial-state/specs/apphost-boilerplate/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\SetupStatusReader;
use OCA\OpenRegister\Controller\Support\ShellBootReads;
use OCA\OpenRegister\Controller\UiController;
use OCA\OpenRegister\Search\Controller\DashboardController;
use OCA\OpenRegister\Search\Controller\Support\DashboardControllerServices;
use OCA\OpenRegister\Search\Service\DashboardService;
use OCA\OpenRegister\Service\DemoDataServiceInterface;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShellBootReadsTest extends TestCase {
	private IInitialState&MockObject $initialState;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private IConfig&MockObject $config;

	private IAppConfig&MockObject $appConfig;

	/**
	 * What the page was given, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $provided = [];

	protected function setUp(): void {
		parent::setUp();
		$this->initialState = $this->createMock(IInitialState::class);
		$this->initialState->method('provideInitialState')->willReturnCallback(function (string $key, $data): void {
			$this->provided[$key] = $data;
		});
		$this->userSession  = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config       = $this->createMock(IConfig::class);
		$this->appConfig    = $this->createMock(IAppConfig::class);
	}

	private function reads(): ShellBootReads {
		$demo = $this->createMock(DemoDataServiceInterface::class);
		$demo->method('listChoices')->willReturn([['id' => 'gemeente', 'label' => 'Gemeente']]);

		return new ShellBootReads(
			$this->initialState,
			$this->userSession,
			$this->groupManager,
			$this->config,
			new SetupStatusReader($this->appConfig, $demo)
		);
	}

	private function signIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($admin);
	}

	/**
	 * The stored values, keyed as PreferencesUserValues stores them.
	 *
	 * @param array<string, string> $stored Stored key => value.
	 */
	private function storedPreferences(array $stored): void {
		$this->config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, $default = '') => ($app === 'openregister' ? ($stored[$key] ?? $default) : $default)
		);
	}

	public function testAGuestPageCarriesNothing(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->reads()->provide();

		$this->assertSame([], $this->provided);
	}

	public function testAUserPageCarriesTheWalkthroughPreferencesTheEndpointWouldAnswer(): void {
		$this->signIn('pieter', false);
		$this->storedPreferences(['pref_walkthroughseenversion' => '2.1.0', 'pref_walkthroughseenversion-progress' => '{"tourId":"main","index":2}']);

		$this->reads()->provide();

		$this->assertSame(['seen' => '2.1.0', 'progress' => '{"tourId":"main","index":2}'], $this->provided['walkthrough_preferences']);
	}

	public function testAnUnsetPreferenceIsCarriedAsNullSoTheShellKnowsItWasNeverSeen(): void {
		$this->signIn('pieter', false);
		$this->storedPreferences([]);

		$this->reads()->provide();

		$this->assertSame(['seen' => null, 'progress' => null], $this->provided['walkthrough_preferences']);
	}

	public function testANonAdminPageLeavesTheAdminOnlySetupStatusOut(): void {
		$this->signIn('pieter', false);
		$this->storedPreferences([]);

		$this->reads()->provide();

		$this->assertArrayNotHasKey('setup_status', $this->provided);
	}

	public function testAnAdminPageCarriesTheSetupStatusDocument(): void {
		$this->signIn('admin', true);
		$this->storedPreferences([]);
		$this->appConfig->method('getValueString')->willReturnMap([
			['openregister', 'demo_data_decided', '', false, 'skipped'],
			['openregister', 'demo_dataset', '', false, ''],
		]);

		$this->reads()->provide();

		$this->assertSame(
			[
				'version'   => 1,
				'completed' => true,
				'datasets'  => [['id' => 'gemeente', 'label' => 'Gemeente']],
				'steps'     => ['welcome' => ['done' => true], 'demo-data' => ['done' => true], 'done' => ['done' => true]],
			],
			$this->provided['setup_status']
		);
	}

	public function testTheSpaRenderProvidesTheReads(): void {
		$this->signIn('admin', true);
		$this->storedPreferences([]);

		$response = (new UiController('openregister', $this->createMock(IRequest::class), $this->reads()))->registers();

		$this->assertSame('index', $response->getTemplateName());
		$this->assertSame(['walkthrough_preferences', 'setup_status'], array_keys($this->provided));
	}

	public function testTheSpaRenderWithoutReadsStillRenders(): void {
		$response = (new UiController('openregister', $this->createMock(IRequest::class)))->registers();

		$this->assertSame('index', $response->getTemplateName());
		$this->assertSame([], $this->provided);
	}

	public function testTheDashboardRenderProvidesTheReads(): void {
		$this->signIn('pieter', false);
		$this->storedPreferences([]);
		$services = new DashboardControllerServices(
			$this->createMock(DashboardService::class),
			new ControllerSession($this->userSession, $this->groupManager, $this->createMock(LoggerInterface::class))
		);

		$response = (new DashboardController($this->createMock(IRequest::class), $services, $this->reads()))->page();

		$this->assertSame('index', $response->getTemplateName());
		$this->assertSame(['walkthrough_preferences'], array_keys($this->provided));
	}
}
