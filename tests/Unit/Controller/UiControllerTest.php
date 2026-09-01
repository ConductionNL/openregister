<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\UiController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UiControllerTest extends TestCase {
	/**
	 * The controller under test.
	 *
	 * @var UiController
	 */
	private UiController $controller;

	/**
	 * The mocked HTTP request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);

		$this->controller = new UiController(
			'openregister',
			$this->request
		);
	}

	/**
	 * Broad sweep over every SPA-mount route name.
	 *
	 * @param string $method The controller action to invoke.
	 *
	 * @return void
	 *
	 * @dataProvider spaRouteProvider
	 */
	public function testSpaRoutesReturnTemplateResponse(string $method): void {
		$result = $this->controller->$method();

		$this->assertInstanceOf(TemplateResponse::class, $result);
		$this->assertEquals('index', $result->getTemplateName());
	}

	/**
	 * Contract assertion shared by every explicit SPA-mount test below.
	 *
	 * Every deep-link route must answer with the SAME shell: the `index`
	 * template of the `openregister` app, HTTP 200, and a CSP that permits
	 * `connect-src *` so the mounted SPA can reach the REST API. A route that
	 * 500s or renders `error` would still be a TemplateResponse, which is why
	 * the status and template name are both asserted.
	 *
	 * @param TemplateResponse $response The response returned by an SPA route.
	 *
	 * @return void
	 */
	private function assertSpaShell(TemplateResponse $response): void {
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('index', $response->getTemplateName());
		$this->assertSame('openregister', $response->getApp());
		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $response->getParams());
		$this->assertMatchesRegularExpression(
			'/connect-src[^;]*\*/',
			$response->getContentSecurityPolicy()->buildPolicy(),
			'SPA shell must relax connect-src so the mounted app can call the REST API'
		);
	}

	/**
	 * The explicit per-endpoint tests below deliberately do NOT go through
	 * spaRouteProvider(): a data-provider drives the call through a variable
	 * method name (`$this->controller->$method()`), which is invisible to any
	 * static reader of this file — including gate-25's contract-coverage
	 * matcher, which looks for a literal `->endpoint(` call. Each registered
	 * public SPA route therefore gets its own named, literal call site.
	 *
	 * @return void
	 */
	public function testRegistersDetailsServesSpaShell(): void {
		$this->assertSpaShell($this->controller->registersDetails());
	}

	public function testSchemasDetailsServesSpaShell(): void {
		$this->assertSpaShell($this->controller->schemasDetails());
	}

	public function testIntegrationsViewServesSpaShell(): void {
		$this->assertSpaShell($this->controller->integrationsView());
	}

	public function testTablesServesSpaShell(): void {
		$this->assertSpaShell($this->controller->tables());
	}

	public function testConfigurationsServesSpaShell(): void {
		$this->assertSpaShell($this->controller->configurations());
	}

	public function testSearchTrailServesSpaShell(): void {
		$this->assertSpaShell($this->controller->searchTrail());
	}

	public function testWebhooksServesSpaShell(): void {
		$this->assertSpaShell($this->controller->webhooks());
	}

	public function testWebhooksLogsServesSpaShell(): void {
		$this->assertSpaShell($this->controller->webhooksLogs());
	}

	public function testEntitiesServesSpaShell(): void {
		$this->assertSpaShell($this->controller->entities());
	}

	public function testEntitiesDetailsServesSpaShell(): void {
		$this->assertSpaShell($this->controller->entitiesDetails());
	}

	public function testAvgServesSpaShell(): void {
		$this->assertSpaShell($this->controller->avg());
	}

	public function testReportsServesSpaShell(): void {
		$this->assertSpaShell($this->controller->reports());
	}

	public function testReportViewServesSpaShell(): void {
		$this->assertSpaShell($this->controller->reportView());
	}

	public function testEndpointsServesSpaShell(): void {
		$this->assertSpaShell($this->controller->endpoints());
	}

	public function testEndpointLogsServesSpaShell(): void {
		$this->assertSpaShell($this->controller->endpointLogs());
	}

	public function testTemplatesServesSpaShell(): void {
		$this->assertSpaShell($this->controller->templates());
	}

	public function testFeaturesRoadmapServesSpaShell(): void {
		$this->assertSpaShell($this->controller->featuresRoadmap());
	}

	public function testMyAccountServesSpaShell(): void {
		$this->assertSpaShell($this->controller->myAccount());
	}

	public function testApplicationDetailsServesSpaShell(): void {
		$this->assertSpaShell($this->controller->applicationDetails());
	}

	/**
	 * `objectDetail` is a SEPARATE action from `objects` on purpose — OC's
	 * router drops a duplicate route name, so `/objects/{register}/{schema}/{id}`
	 * needs its own action. Both must mount the same shell.
	 *
	 * @return void
	 */
	public function testObjectDetailServesSameShellAsObjects(): void {
		$detail = $this->controller->objectDetail();
		$list = $this->controller->objects();

		$this->assertSpaShell($detail);
		$this->assertSame($list->getTemplateName(), $detail->getTemplateName());
		$this->assertSame($list->getStatus(), $detail->getStatus());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function spaRouteProvider(): array {
		return [
			'registers' => ['registers'],
			'registersDetails' => ['registersDetails'],
			'schemas' => ['schemas'],
			'schemasDetails' => ['schemasDetails'],
			'sources' => ['sources'],
			'organisation' => ['organisation'],
			'objects' => ['objects'],
			'tables' => ['tables'],
			// NOTE: 'chat' removed — UiController never had a chat() SPA
			// route; `chat#*` in appinfo/routes.php are API-only endpoints
			// (ChatController), not a TemplateResponse page.
			'configurations' => ['configurations'],
			'deleted' => ['deleted'],
			'auditTrail' => ['auditTrail'],
			'searchTrail' => ['searchTrail'],
			'webhooks' => ['webhooks'],
			'webhooksLogs' => ['webhooksLogs'],
			'entities' => ['entities'],
			'entitiesDetails' => ['entitiesDetails'],
			'endpoints' => ['endpoints'],
			'endpointLogs' => ['endpointLogs'],
		];
	}
}
