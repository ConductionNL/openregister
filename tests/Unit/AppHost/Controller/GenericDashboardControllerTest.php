<?php

/**
 * AppHost GenericDashboardController — SPA shell and public-page routing tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Controller;

use OCA\OpenRegister\AppHost\Controller\GenericDashboardController;
use OCA\OpenRegister\AppHost\Service\PublicPageResolver;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * page()/catchAll() render the leaf's own index template; publicPage() opens
 * the shell anonymously only for a declared public path.
 */
class GenericDashboardControllerTest extends TestCase {
	private function controller(?PublicPageResolver $resolver = null, ?IInitialState $state = null): GenericDashboardController {
		return new GenericDashboardController('leafapp', $this->createMock(IRequest::class), $resolver, $state);
	}

	public function testPageRendersLeafAppIndexTemplate(): void {
		$response = $this->controller()->page();

		$this->assertSame('leafapp', $response->getApp());
		$this->assertSame('index', $response->getTemplateName());
	}

	public function testCatchAllServesTheSameLeafShell(): void {
		$response = $this->controller()->catchAll();

		$this->assertSame('leafapp', $response->getApp());
		$this->assertSame('index', $response->getTemplateName());
	}

	public function testPublicPageWithoutResolverIsGuest404(): void {
		$response = $this->controller()->publicPage('anything');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('core', $response->getApp());
		$this->assertSame('404', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
	}

	public function testDeclaredPublicPathServesPublicShellAndFlagsInitialState(): void {
		$shell = new TemplateResponse('leafapp', 'index', [], TemplateResponse::RENDER_AS_PUBLIC);

		$resolver = $this->createMock(PublicPageResolver::class);
		$resolver->expects($this->once())->method('isDeclared')->with('leafapp', '/public/forms/abc')->willReturn(true);
		$resolver->expects($this->once())->method('publicShell')->with('leafapp')->willReturn($shell);
		$resolver->expects($this->never())->method('respond');

		$state = $this->createMock(IInitialState::class);
		$state->expects($this->once())->method('provideInitialState')->with(PublicPageResolver::INITIAL_STATE_KEY, true);

		$this->assertSame($shell, $this->controller($resolver, $state)->publicPage('/forms/abc'));
	}

	public function testDeclaredPublicPathWorksWithoutInitialStateService(): void {
		$shell = new TemplateResponse('leafapp', 'index', [], TemplateResponse::RENDER_AS_PUBLIC);

		$resolver = $this->createMock(PublicPageResolver::class);
		$resolver->method('isDeclared')->willReturn(true);
		$resolver->expects($this->once())->method('publicShell')->with('leafapp')->willReturn($shell);

		$this->assertSame($shell, $this->controller($resolver)->publicPage('forms'));
	}

	public function testUndeclaredPathReturnsResolverAnswer(): void {
		$redirect = new RedirectResponse('/login?redirect_url=/apps/leafapp/public/secret');

		$resolver = $this->createMock(PublicPageResolver::class);
		$resolver->expects($this->once())->method('isDeclared')->with('leafapp', '/public/secret')->willReturn(false);
		$resolver->expects($this->never())->method('publicShell');
		$resolver->expects($this->once())->method('respond')->with('leafapp', '/public/secret')->willReturn($redirect);

		$state = $this->createMock(IInitialState::class);
		$state->expects($this->never())->method('provideInitialState');

		$this->assertSame($redirect, $this->controller($resolver, $state)->publicPage('secret'));
	}

	public function testUndeclaredPathWithNoResolverAnswerFallsBackToOrdinaryShell(): void {
		$resolver = $this->createMock(PublicPageResolver::class);
		$resolver->method('isDeclared')->willReturn(false);
		$resolver->expects($this->once())->method('respond')->with('leafapp', '/public/')->willReturn(null);

		$response = $this->controller($resolver)->publicPage();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('leafapp', $response->getApp());
		$this->assertSame('index', $response->getTemplateName());
		$this->assertNotSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
	}
}
