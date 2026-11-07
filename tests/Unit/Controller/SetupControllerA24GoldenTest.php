<?php

/**
 * Golden outcomes of SetupController's admin-only paths, pinned before a refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SetupController;
use OCA\OpenRegister\Service\DemoDataServiceInterface;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the admin-setting posture and the exact writes + bodies of every action.
 */
class SetupControllerA24GoldenTest extends TestCase {

	/**
	 * appConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * demoData.
	 *
	 * @var DemoDataServiceInterface&MockObject
	 */
	private DemoDataServiceInterface&MockObject $demoData;

	/**
	 * Stored app-config values.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Recorded writes, in order.
	 *
	 * @var array<int, string>
	 */
	private array $writes = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('openregister', $app);
				$this->writes[] = $key . '=' . $value;
				$this->stored[$key] = $value;
				return true;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->demoData = $this->createMock(DemoDataServiceInterface::class);
		$this->demoData->method('listChoices')->willReturn([['id' => 'none'], ['id' => 'demo'], ['id' => '7']]);
	}//end setUp()

	/**
	 * Build the controller over a request carrying these params.
	 *
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return SetupController
	 */
	private function controller(array $params = []): SetupController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		return new SetupController($request, $this->appConfig, $this->logger, $this->demoData);
	}//end controller()

	/**
	 * Every action needs the admin setting.
	 *
	 * @return void
	 */
	public function testEveryActionNeedsTheAdminSetting(): void {
		foreach (['status', 'saveConfig', 'runAction'] as $name) {
			$method = new ReflectionMethod(SetupController::class, $name);
			$attributes = $method->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $name);
			$this->assertSame([OpenRegisterAdmin::class], $attributes[0]->getArguments(), $name);
			$this->assertSame([], $method->getAttributes(NoAdminRequired::class), $name);
			$this->assertSame([], $method->getAttributes(PublicPage::class), $name);
		}
	}

	/**
	 * Status body.
	 *
	 * @return void
	 */
	public function testStatusBody(): void {
		$this->stored['demo_dataset'] = 'demo';
		$this->assertSame(
			[
				'version' => 1,
				'completed' => true,
				'datasets' => [['id' => 'none'], ['id' => 'demo'], ['id' => '7']],
				'steps' => [
					'welcome' => ['done' => true],
					'demo-data' => ['done' => true],
					'done' => ['done' => true],
				],
			],
			$this->controller()->status()->getData()
		);
		$this->stored = ['demo_data_decided' => 'skipped'];
		$this->assertTrue($this->controller()->status()->getData()['steps']['demo-data']['done']);
		$this->stored = [];
		$this->assertFalse($this->controller()->status()->getData()['steps']['demo-data']['done']);
	}

	/**
	 * Save config outcomes.
	 *
	 * @return void
	 */
	public function testSaveConfigOutcomes(): void {
		$cases = [
			[[], 200, ['success' => true, 'config' => []], []],
			[['demo_dataset' => 'nope'], 400, ['success' => false, 'message' => 'No dataset is called "nope".'], []],
			[['demo_dataset' => ['x' => 1]], 400, ['success' => false, 'message' => 'A dataset is named by a string.'], []],
			[['demo_dataset' => []], 400, ['success' => false, 'message' => 'A dataset is named by a string.'], []],
			[['demo_dataset' => ['demo']], 200, ['success' => true, 'config' => ['demo_dataset' => 'demo']], ['demo_dataset=demo']],
			[['demo_dataset' => 7], 200, ['success' => true, 'config' => ['demo_dataset' => '7']], ['demo_dataset=7']],
		];
		foreach ($cases as [$params, $status, $body, $writes]) {
			$this->writes = [];
			$r = $this->controller($params)->saveConfig();
			$this->assertSame([$status, $body, $writes], [$r->getStatus(), $r->getData(), $this->writes], json_encode($params));
		}
	}

	/**
	 * Dismiss and skip and unknown.
	 *
	 * @return void
	 */
	public function testDismissAndSkipAndUnknown(): void {
		$r = $this->controller()->runAction('dismiss-setup');
		$this->assertSame([200, ['success' => true, 'message' => 'Setup was closed.']], [$r->getStatus(), $r->getData()]);
		$this->assertSame(['demo_data_decided=dismissed'], $this->writes);
		$this->controller()->runAction('dismiss-setup');
		$this->assertSame(['demo_data_decided=dismissed'], $this->writes);

		$this->writes = [];
		$r = $this->controller()->runAction('skip-demo-data');
		$this->assertSame([200, ['success' => true, 'message' => 'No example data was loaded.']], [$r->getStatus(), $r->getData()]);
		$this->assertSame(['demo_dataset=none', 'demo_data_decided=skipped'], $this->writes);

		$this->writes = [];
		$r = $this->controller()->runAction('drop-tables');
		$this->assertSame([404, ['success' => false, 'message' => 'Unknown setup action: drop-tables']], [$r->getStatus(), $r->getData()]);
		$this->assertSame([], $this->writes);
	}

	/**
	 * Load refusals.
	 *
	 * @return void
	 */
	public function testLoadRefusals(): void {
		$this->demoData->expects($this->never())->method('install');
		$r = $this->controller()->runAction('load-demo-data');
		$this->assertSame([400, ['success' => false, 'message' => 'Pick a dataset first.']], [$r->getStatus(), $r->getData()]);
		$r = $this->controller(['dataset' => 'bogus'])->runAction('install-demo-data');
		$this->assertSame([400, ['success' => false, 'message' => 'No dataset is called "bogus".']], [$r->getStatus(), $r->getData()]);
		$r = $this->controller(['dataset' => ['a']])->runAction('load-demo-data');
		$this->assertSame([400, ['success' => false, 'message' => 'A dataset is named by a string.']], [$r->getStatus(), $r->getData()]);
		$r = $this->controller(['dataset' => 'none'])->runAction('load-demo-data');
		$this->assertSame([200, ['success' => true, 'message' => 'No example data was loaded.']], [$r->getStatus(), $r->getData()]);
		$this->assertSame(['demo_dataset=none', 'demo_data_decided=skipped'], $this->writes);
	}

	/**
	 * Load succeeds for legacy stored and posted.
	 *
	 * @return void
	 */
	public function testLoadSucceedsForLegacyStoredAndPosted(): void {
		$this->demoData->method('install')->willReturn(['objects' => 12]);
		$r = $this->controller()->runAction('install-demo-data');
		$this->assertSame([200, ['success' => true, 'message' => 'Imported 12 demo object(s).']], [$r->getStatus(), $r->getData()]);
		$this->assertSame(['demo_dataset=demo', 'demo_data_decided=installed'], $this->writes);

		$this->writes = [];
		$this->stored = ['demo_dataset' => 'demo'];
		$this->controller()->runAction('load-demo-data');
		$this->assertSame(['demo_dataset=demo', 'demo_data_decided=installed'], $this->writes);

		$this->writes = [];
		$this->stored = ['demo_dataset' => 'none'];
		$this->controller(['dataset' => 7])->runAction('load-demo-data');
		$this->assertSame(['demo_dataset=7', 'demo_data_decided=installed'], $this->writes);
	}

	/**
	 * Load failure is logged and500.
	 *
	 * @return void
	 */
	public function testLoadFailureIsLoggedAnd500(): void {
		$e = new RuntimeException('broken');
		$this->demoData->method('install')->willThrowException($e);
		$this->logger->expects($this->once())->method('error')->with(
			'Setup install-demo-data failed: broken',
			['app' => 'openregister', 'exception' => $e]
		);
		$r = $this->controller(['dataset' => 'demo'])->runAction('load-demo-data');
		$this->assertSame([500, ['success' => false, 'message' => 'Could not import the demo data: broken']], [$r->getStatus(), $r->getData()]);
		$this->assertSame([], $this->writes);
	}
}//end class
