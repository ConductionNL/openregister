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
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Observability\ObservabilityProbes;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The metrics endpoint renders the calling app's manifest through the engine
 * and returns it verbatim as Prometheus 0.0.4 text, admin-only.
 */
class GenericMetricsControllerTest extends TestCase {
	public function testRendersCallingAppManifestAsPrometheusText(): void {
		$manifest = new ObservabilityManifest('leafapp', new ObservabilityProbes([], []));
		$body = "# TYPE leafapp_objects gauge\nleafapp_objects 7\n";

		$loader = $this->createMock(ManifestLoader::class);
		$loader->expects($this->once())->method('load')->with('leafapp')->willReturn($manifest);

		$engine = $this->createMock(MetricsEngine::class);
		$engine->expects($this->once())->method('render')->with($this->identicalTo($manifest))->willReturn($body);

		$controller = new GenericMetricsController('leafapp', $this->createMock(IRequest::class), $loader, $engine);
		$response = $controller->index();

		$this->assertSame($body, $response->render());
		$this->assertSame(PrometheusRenderer::CONTENT_TYPE, $response->getHeaders()['Content-Type']);
		$this->assertSame(200, $response->getStatus());
	}

	public function testIndexIsNotOpenedToNonAdminsOrAnonymousCallers(): void {
		$method = new ReflectionMethod(GenericMetricsController::class, 'index');

		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
		$this->assertSame([], $method->getAttributes(PublicPage::class));
	}
}
