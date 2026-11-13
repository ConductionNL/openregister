<?php

/**
 * Tests for the draft-set, draft-value and refusal paths of
 * {@see \OCA\OpenRegister\Controller\ConfigurationDeploymentController}.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ConfigurationDeploymentController;
use OCA\OpenRegister\Controller\Support\ConfigDeploymentControllerServices;
use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftsInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftTarget;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistryInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationScope;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewServiceInterface;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ConfigurationDeploymentControllerDraftsTest.
 */
#[CoversClass(ConfigurationDeploymentController::class)]
#[UsesClass(ConfigDeploymentControllerServices::class)]
#[UsesClass(ConfigurationDraftTarget::class)]
#[UsesClass(ConfigurationAddress::class)]
#[UsesClass(DeploymentRefusedException::class)]
#[UsesClass(ConfigurationScope::class)]
final class ConfigurationDeploymentControllerDraftsTest extends TestCase {

	/**
	 * Drafts service mock.
	 *
	 * @var ConfigurationDraftsInterface&MockObject
	 */
	private ConfigurationDraftsInterface&MockObject $drafts;

	/**
	 * Previews service mock.
	 *
	 * @var DeploymentPreviewServiceInterface&MockObject
	 */
	private DeploymentPreviewServiceInterface&MockObject $previews;

	/**
	 * Deployments service mock.
	 *
	 * @var DeploymentServiceInterface&MockObject
	 */
	private DeploymentServiceInterface&MockObject $deployments;

	protected function setUp(): void {
		parent::setUp();

		$this->drafts = $this->createMock(ConfigurationDraftsInterface::class);
		$this->previews = $this->createMock(DeploymentPreviewServiceInterface::class);
		$this->deployments = $this->createMock(DeploymentServiceInterface::class);
	}//end setUp()

	/**
	 * The controller with a request answering the given params.
	 *
	 * @param array<string,mixed> $params The request params.
	 *
	 * @return ConfigurationDeploymentController
	 */
	private function controller(array $params = []): ConfigurationDeploymentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

