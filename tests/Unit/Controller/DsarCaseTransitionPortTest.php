<?php

/**
 * The DSAR case transition endpoint moves the case through the Compliance-owned transition port.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dsar-case-api/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DsarCaseController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Compliance\LifecycleTransitionInterface;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\DsarCaseControllerFactory;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The lifecycle engine (Processes) answers the port and binds it in the Processes map; the endpoint calls it once.
 */
final class DsarCaseTransitionPortTest extends TestCase {

	public function testTheLifecycleEngineAnswersThePort(): void {
		$this->assertTrue(is_subclass_of(TransitionEngine::class, LifecycleTransitionInterface::class));
		$this->assertSame(TransitionEngine::class, FlowWiring::BINDINGS[LifecycleTransitionInterface::class]);
	}

	public function testTheEndpointMovesTheCaseThroughThePort(): void {
		$saved = new ObjectEntity();
		$saved->setUuid('case-1');

		$transitions = $this->createMock(LifecycleTransitionInterface::class);
		$transitions->expects($this->once())->method('transition')->with('case-1', 'close')->willReturn($saved);

		$response = $this->controller(transitions: $transitions, action: 'close')->transition('case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($saved->jsonSerialize(), $response->getData());
	}

	public function testARefusedMoveIsForbidden(): void {
		$transitions = $this->createMock(LifecycleTransitionInterface::class);
		$transitions->method('transition')->willThrowException(new RuntimeException('not allowed from this state'));

		$response = $this->controller(transitions: $transitions, action: 'close')->transition('case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'not allowed from this state'], $response->getData());
	}

	public function testNoActionIsABadRequestAndMovesNothing(): void {
		$transitions = $this->createMock(LifecycleTransitionInterface::class);
		$transitions->expects($this->never())->method('transition');

		$response = $this->controller(transitions: $transitions, action: '')->transition('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/**
	 * A controller for an authenticated handler who may act on case-1.
	 *
	 * @param LifecycleTransitionInterface $transitions The port double.
	 * @param string                    $action      The posted action.
	 *
	 * @return DsarCaseController
	 */
	private function controller(LifecycleTransitionInterface $transitions, string $action): DsarCaseController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'action' ? $action : null);

		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$accessor = $this->createMock(CaseObjectAccessor::class);
		$accessor->method('load')->willReturn($case);
		$access = $this->createMock(CaseAccessControl::class);
		$access->method('mayAct')->willReturn(true);

		return DsarCaseControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(ObjectService::class),
			$accessor,
			$access,
			$transitions,
			$this->createMock(EvidenceHarvestService::class),
			$this->createMock(RedactionWriteService::class),
			$this->createMock(ExportBundleService::class),
			$session,
			$this->createMock(DsarPolicyPackResolver::class),
			$this->createMock(IdentityVerifyRegistry::class),
			$this->createMock(RegulatorEscalateRegistry::class)
		);
	}
}
