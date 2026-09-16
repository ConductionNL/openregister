<?php

/**
 * Contract tests for the configuration deployment endpoints.
 *
 * What a green service suite cannot tell you: that the routes exist, that a
 * refusal answers over the wire with the status the refusal chose rather than
 * a 500, and that no method here is reachable by an ordinary account.
 *
 * The posture assertion is the one worth keeping. Every route in this
 * controller changes instance configuration, and the only thing standing
 * between a signed-in account and that is the absence of #[NoAdminRequired]:
 * the middleware refuses before the method runs. Adding the attribute during a
 * refactor would not read as a regression anywhere else, so it reads as one
 * here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ConfigurationDeploymentController;
use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationExplainer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ConfigurationDeploymentControllerTest extends TestCase {

	/**
	 * Every routed method on this controller.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function routedMethods(): array {
		return [
			'index' => ['index'],
			'create' => ['create'],
			'show' => ['show'],
			'discard' => ['discard'],
			'draftValue' => ['draftValue'],
			'preview' => ['preview'],
			'approve' => ['approve'],
			'deploy' => ['deploy'],
			'deployments' => ['deployments'],
			'deployment' => ['deployment'],
			'rollback' => ['rollback'],
			'effective' => ['effective'],
		];
	}//end routedMethods()

	/**
	 * A request answering a staged set of parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest The request.
	 */
	private function request(array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

		return $request;
	}//end request()

	/**
	 * The controller over staged services.
	 *
	 * @param IRequest                        $request     The request.
	 * @param ConfigurationDraftService|null  $drafts      The draft service.
	 * @param DeploymentService|null          $deployments The deployment service.
	 * @param ConfigurationExplainer|null     $explainer   The explainer.
	 * @param DeploymentPreviewService|null   $previews    The preview service.
	 *
	 * @return ConfigurationDeploymentController The controller.
	 */
	private function controller(
		IRequest $request,
		?ConfigurationDraftService $drafts = null,
		?DeploymentService $deployments = null,
		?ConfigurationExplainer $explainer = null,
		?DeploymentPreviewService $previews = null
	): ConfigurationDeploymentController {
		return new ConfigurationDeploymentController(
			'openregister',
			$request,
			($drafts ?? $this->createMock(ConfigurationDraftService::class)),
			($previews ?? $this->createMock(DeploymentPreviewService::class)),
			($deployments ?? $this->createMock(DeploymentService::class)),
			($explainer ?? $this->createMock(ConfigurationExplainer::class)),
			new ConfigurationKeyRegistry()
		);
	}//end controller()

	/**
	 * @param string $method The controller method.
	 *
	 * @return void
	 *
	 * @dataProvider routedMethods
	 */
	public function testNoRouteHereIsReachableWithoutAdministratorRights(string $method): void {
		$reflection = new ReflectionMethod(ConfigurationDeploymentController::class, $method);

		$this->assertSame(
			[],
			$reflection->getAttributes(NoAdminRequired::class),
			$method.'() carries #[NoAdminRequired]; every route here changes instance configuration.'
		);
		$this->assertSame(
			[],
			$reflection->getAttributes(PublicPage::class),
			$method.'() carries #[PublicPage]; every route here changes instance configuration.'
		);
		$this->assertStringNotContainsString(
			'@NoAdminRequired',
			(string)$reflection->getDocComment(),
			$method.'() declares @NoAdminRequired in its docblock; the middleware would then let anybody through.'
		);
	}//end testNoRouteHereIsReachableWithoutAdministratorRights()

	public function testASetNeedsANameOverTheWire(): void {
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('openSet')->willThrowException(
			new DeploymentRefusedException(
				DeploymentRefusedException::REASON_INVALID,
				'a draft set needs a name',
				[],
				Http::STATUS_BAD_REQUEST
			)
		);

		$response = $this->controller($this->request(), $drafts)->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid', $response->getData()['error']);
	}//end testASetNeedsANameOverTheWire()

	public function testAnOpenedSetComesBackAsCreated(): void {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-1');
		$set->setName('september tuning');
		$set->setState(ConfigurationDraftSet::STATE_OPEN);

		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('openSet')->willReturn($set);

		$response = $this->controller($this->request(['name' => 'september tuning']), $drafts)->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('set-1', $response->getData()['uuid']);
	}//end testAnOpenedSetComesBackAsCreated()

	public function testARefusedDeploymentAnswersWithItsOwnStatusAndNamesTheValue(): void {
		$deployments = $this->createMock(DeploymentService::class);
		$deployments->method('deploy')->willThrowException(
			new DeploymentRefusedException(
				DeploymentRefusedException::REASON_VALUE_REFUSED,
				'nothing was applied: "llm" refused (declared as object and the value is string)',
				[['key' => 'llm', 'reason' => 'declared as object and the value is string']]
			)
		);

		$response = $this->controller($this->request(), null, $deployments)->deploy('set-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('value-refused', $response->getData()['error']);
		$this->assertSame('llm', $response->getData()['refusals'][0]['key']);
		$this->assertSame(0, $response->getData()['applied']);
	}//end testARefusedDeploymentAnswersWithItsOwnStatusAndNamesTheValue()

	public function testAFourEyesRefusalAnswersForbiddenRatherThanConflict(): void {
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('approveSet')->willThrowException(
			new DeploymentRefusedException(
				DeploymentRefusedException::REASON_FOUR_EYES,
				'this instance requires an approver other than the author',
				[],
				Http::STATUS_FORBIDDEN
			)
		);

		$response = $this->controller($this->request(), $drafts)->approve('set-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('four-eyes', $response->getData()['error']);
	}//end testAFourEyesRefusalAnswersForbiddenRatherThanConflict()

	public function testAnUnknownSetAnswersNotFound(): void {
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('loadSet')->willThrowException(
			new DeploymentRefusedException(
				DeploymentRefusedException::REASON_UNKNOWN,
				'no draft set "nope"',
				[],
				Http::STATUS_NOT_FOUND
			)
		);

		$response = $this->controller($this->request(), $drafts)->show('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnknownSetAnswersNotFound()

	public function testARollbackComesBackAsCreatedAndNamesWhatItRestores(): void {
		$rollback = new ConfigurationDeployment();
		$rollback->setUuid('dep-2');
		$rollback->setName('rollback of friday afternoon');
		$rollback->setRestoresUuid('dep-1');
		$rollback->setChanges([['key' => 'rbac']]);

		$deployments = $this->createMock(DeploymentService::class);
		$deployments->method('rollback')->willReturn($rollback);

		$response = $this->controller($this->request(), null, $deployments)->rollback('dep-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('dep-1', $response->getData()['restores']);
		$this->assertTrue($response->getData()['isRollback']);
	}//end testARollbackComesBackAsCreatedAndNamesWhatItRestores()

	public function testTheExplainerNeedsAKey(): void {
		$response = $this->controller($this->request())->effective();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('key is required', $response->getData()['message']);
	}//end testTheExplainerNeedsAKey()

	public function testTheExplainerPassesTheLayerReferencesThrough(): void {
		$explainer = $this->createMock(ConfigurationExplainer::class);
		$explainer->expects($this->once())
			->method('explain')
			->with('integration.mail', 'zaken', 'standaard', 'zaak')
			->willReturn(['key' => 'integration.mail', 'found' => true]);

		$request = $this->request(
			[
				'key' => 'integration.mail',
				'register' => 'zaken',
				'bundle' => 'standaard',
				'subject' => 'zaak',
			]
		);

		$response = $this->controller($request, null, null, $explainer)->effective();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['found']);
	}//end testTheExplainerPassesTheLayerReferencesThrough()

	public function testTheHistoryIsListedNewestFirstWithATotal(): void {
		$first = new ConfigurationDeployment();
		$first->setUuid('dep-2');
		$second = new ConfigurationDeployment();
		$second->setUuid('dep-1');

		$deployments = $this->createMock(DeploymentService::class);
		$deployments->method('history')->willReturn([$first, $second]);

		$response = $this->controller($this->request(), null, $deployments)->deployments();

		$this->assertSame(2, $response->getData()['total']);
		$this->assertSame('dep-2', $response->getData()['results'][0]['uuid']);
	}//end testTheHistoryIsListedNewestFirstWithATotal()

	public function testTheVocabularyIsServedSoACallerNeedNotGuess(): void {
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('listSets')->willReturn([]);

		$vocabulary = $this->controller($this->request(), $drafts)->index()->getData()['vocabulary'];

		$this->assertSame('object', $vocabulary['instanceKeys']['rbac']);
		$this->assertContains('integration.', $vocabulary['openPrefixes']);
		$this->assertSame(['instance', 'register', 'bundle', 'subject'], $vocabulary['layers']);
		// The two keys that govern deployments are visible AS reserved, with
		// the reason, rather than simply absent. Absent reads as an oversight.
		$this->assertArrayHasKey('configuration_four_eyes', $vocabulary['reserved']);
		$this->assertArrayHasKey('configuration_drafting', $vocabulary['reserved']);
	}//end testTheVocabularyIsServedSoACallerNeedNotGuess()

	public function testThePreviewIsServedForAKnownSet(): void {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-1');

		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('loadSet')->willReturn($set);

		$previews = $this->createMock(DeploymentPreviewService::class);
		$previews->method('preview')->willReturn(
			['counts' => ['toChange' => 2, 'unchanged' => 0, 'refused' => 0], 'deployable' => true]
		);

		$response = $this->controller($this->request(), $drafts, null, null, $previews)->preview('set-1');

		$this->assertTrue($response->getData()['deployable']);
		$this->assertSame(2, $response->getData()['counts']['toChange']);
	}//end testThePreviewIsServedForAKnownSet()
}//end class
