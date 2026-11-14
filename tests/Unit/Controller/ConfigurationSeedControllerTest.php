<?php

/**
 * Unit tests for ConfigurationSeedController.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ConfigurationSeedController;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSeedService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Seeding stages the working defaults as drafts and answers 201; a blank name
 * is passed through as null; a deployment refusal is answered with its own body
 * and status code.
 *
 * @covers \OCA\OpenRegister\Controller\ConfigurationSeedController
 * @uses \OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
 */
class ConfigurationSeedControllerTest extends TestCase {

	/** @var ConfigurationSeedService&MockObject */
	private ConfigurationSeedService $seeds;

	/** @var IRequest&MockObject */
	private IRequest $request;

	protected function setUp(): void {
		$this->seeds = $this->createMock(ConfigurationSeedService::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	private function controller(): ConfigurationSeedController {
		return new ConfigurationSeedController('openregister', $this->request, $this->seeds);
	}//end controller()

	/**
	 * Stub getParam('name') with the given value.
	 *
	 * @param string|null $name The `name` param value.
	 *
	 * @return void
	 */
	private function withName($name): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($name) {
				if ($key === 'name') {
					return $name;
				}

				return $default;
			}
		);
	}//end withName()

	public function testSeedingStagesTheDefaultsAnd201(): void {
		$this->withName('production');
		$this->seeds->expects($this->once())->method('seed')->with('production')->willReturn(['staged' => 3, 'skipped' => []]);

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['staged' => 3, 'skipped' => []], $response->getData());
	}//end testSeedingStagesTheDefaultsAnd201()

	public function testABlankNameIsPassedAsNull(): void {
		$this->withName('   ');
		$this->seeds->expects($this->once())->method('seed')->with(null)->willReturn(['staged' => 0]);

		$this->controller()->create();
	}//end testABlankNameIsPassedAsNull()

	public function testAMissingNameIsPassedAsNull(): void {
		$this->withName(null);
		$this->seeds->expects($this->once())->method('seed')->with(null)->willReturn([]);

		$this->controller()->create();
	}//end testAMissingNameIsPassedAsNull()

	public function testADeploymentRefusalAnswersWithItsOwnBodyAndStatus(): void {
		$this->withName('production');
		$refusal = new DeploymentRefusedException('conflict', 'a value already set', [], Http::STATUS_CONFLICT);
		$this->seeds->method('seed')->willThrowException($refusal);

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['error' => 'conflict', 'message' => 'a value already set', 'refusals' => [], 'applied' => 0],
			$response->getData()
		);
	}//end testADeploymentRefusalAnswersWithItsOwnBodyAndStatus()
}//end class
