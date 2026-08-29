<?php

/**
 * The lifecycle API contract: refusals a client can act on.
 *
 * 🔴 THE STATUS CODE AND THE REASON ARE BOTH LOAD-BEARING. 409 says "the
 * request is fine, the flow's state refuses it" — which is true, and which
 * tells the editor to offer "create a draft" rather than to fix the payload.
 * The `reason` field is what lets it pick BETWEEN refusals: "this version is
 * published" and "this flow has no published version" want opposite buttons,
 * and parsing an English sentence to tell them apart is how a UI offers the
 * wrong one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FlowController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class FlowLifecycleControllerTest extends TestCase {

	/**
	 * @var FlowService|\PHPUnit\Framework\MockObject\MockObject The flow service.
	 */
	private $flows;

	/**
	 * @var FlowVersionService|\PHPUnit\Framework\MockObject\MockObject The version service.
	 */
	private $versions;

	/**
	 * @var FlowController The controller under test.
	 */
	private FlowController $controller;

	/**
	 * Build the controller over mocked collaborators, with rights granted.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->flows = $this->createMock(FlowService::class);
		$this->versions = $this->createMock(FlowVersionService::class);

		$auth = $this->createMock(OpenRegisterActionAuthService::class);
		$auth->method('can')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$this->controller = new FlowController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(EventCatalogService::class),
			$this->createMock(FlowNodeRegistry::class),
			$this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
			$this->createMock(FlowNodePreflight::class),
			$this->flows,
			new FlowAccess($session, $groups, $auth),
			$this->versions
		);
	}//end setUp()

	/**
	 * A flow the service will hand back.
	 *
	 * @return Flow The flow.
	 */
	private function aFlow(): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);

		return $flow;
	}//end aFlow()

	/**
	 * 🔴 EDITING A PUBLISHED FLOW IS A 409 WITH A MACHINE-READABLE REASON,
	 * never a 400 and never a silent success.
	 *
	 * @return void
	 */
	public function testEditingAPublishedFlowAnswers409WithAReason(): void {
		$this->flows->method('save')->willThrowException(
			new FlowLifecycleRefused(
				reason: FlowLifecycleRefused::REASON_IMMUTABLE,
				flowId: 'flow-1',
				state: FlowVersion::STATUS_PUBLISHED
			)
		);

		$response = $this->controller->update(id: 'flow-1');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(FlowLifecycleRefused::REASON_IMMUTABLE, $body['reason']);
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $body['lifecycleStatus']);
		$this->assertSame('flow-1', $body['flowId']);
	}//end testEditingAPublishedFlowAnswers409WithAReason()

	/**
	 * Publishing returns the version that went live.
	 *
	 * @return void
	 */
	public function testPublishingReturnsTheNewlyPublishedVersion(): void {
		$version = new FlowVersion();
		$version->setFlowUuid('flow-1');
		$version->setVersion(3);
		$version->setStatus(FlowVersion::STATUS_PUBLISHED);

		$this->flows->method('find')->willReturn($this->aFlow());
		$this->versions->method('publish')->willReturn($version);

		$body = $this->controller->publish(id: 'flow-1')->getData();

		$this->assertSame(3, $body['version']);
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $body['status']);
	}//end testPublishingReturnsTheNewlyPublishedVersion()

	/**
	 * Publishing something that is not a draft is refused with its own reason,
	 * distinct from the immutability one.
	 *
	 * @return void
	 */
	public function testPublishingANonDraftAnswers409(): void {
		$this->flows->method('find')->willReturn($this->aFlow());
		$this->versions->method('publish')->willThrowException(
			new FlowLifecycleRefused(
				reason: FlowLifecycleRefused::REASON_NOT_A_DRAFT,
				flowId: 'flow-1',
				state: FlowVersion::STATUS_PUBLISHED
			)
		);

		$response = $this->controller->publish(id: 'flow-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(FlowLifecycleRefused::REASON_NOT_A_DRAFT, $response->getData()['reason']);
	}//end testPublishingANonDraftAnswers409()

	/**
	 * Creating a draft answers 201 with the new version number.
	 *
	 * @return void
	 */
	public function testCreatingADraftAnswers201(): void {
		$draft = new FlowVersion();
		$draft->setFlowUuid('flow-1');
		$draft->setVersion(4);
		$draft->setStatus(FlowVersion::STATUS_DRAFT);

		$this->flows->method('find')->willReturn($this->aFlow());
		$this->versions->method('createDraft')->willReturn($draft);

		$response = $this->controller->draft(id: 'flow-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(4, $response->getData()['version']);
		$this->assertSame(FlowVersion::STATUS_DRAFT, $response->getData()['status']);
	}//end testCreatingADraftAnswers201()

	/**
	 * Deprecating when nothing is published is refused, not reported as a
	 * success that did nothing.
	 *
	 * @return void
	 */
	public function testDeprecatingWithNothingPublishedAnswers409(): void {
		$this->flows->method('find')->willReturn($this->aFlow());
		$this->versions->method('deprecate')->willThrowException(
			new FlowLifecycleRefused(
				reason: FlowLifecycleRefused::REASON_NOT_PUBLISHED,
				flowId: 'flow-1',
				state: null
			)
		);

		$response = $this->controller->deprecate(id: 'flow-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(FlowLifecycleRefused::REASON_NOT_PUBLISHED, $response->getData()['reason']);
	}//end testDeprecatingWithNothingPublishedAnswers409()

	/**
	 * A lifecycle action on a flow the caller cannot see is a 404, exactly as
	 * a read is — the id must never select a row the caller could not list.
	 *
	 * @return void
	 */
	public function testALifecycleActionOnAnInvisibleFlowIs404(): void {
		$this->flows->method('find')->willThrowException(new DoesNotExistException('nope'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->publish(id: 'ghost')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->draft(id: 'ghost')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->deprecate(id: 'ghost')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->versions(id: 'ghost')->getStatus());
	}//end testALifecycleActionOnAnInvisibleFlowIs404()

	/**
	 * 🔴 RUNNING AN UNPUBLISHED FLOW IS A 409, NOT A 500.
	 *
	 * This escaped as an unhandled exception and reached the client as an HTML
	 * error page — "the server is broken" for what is actually "publish this
	 * flow first". Every unit test passed, because they all asserted on the
	 * EXCEPTION rather than on the response a caller actually receives. The e2e
	 * caught it; this test exists so a unit run catches it next time.
	 *
	 * @return void
	 */
	public function testRunningAnUnpublishedFlowAnswers409NotAFault(): void {
		$this->flows->method('run')->willThrowException(
			new FlowLifecycleRefused(
				reason: FlowLifecycleRefused::REASON_NO_PUBLISHED_VERSION,
				flowId: 'flow-1',
				state: null
			)
		);

		$response = $this->controller->run(id: 'flow-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			FlowLifecycleRefused::REASON_NO_PUBLISHED_VERSION,
			$response->getData()['reason']
		);
	}//end testRunningAnUnpublishedFlowAnswers409NotAFault()

	/**
	 * Listing versions returns them with a total, like every other list here.
	 *
	 * @return void
	 */
	public function testListingVersionsReturnsThemWithATotal(): void {
		$one = new FlowVersion();
		$one->setVersion(2);
		$two = new FlowVersion();
		$two->setVersion(1);

		$this->flows->method('find')->willReturn($this->aFlow());
		$this->versions->method('versionsOf')->willReturn([$one, $two]);

		$body = $this->controller->versions(id: 'flow-1')->getData();

		$this->assertSame(2, $body['total']);
		$this->assertSame(2, $body['results'][0]['version']);
	}//end testListingVersionsReturnsThemWithATotal()

	/**
	 * Reading one version carries the graph it names, so the editor can render
	 * a historical version read-only without a second round trip.
	 *
	 * @return void
	 */
	public function testReadingOneVersionCarriesItsGraph(): void {
		$version = new FlowVersion();
		$version->setVersion(2);
		$version->setDefinitionHash('abc');

		$this->flows->method('find')->willReturn($this->aFlow());
		$this->versions->method('versionOf')->willReturn($version);
		$this->versions->method('graphOfVersion')->willReturn(['nodes' => [['id' => 'a']], 'edges' => []]);

		$body = $this->controller->version(id: 'flow-1', version: 2)->getData();

		$this->assertSame('a', $body['graph']['nodes'][0]['id']);
	}//end testReadingOneVersionCarriesItsGraph()

	/**
	 * A version number that does not exist is a 404, not an empty 200 that a
	 * client would render as "this version has no steps".
	 *
	 * @return void
	 */
	public function testAnUnknownVersionNumberIs404(): void {
		$this->flows->method('find')->willReturn($this->aFlow());
		$this->versions->method('versionOf')->willReturn(null);

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->version(id: 'flow-1', version: 99)->getStatus()
		);
	}//end testAnUnknownVersionNumberIs404()
}//end class
