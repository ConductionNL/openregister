<?php

/**
 * Unit tests for the organisation scope under a forced-anonymous evaluation.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The organisation filter has its own no-session shortcut: on the CLI, a call
 * without a user is treated as a trusted system operation and scoped to
 * everything. PHPUnit runs under the CLI SAPI, so this is the environment that
 * shortcut fires in — and a forced-anonymous evaluation must not inherit it.
 *
 * The observable here is the returned scope itself, not a mock expectation.
 */
class MagicOrganizationHandlerAnonymousScopeTest extends TestCase {

	private MagicOrganizationHandler $handler;


	protected function setUp(): void {
		parent::setUp();
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		// Past the system shortcut the handler resolves the caller's organisations
		// through the container. An anonymous caller has none.
		$organisationService = new class {
			public function getUserActiveOrganisations(): array {
				return [];
			}

			public function getActiveOrganisation(): ?object {
				return null;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisationService);

		$this->handler = new MagicOrganizationHandler(
			$userSession,
			$this->createMock(IGroupManager::class),
			$appConfig,
			$container,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()


	public function testWithoutTheScopeAnEmptyCliSessionScopesToEverything(): void {
		$this->assertSame('cli', PHP_SAPI, 'this test only means something under the CLI SAPI');

		$scope = $this->handler->resolveOrganizationScope();

		$this->assertSame(MagicOrganizationHandler::SCOPE_ALL, $scope['mode']);
	}//end testWithoutTheScopeAnEmptyCliSessionScopesToEverything()


	public function testInsideTheScopeTheSameSessionIsNotTreatedAsTheSystem(): void {
		$scope = AnonymousEvaluationContext::run(
			fn (): array => $this->handler->resolveOrganizationScope()
		);

		$this->assertNotSame(
			MagicOrganizationHandler::SCOPE_ALL,
			$scope['mode'],
			'an anonymous evaluation is a caller, not a system operation'
		);
	}//end testInsideTheScopeTheSameSessionIsNotTreatedAsTheSystem()
}//end class
