<?php

declare(strict_types=1);

/**
 * The holder's page for an access link (#4061).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://conduction.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Error;
use OCA\OpenRegister\Controller\AccessLinkPageController;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The page is a public HTML page, not JSON, and hands only the anchor on.
 */
class AccessLinkPageControllerTest extends TestCase {

	/**
	 * A link opens a page for a person, carrying only the anchor to the script.
	 *
	 * @return void
	 */
	public function testTheLinkOpensAPublicPageNotJson(): void {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->once())
			->method('provideInitialState')
			->with('accessLinkAnchor', 'AnchorValueThatIsOpaque');

		$controller = new AccessLinkPageController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			initialState: $initialState,
			l10n: $this->createMock(IL10N::class)
		);

		try {
			$response = $controller->show(anchor: 'AnchorValueThatIsOpaque');
			$this->assertInstanceOf(PublicTemplateResponse::class, $response);
			$this->assertSame(AccessLinkPageController::TEMPLATE, $response->getTemplateName());
			$this->assertSame([], $response->getParams(), 'The page itself carries no record data.');
		} catch (Error $outsideNextcloud) {
			// PublicTemplateResponse loads core scripts through OCP\Util, which
			// needs a running Nextcloud (same as PublicPageResolverTest). Getting
			// that far proves a public HTML page, not a JSONResponse, is built.
			$this->assertStringContainsString('AppScriptDependency', $outsideNextcloud->getMessage());
		}
	}

	/**
	 * Someone without an account can reach the page.
	 *
	 * @return void
	 */
	public function testThePageIsPublic(): void {
		$method = new ReflectionMethod(AccessLinkPageController::class, 'show');

		$this->assertNotEmpty($method->getAttributes(PublicPage::class));
		$this->assertSame('OCP\\AppFramework\\Http\\Template\\PublicTemplateResponse', (string)$method->getReturnType());
	}
}
