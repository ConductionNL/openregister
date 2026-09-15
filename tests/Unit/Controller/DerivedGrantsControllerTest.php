<?php

/**
 * What a rule change reports, in the shape an administrator reads.
 *
 * The count is the product here, not a nicety: an access change nobody is told
 * about is the one that surprises an auditor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DerivedGrantsController;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Task 8.3, over the wire.
 *
 * @covers \OCA\OpenRegister\Controller\DerivedGrantsController
 */
class DerivedGrantsControllerTest extends TestCase {

	/**
	 * A controller over one store.
	 *
	 * @param DerivedGrantStore $store The store the case pins.
	 *
	 * @return DerivedGrantsController The controller under test.
	 */
	private function controllerFor(DerivedGrantStore $store): DerivedGrantsController {
		return new DerivedGrantsController(
			appName: 'openregister',
			request: $this->createMock(originalClassName: IRequest::class),
			store: $store
		);
	}//end controllerFor()

	/**
	 * The re-run reports what moved, in the shape an administrator reads.
	 *
	 * @return void
	 */
	public function testTheDerivedGrantReRunReportsWhatMoved(): void {
		$store = $this->createMock(originalClassName: DerivedGrantStore::class);
		$store->method('rules')->willReturn([['claim' => 'department', 'equals' => 'vergunningen']]);
		$store->method('reapply')->willReturn(
			[
				'users' => 3,
				'changed' => 1,
				'grantsBefore' => 2,
				'grantsAfter' => 1,
				'accounts' => ['bea'],
			]
		);

		$response = $this->controllerFor(store: $store)->reapply();

		$this->assertSame(200, $response->getStatus());
		$body = $response->getData();

		foreach (['ruleCount', 'users', 'changed', 'grantsBefore', 'grantsAfter', 'accounts'] as $key) {
			$this->assertArrayHasKey($key, $body, sprintf('the re-run lost its "%s" key', $key));
		}

		$this->assertSame(1, $body['ruleCount']);
		$this->assertSame(1, $body['changed']);
		$this->assertSame(['bea'], $body['accounts']);
	}//end testTheDerivedGrantReRunReportsWhatMoved()

	/**
	 * An instance with no rules says so, rather than reporting that nothing moved.
	 *
	 * The second reads as a rule that had no effect, which is the answer
	 * somebody debugging a rule would most like to be given by mistake.
	 *
	 * @return void
	 */
	public function testTheReRunSaysWhenNoRulesAreDeclared(): void {
		$store = $this->createMock(originalClassName: DerivedGrantStore::class);
		$store->method('rules')->willReturn([]);
		$store->expects($this->never())->method('reapply');

		$response = $this->controllerFor(store: $store)->reapply();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(0, $response->getData()['ruleCount']);
		$this->assertStringContainsString('No claim rules', $response->getData()['message']);
	}//end testTheReRunSaysWhenNoRulesAreDeclared()

}//end class
