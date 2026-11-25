<?php

/**
 * Tests for PublicFormPageController: the public page of a form open to visitors.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Error;
use OCA\OpenRegister\Controller\PublicFormPageController;
use OCA\OpenRegister\Service\Form\PublicFormDefinition;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The page hands the form to its script only when the form is open to visitors.
 *
 * @covers \OCA\OpenRegister\Controller\PublicFormPageController
 */
class PublicFormPageControllerTest extends TestCase {

	/**
	 * Open the page for a form the reader answers with.
	 *
	 * @param array<string, mixed>|null $form What the reader answers.
	 *
	 * @return array{0: mixed, 1: PublicTemplateResponse|null} The handed state and the page, null outside Nextcloud.
	 */
	private function open(?array $form): array {
		$forms = $this->createMock(PublicFormDefinition::class);
		$forms->expects($this->once())->method('forVisitor')->with('form-1')->willReturn($form);

		$handed = 'nothing';
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->once())->method('provideInitialState')->willReturnCallback(
			static function (string $key, mixed $value) use (&$handed): void {
				TestCase::assertSame(PublicFormPageController::STATE_KEY, $key);
				$handed = $value;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new PublicFormPageController($this->createMock(IRequest::class), $forms, $initialState, $l10n);
		try {
			$page = $controller->show(formId: 'form-1');
		} catch (Error $outsideNextcloud) {
			// PublicTemplateResponse loads core scripts through OCP\Util, which
			// needs a running Nextcloud (as in AccessLinkPageControllerTest).
			$this->assertStringContainsString('AppScriptDependency', $outsideNextcloud->getMessage());
			$page = null;
		}

		return [$handed, $page];
	}//end open()

	/**
	 * An open form is handed to the page script.
	 *
	 * @return void
	 */
	public function testAnOpenFormIsHandedToThePage(): void {
		$form = ['id' => 'form-1', 'title' => 'Report a problem', 'description' => '', 'fields' => [['key' => 'location']], 'steps' => []];

		[$handed, $page] = $this->open(form: $form);

		$this->assertSame($form, $handed);
		if ($page !== null) {
			$this->assertSame(PublicFormPageController::TEMPLATE, $page->getTemplateName());
			$this->assertSame(Http::STATUS_OK, $page->getStatus());
		}
	}//end testAnOpenFormIsHandedToThePage()

	/**
	 * A form that is not open hands nothing to the page.
	 *
	 * @return void
	 */
	public function testAClosedFormHandsNothing(): void {
		[$handed, $page] = $this->open(form: null);

		$this->assertNull($handed);
		if ($page !== null) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $page->getStatus());
		}
	}//end testAClosedFormHandsNothing()

	/**
	 * The page needs no account, no CSRF token, and is rate limited for anonymous callers.
	 *
	 * @return void
	 */
	public function testThePageIsPublicAndRateLimited(): void {
		$method = new ReflectionMethod(PublicFormPageController::class, 'show');

		$this->assertNotEmpty($method->getAttributes(PublicPage::class));
		$this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
		$this->assertNotEmpty($method->getAttributes(AnonRateLimit::class));
	}//end testThePageIsPublicAndRateLimited()

	/**
	 * The page is routed at /forms/{formId}, its template exists and its script is a build entry.
	 *
	 * @return void
	 */
	public function testThePageIsRoutedWithItsTemplateAndScript(): void {
		$root = dirname(__DIR__, 3);
		$routes = require $root . '/appinfo/routes.php';
		$found = array_values(array_filter($routes['routes'], static fn (array $route): bool => $route['name'] === 'publicFormPage#show'));

		$this->assertSame([['name' => 'publicFormPage#show', 'url' => '/forms/{formId}', 'verb' => 'GET']], $found);
		$this->assertFileExists($root . '/templates/' . PublicFormPageController::TEMPLATE . '.php');
		$this->assertStringContainsString("'public-form.js'", (string)file_get_contents($root . '/webpack.config.js'));
	}//end testThePageIsRoutedWithItsTemplateAndScript()
}//end class
