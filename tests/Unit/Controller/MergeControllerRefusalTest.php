<?php

/**
 * How the merge endpoints answer a refusal: a property the caller may not
 * read, a denied caller and a decision map that does not match the preview
 * each get their own status, and the decision map reaches the merge as sent.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\MergeController;
use OCA\OpenRegister\Controller\Support\MergeControllerServices;
use OCA\OpenRegister\Exception\MergeDecisionException;
use OCA\OpenRegister\Exception\MergeNotFullyReadableException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\MergeInstruction;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Refusal answers of MergeController.
 *
 * @covers \OCA\OpenRegister\Controller\MergeController
 * @uses   \OCA\OpenRegister\Controller\Support\MergeControllerServices
 * @uses   \OCA\OpenRegister\Exception\MergeDecisionException
 * @uses   \OCA\OpenRegister\Exception\MergeNotFullyReadableException
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Kernel\Contracts\MergeInstruction
 */
final class MergeControllerRefusalTest extends TestCase {

	/**
	 * The merge service.
	 *
	 * @var MergeService&MockObject
	 */
	private MergeService $merges;

	protected function setUp(): void {
		parent::setUp();
		$this->merges = $this->createMock(MergeService::class);
	}//end setUp()

	/**
	 * The controller over a request with the given parameters and signed-in user.
	 *
	 * @param array<string, mixed> $params The parameters.
	 * @param string|null          $uid    The signed-in user, or null.
	 *
	 * @return MergeController The controller.
	 */
	private function controller(array $params, ?string $uid = 'handler'): MergeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new MergeController(request: $request, services: new MergeControllerServices($this->merges, $session));
	}//end controller()

	public function testAPreviewOverUnreadablePropertiesIsForbiddenAndNamesThem(): void {
		$this->merges->method('previewMerge')->willThrowException(new MergeNotFullyReadableException(properties: ['bsn', 'iban']));

		$response = $this->controller(params: ['from' => 'a', 'into' => 'b'])->preview();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['error' => 'This merge touches properties you may not read.', 'properties' => ['bsn', 'iban']],
			$response->getData()
		);
	}//end testAPreviewOverUnreadablePropertiesIsForbiddenAndNamesThem()

	public function testAPreviewForADeniedCallerIsForbidden(): void {
		$this->merges->method('previewMerge')->willThrowException(new NotAuthorizedException('not yours'));

		$response = $this->controller(params: ['from' => 'a', 'into' => 'b'])->preview();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'not yours'], $response->getData());
	}//end testAPreviewForADeniedCallerIsForbidden()

	public function testAMismatchedDecisionMapIsUnprocessableAndNamesTheProperties(): void {
		$this->merges->method('merge')->willThrowException(new MergeDecisionException(properties: ['email']));

		$response = $this->controller(params: ['from' => 'a', 'into' => 'b', 'decisions' => ['email' => 'into']])->execute();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			['error' => 'The decision map does not match the preview.', 'properties' => ['email']],
			$response->getData()
		);
	}//end testAMismatchedDecisionMapIsUnprocessableAndNamesTheProperties()

	public function testAMergeOverUnreadablePropertiesIsForbiddenAndNamesThem(): void {
		$this->merges->method('merge')->willThrowException(new MergeNotFullyReadableException(properties: ['bsn']));

		$response = $this->controller(params: ['from' => 'a', 'into' => 'b'])->execute();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['bsn'], $response->getData()['properties']);
	}//end testAMergeOverUnreadablePropertiesIsForbiddenAndNamesThem()

	public function testTheDecisionMapAndTheMergerReachTheMerge(): void {
		$this->merges->expects($this->once())->method('merge')->with(
			$this->callback(
				static fn (MergeInstruction $i): bool => [$i->from, $i->into, $i->reason, $i->mergedBy] === ['a', 'b', 'duplicate', 'handler']
			),
			['email' => 'from']
		)->willReturn(['merged' => true]);

		$response = $this->controller(params: ['from' => 'a', 'into' => 'b', 'reason' => 'duplicate', 'decisions' => ['email' => 'from']])
			->execute();

		$this->assertSame(['merged' => true], $response->getData());
	}//end testTheDecisionMapAndTheMergerReachTheMerge()

	public function testADecisionMapThatIsNotAnArrayIsNoMapAndNoUserIsNoMerger(): void {
		$this->merges->expects($this->once())->method('merge')->with(
			$this->callback(static fn (MergeInstruction $i): bool => $i->mergedBy === ''),
			null
		)->willReturn([]);

		$this->controller(params: ['from' => 'a', 'into' => 'b', 'decisions' => 'all'], uid: null)->execute();
	}//end testADecisionMapThatIsNotAnArrayIsNoMapAndNoUserIsNoMerger()
}//end class