		return new ConfigurationDeploymentController(
			'openregister',
			$request,
			new ConfigDeploymentControllerServices(
				drafts: $this->drafts,
				previews: $this->previews,
				deployments: $this->deployments,
				explainer: $this->createMock(ConfigurationExplainer::class),
			),
			$this->createMock(ConfigurationKeyRegistryInterface::class)
		);
	}//end controller()

	/**
	 * An entity mock that serialises to the given array.
	 *
	 * @param class-string        $class The entity class.
	 * @param array<string,mixed> $json  What it serialises to.
	 *
	 * @return MockObject
	 */
	private function entity(string $class, array $json): MockObject {
		$entity = $this->createMock($class);
		$entity->method('jsonSerialize')->willReturn($json);

		return $entity;
	}//end entity()

	/**
	 * A refusal with the given status.
	 *
	 * @param int $status The HTTP status the refusal answers with.
	 *
	 * @return DeploymentRefusedException
	 */
	private function refusal(int $status): DeploymentRefusedException {
		return new DeploymentRefusedException(DeploymentRefusedException::REASON_UNKNOWN, 'no such thing', [], $status);
	}//end refusal()

	public function testShowReturnsTheSetWithItsDrafts(): void {
		$this->drafts->method('loadSet')->with('set-1')->willReturn($this->entity(ConfigurationDraftSet::class, ['uuid' => 'set-1']));
		$this->drafts->expects($this->once())->method('draftsIn')->with('set-1')->willReturn(
			[
				$this->entity(ConfigurationDraft::class, ['key' => 'rbac']),
				$this->entity(ConfigurationDraft::class, ['key' => 'llm']),
			]
		);

		$response = $this->controller()->show('set-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['set' => ['uuid' => 'set-1'], 'drafts' => [['key' => 'rbac'], ['key' => 'llm']]], $response->getData());
	}//end testShowReturnsTheSetWithItsDrafts()

	public function testDraftValueAddressesTheKeyOnTheGivenLayerAndAnswersCreated(): void {
		$captured = null;
		$this->drafts->expects($this->never())->method('draftRemoval');
		$this->drafts->expects($this->once())->method('draftValue')->willReturnCallback(
			function (ConfigurationDraftTarget $target, mixed $value) use (&$captured): ConfigurationDraft {
				$captured = [$target, $value];
				return $this->entity(ConfigurationDraft::class, ['key' => 'rbac', 'value' => true]);
			}
		);

		$response = $this->controller(['layer' => 'register', 'layerRef' => 'reg-7', 'key' => 'rbac', 'value' => true])->draftValue('set-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['key' => 'rbac', 'value' => true], $response->getData());
		$this->assertSame('set-1', $captured[0]->setUuid);
		$this->assertSame('register', $captured[0]->address->layer);
		$this->assertSame('reg-7', $captured[0]->address->layerRef);
		$this->assertSame('rbac', $captured[0]->address->configKey);
		$this->assertTrue($captured[1]);
	}//end testDraftValueAddressesTheKeyOnTheGivenLayerAndAnswersCreated()

	public function testDraftValueDefaultsToTheInstanceLayerWithoutAReference(): void {
		$captured = null;
		$this->drafts->method('draftValue')->willReturnCallback(
			function (ConfigurationDraftTarget $target) use (&$captured): ConfigurationDraft {
				$captured = $target;
				return $this->entity(ConfigurationDraft::class, []);
			}
		);

		$this->controller(['key' => 'rbac', 'value' => 'x'])->draftValue('set-1');

		$this->assertSame('instance', $captured->address->layer);
		$this->assertNull($captured->address->layerRef);
	}//end testDraftValueDefaultsToTheInstanceLayerWithoutAReference()

	public function testDraftValueWithRemovesDraftsARemovalInstead(): void {
		$this->drafts->expects($this->never())->method('draftValue');
		$this->drafts->expects($this->once())->method('draftRemoval')
			->with($this->callback(static fn (ConfigurationDraftTarget $target): bool => $target->address->configKey === 'llm'))
			->willReturn($this->entity(ConfigurationDraft::class, ['key' => 'llm', 'removes' => true]));

		$response = $this->controller(['key' => 'llm', 'removes' => true])->draftValue('set-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['key' => 'llm', 'removes' => true], $response->getData());
	}//end testDraftValueWithRemovesDraftsARemovalInstead()

	public function testARefusedDraftAnswersWithTheRefusal(): void {
		$this->drafts->method('draftValue')->willThrowException($this->refusal(Http::STATUS_CONFLICT));

		$response = $this->controller(['key' => 'rbac', 'value' => 1])->draftValue('set-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'unknown', 'message' => 'no such thing', 'refusals' => [], 'applied' => 0], $response->getData());
	}//end testARefusedDraftAnswersWithTheRefusal()

	public function testAPreviewOfAnUnknownSetAnswersNotFoundWithoutPreviewing(): void {
		$this->drafts->method('loadSet')->willThrowException($this->refusal(Http::STATUS_NOT_FOUND));
		$this->previews->expects($this->never())->method('preview');

		$response = $this->controller()->preview('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAPreviewOfAnUnknownSetAnswersNotFoundWithoutPreviewing()

	public function testDiscardReturnsTheDiscardedSet(): void {
		$this->drafts->expects($this->once())->method('discardSet')->with('set-1')
			->willReturn($this->entity(ConfigurationDraftSet::class, ['uuid' => 'set-1', 'state' => 'discarded']));

		$response = $this->controller()->discard('set-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['uuid' => 'set-1', 'state' => 'discarded'], $response->getData());
	}//end testDiscardReturnsTheDiscardedSet()

	public function testARefusedDiscardAnswersWithTheRefusal(): void {
		$this->drafts->method('discardSet')->willThrowException($this->refusal(Http::STATUS_CONFLICT));

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller()->discard('set-1')->getStatus());
	}//end testARefusedDiscardAnswersWithTheRefusal()

	public function testAnUnknownDeploymentAnswersNotFound(): void {
		$this->deployments->method('loadDeployment')->willThrowException($this->refusal(Http::STATUS_NOT_FOUND));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->deployment('dep-x')->getStatus());
	}//end testAnUnknownDeploymentAnswersNotFound()

	public function testAKnownDeploymentIsServed(): void {
		$this->deployments->method('loadDeployment')->with('dep-1')
			->willReturn($this->entity(ConfigurationDeployment::class, ['uuid' => 'dep-1']));

		$response = $this->controller()->deployment('dep-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['uuid' => 'dep-1'], $response->getData());
	}//end testAKnownDeploymentIsServed()

	public function testARefusedRollbackAnswersWithTheRefusalAndPassesTheName(): void {
		$this->deployments->expects($this->once())->method('rollback')->with('dep-1', 'undo friday')
			->willThrowException($this->refusal(Http::STATUS_CONFLICT));

		$response = $this->controller(['name' => 'undo friday'])->rollback('dep-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('unknown', $response->getData()['error']);
	}//end testARefusedRollbackAnswersWithTheRefusalAndPassesTheName()
}//end class
