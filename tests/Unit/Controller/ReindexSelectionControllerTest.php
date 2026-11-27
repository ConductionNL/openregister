<?php

/**
 * Tests for POST /api/operations/reindex: administrator only, 202 for a queued run, 400 for a refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\OperationsReindexController;
use OCA\OpenRegister\Service\Operations\ReindexRefusedException;
use OCA\OpenRegister\Service\Operations\ReindexSelectionServiceInterface;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(OperationsReindexController::class)]
#[UsesClass(ReindexRefusedException::class)]
final class ReindexSelectionControllerTest extends TestCase {

	private function controller(ReindexSelectionServiceInterface $reindex, array $params): OperationsReindexController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name): mixed => ($params[$name] ?? null));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new OperationsReindexController($request, $reindex, $session);
	}

	/**
	 * Nextcloud's middleware answers a non-administrator 403 before the
	 * controller is built, because the method declares no NoAdminRequired or
	 * PublicPage; the route is a POST, so CSRF stays required.
	 */
	public function testANonAdministratorGets403(): void {
		$method = new ReflectionMethod(OperationsReindexController::class, 'reindex');

		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
		$this->assertSame([], $method->getAttributes(PublicPage::class));
		$this->assertSame([], $method->getAttributes(NoCSRFRequired::class));
		$this->assertStringNotContainsString('@NoAdminRequired', (string)$method->getDocComment());
		$this->assertStringNotContainsString('@PublicPage', (string)$method->getDocComment());

		$routes = require __DIR__.'/../../../appinfo/routes.php';
		$matching = array_values(array_filter(
			$routes['routes'],
			static fn (array $route): bool => $route['name'] === 'operationsReindex#reindex' && $route['url'] === '/api/operations/reindex'
		));
		$this->assertCount(1, $matching);
		$this->assertSame('POST', $matching[0]['verb']);
	}

	public function testAQueuedRunAnswers202WithItsKeyAndTotal(): void {
		$reindex = $this->createMock(ReindexSelectionServiceInterface::class);
		$reindex->expects($this->once())->method('start')
			->with(['register' => 3, 'schema' => 7, 'filters' => ['wooVerzoek' => '2026-118'], 'ids' => null], 'admin')
			->willReturn(['run' => 'abc', 'kind' => 'filter', 'total' => 240]);

		$response = $this->controller(reindex: $reindex, params: ['register' => 3, 'schema' => 7, 'filters' => ['wooVerzoek' => '2026-118']])->reindex();

		$this->assertSame(202, $response->getStatus());
		$this->assertSame(['run' => 'abc', 'kind' => 'filter', 'total' => 240], $response->getData());
	}

	public function testARefusedSelectionAnswers400WithItsSentence(): void {
		$reindex = $this->createMock(ReindexSelectionServiceInterface::class);
		$reindex->method('start')->willThrowException(new ReindexRefusedException('Rebuild the whole index instead.'));

		$response = $this->controller(reindex: $reindex, params: ['register' => 3, 'schema' => 7])->reindex();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'refused', 'message' => 'Rebuild the whole index instead.'], $response->getData());
	}
}
