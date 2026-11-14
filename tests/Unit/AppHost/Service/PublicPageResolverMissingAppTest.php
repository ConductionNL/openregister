<?php

/**
 * AppHost PublicPageResolver — an app whose install path cannot be found.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Service\PublicPageManifests;
use OCA\OpenRegister\AppHost\Service\PublicPageResolver;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * No install path means no manifest, so nothing is declared public; the miss
 * is logged at debug level and asked for once per app.
 */
#[CoversClass(PublicPageResolver::class)]
#[UsesClass(PublicPageManifests::class)]
class PublicPageResolverMissingAppTest extends TestCase {

	public function testAnAppWithoutAnInstallPathDeclaresNothing(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('getAppPath')->with('ghost')
			->willThrowException(new AppPathNotFoundException('Could not find path for ghost'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')
			->with('[PublicPageResolver] App path not found for ghost: Could not find path for ghost');

		$resolver = new PublicPageResolver(
			manifests: new PublicPageManifests($appManager, $logger),
			userSession: $this->createMock(IUserSession::class),
			urlGenerator: $this->createMock(IURLGenerator::class),
			request: $this->createMock(IRequest::class)
		);

		$this->assertFalse($resolver->isDeclared('ghost', '/public/status/tok'));
		$this->assertFalse($resolver->isDeclared('ghost', '/public/other'));
	}//end testAnAppWithoutAnInstallPathDeclaresNothing()
}//end class
