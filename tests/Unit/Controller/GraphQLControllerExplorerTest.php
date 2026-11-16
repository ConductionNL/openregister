<?php

/**
 * Contract tests for GraphQLController::explorer().
 *
 * Covers GET /api/graphql/explorer — the GraphiQL explorer page.
 *
 * The explorer is bundled with the app (api-explorer-in-the-app, REQ-APIX-001):
 * the page is a template that loads `openregister-graphqlExplorer.js` from the
 * instance, and its policy is Nextcloud's default. So the response allows no
 * outside script domain (no unpkg.com), no inline or eval script, and only the
 * instance's own workers, which the bundled editor starts.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 * @spec openspec/changes/api-explorer-in-the-app/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Search\Controller\GraphQLController;
use OCA\OpenRegister\Search\Controller\Support\GraphQLControllerServices;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class GraphQLControllerExplorerTest extends TestCase {
	/**
	 * The controller under test.
	 *
	 * @var GraphQLController
	 */
	private GraphQLController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->controller = new GraphQLController(
			$this->createMock(IRequest::class),
			new GraphQLControllerServices($this->createMock(GraphQLService::class))
		);
	}

	public function testTheExplorerIsTheBundledTemplate(): void {
		$response = $this->controller->explorer();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('openregister', $response->getApp());
		$this->assertSame('graphqlExplorer', $response->getTemplateName());
		$this->assertSame([], $response->getParams());
	}

	public function testTheTemplateLoadsTheBundledEntryAndNoOutsideScript(): void {
		$template = (string)file_get_contents(__DIR__ . '/../../../templates/graphqlExplorer.php');

		$this->assertStringContainsString("addEntryScripts(\$appId, 'graphqlExplorer', \$appId.'-graphqlExplorer')", $template);
		$this->assertStringContainsString('<div id="openregister-graphql-explorer"', $template);
		$this->assertStringNotContainsString('<script', $template);
		$this->assertStringNotContainsString('https://', $template);
	}

	public function testThePolicyAllowsNoOutsideOrInlineScript(): void {
		$policy = $this->controller->explorer()->getContentSecurityPolicy()->buildPolicy();

		$this->assertStringNotContainsString('unpkg.com', $policy);
		$this->assertStringNotContainsString("'unsafe-eval'", $policy);

		$directives = [];
		foreach (explode(';', $policy) as $directive) {
			$parts = preg_split('/\s+/', trim($directive));
			$directives[$parts[0]] = array_slice($parts, 1);
		}

		$this->assertArrayHasKey('script-src', $directives);
		$this->assertNotContains("'unsafe-inline'", $directives['script-src']);
		$this->assertSame(["'self'"], $directives['worker-src']);
	}
}
