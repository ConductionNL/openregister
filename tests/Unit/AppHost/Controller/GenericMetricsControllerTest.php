<?php

/**
 * AppHost GenericMetricsController — Prometheus exposition response tests.
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

use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsAccessGuard;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Observability\ObservabilityProbes;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The metrics endpoint renders the calling app's manifest through the engine
 * and returns it verbatim as Prometheus 0.0.4 text, to whoever the access guard
 * admits; everyone else gets the guard's refusal and no metric data.
 *
 * @covers \OCA\OpenRegister\AppHost\Controller\GenericMetricsController
 * @uses   \OCA\OpenRegister\AppHost\Observability\ObservabilityManifest
 * @uses   \OCA\OpenRegister\AppHost\Observability\ObservabilityProbes
 */
class GenericMetricsControllerTest extends TestCase {
	public function testRendersCallingAppManifestAsPrometheusText(): void {
		$manifest = new ObservabilityManifest('leafapp', new ObservabilityProbes([], []));
		$body = "# TYPE leafapp_objects gauge\nleafapp_objects 7\n";

		$loader = $this->createMock(ManifestLoader::class);
		$loader->expects($this->once())->method('load')->with('leafapp')->willReturn($manifest);

		$engine = $this->createMock(MetricsEngine::class);
		$engine->expects($this->once())->method('render')->with($this->identicalTo($manifest))->willReturn($body);

		$request = $this->createMock(IRequest::class);
		$controller = new GenericMetricsController('leafapp', $request, $loader, $engine, $this->guardAnswering(200, 'leafapp', $request));
		$response = $controller->index();

		$this->assertSame($body, $response->render());
		$this->assertSame(PrometheusRenderer::CONTENT_TYPE, $response->getHeaders()['Content-Type']);
		$this->assertSame(200, $response->getStatus());
	}

	public function testAnAnonymousRefusalIs401WithoutMetricData(): void {
		$response = $this->refusedController(status: 401, presentsToken: false)->index();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame('Unauthorized', $response->render());
		$this->assertFalse($response->isThrottled());
	}

	public function testANonAdminRefusalIs403WithoutMetricData(): void {
		$response = $this->refusedController(status: 403, presentsToken: false)->index();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('Forbidden', $response->render());
		$this->assertFalse($response->isThrottled());
	}

	public function testAWrongScraperTokenIsThrottled(): void {
		$response = $this->refusedController(status: 403, presentsToken: true)->index();

		$this->assertSame(403, $response->getStatus());
		$this->assertTrue($response->isThrottled());
		$this->assertSame(['action' => 'apphost_metrics'], $response->getThrottleMetadata());
	}

	public function testWithoutAGuardNobodyIsServed(): void {
		$engine = $this->createMock(MetricsEngine::class);
		$engine->expects($this->never())->method('render');

		$controller = new GenericMetricsController('leafapp', $this->createMock(IRequest::class), $this->createMock(ManifestLoader::class), $engine);

		$this->assertSame(403, $controller->index()->getStatus());
	}

	public function testIndexStillDoesNotWaiveTheAdminCheckForSessions(): void {
		$method = new ReflectionMethod(GenericMetricsController::class, 'index');

		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
	}

	private function refusedController(int $status, bool $presentsToken): GenericMetricsController {
		$loader = $this->createMock(ManifestLoader::class);
		$loader->expects($this->never())->method('load');
		$engine = $this->createMock(MetricsEngine::class);
		$engine->expects($this->never())->method('render');

		$request = $this->createMock(IRequest::class);
		$guard = $this->guardAnswering($status, 'leafapp', $request);
		$guard->method('presentsToken')->with($request)->willReturn($presentsToken);

		return new GenericMetricsController('leafapp', $request, $loader, $engine, $guard);
	}

	private function guardAnswering(int $status, string $appId, IRequest $request): MetricsAccessGuard&\PHPUnit\Framework\MockObject\MockObject {
		$guard = $this->createMock(MetricsAccessGuard::class);
		$guard->expects($this->once())->method('decide')->with($appId, $this->identicalTo($request))->willReturn($status);

		return $guard;
	}
}
