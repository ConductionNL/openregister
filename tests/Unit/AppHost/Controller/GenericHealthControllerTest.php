<?php

/**
 * AppHost GenericHealthController — response shape, status code and CORS tests.
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

use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\HealthResult;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Observability\HealthResponsePolicy;
use OCA\OpenRegister\AppHost\Observability\ObservabilityProbes;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The health endpoint renders `{status, app, version, checks}` for the calling
 * app, with the executor's HTTP code, and adds CORS headers only when asked.
 */
class GenericHealthControllerTest extends TestCase {
	/**
	 * Build the controller for app `leafapp` over the given manifest and result.
	 */
	private function controller(ObservabilityManifest $manifest, HealthResult $result): GenericHealthController {
		$loader = $this->createMock(ManifestLoader::class);
		$loader->expects($this->once())->method('load')->with('leafapp')->willReturn($manifest);
		$loader->expects($this->once())->method('appVersion')->with('leafapp')->willReturn('1.2.3');

		$executor = $this->createMock(HealthCheckExecutor::class);
		$executor->expects($this->once())->method('execute')->with($this->identicalTo($manifest))->willReturn($result);

		return new GenericHealthController('leafapp', $this->createMock(IRequest::class), $loader, $executor);
	}

	public function testRendersAdr006ShapeWithExecutorStatusCode(): void {
		$manifest = new ObservabilityManifest('leafapp', new ObservabilityProbes([], []));
		$result = new HealthResult('degraded', ['database' => 'ok', 'files' => 'failed: gone'], 503);

		$response = $this->controller($manifest, $result)->index();

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(
			[
				'status' => 'degraded',
				'app' => 'leafapp',
				'version' => '1.2.3',
				'checks' => ['database' => 'ok', 'files' => 'failed: gone'],
			],
			$response->getData()
		);
	}

	public function testNoCorsHeadersWhenManifestDoesNotEnableCors(): void {
		$manifest = new ObservabilityManifest('leafapp', new ObservabilityProbes([], []), new HealthResponsePolicy(ObservabilityManifest::POLICY_ADR006, false));
		$result = new HealthResult('ok', [], 200);

		$headers = $this->controller($manifest, $result)->index()->getHeaders();

		$this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
		$this->assertArrayNotHasKey('Access-Control-Allow-Methods', $headers);
	}

	public function testCorsHeadersAddedWhenManifestEnablesCors(): void {
		$manifest = new ObservabilityManifest('leafapp', new ObservabilityProbes([], []), new HealthResponsePolicy(ObservabilityManifest::POLICY_ADR006, true));
		$result = new HealthResult('ok', [], 200);

		$response = $this->controller($manifest, $result)->index();
		$headers = $response->getHeaders();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('*', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('GET, OPTIONS', $headers['Access-Control-Allow-Methods']);
	}
}
