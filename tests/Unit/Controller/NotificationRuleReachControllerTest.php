<?php

/**
 * The administrator's read of rules that reached nobody, over HTTP.
 *
 * The method carries no `#[NoAdminRequired]`, so Nextcloud's middleware
 * refuses a caller who is not an administrator before the controller runs;
 * one test pins that posture by reflection.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Controller\NotificationRuleReachController;
use OCA\OpenRegister\Notifications\Service\Notification\RuleReachLedger;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Notifications\Controller\NotificationRuleReachController
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RuleReachLedger
 */
class NotificationRuleReachControllerTest extends TestCase {

	public function testTheReportComesFromTheLedger(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueArray')->willReturn(
			['case-assigned' => ['occurrences' => 12, 'lastSeen' => 1700000000]]
		);
		$controller = new NotificationRuleReachController(
			request: $this->createMock(IRequest::class),
			ledger: new RuleReachLedger(appConfig: $config)
		);

		$response = $controller->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'rulesReachingNobody' => [['rule' => 'case-assigned', 'occurrences' => 12, 'lastSeen' => '2023-11-14T22:13:20Z']],
				'ruleCount' => 1,
				'occurrences' => 12,
				'needsAPerson' => true,
			],
			$response->getData()
		);
	}//end testTheReportComesFromTheLedger()

	public function testOnlyAnAdministratorReachesTheRead(): void {
		$method = new ReflectionMethod(NotificationRuleReachController::class, 'index');

		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
		$this->assertSame([], $method->getAttributes(PublicPage::class));
		$this->assertStringNotContainsString('@NoAdminRequired', (string)$method->getDocComment());
		$this->assertStringNotContainsString('@PublicPage', (string)$method->getDocComment());
	}//end testOnlyAnAdministratorReachesTheRead()
}//end class
