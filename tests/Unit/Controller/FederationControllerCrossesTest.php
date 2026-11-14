<?php

/**
 * A served object carries only what its share declares (REQ-PCF-002).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class FederationControllerCrossesTest extends TestCase {

	private FederationController $controller;

	protected function setUp(): void {
		$this->controller = FederationControllerFactory::create(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(FederatedShareMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(FederationShareService::class),
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function share(?array $crosses): FederatedShare {
		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope('schema');
		$share->setRegister('zaken');
		$share->setSchema('zaak');
		$share->setCrosses($crosses);
		return $share;
	}

	/**
	 * The object as the share serves it.
	 *
	 * @return array<string, mixed>
	 */
	private function served(?array $crosses): array {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setObject(['title' => 'Dorpsstraat 1']);
		$entity->setFiles([['id' => 7, 'title' => 'plan.pdf']]);

		$m = new ReflectionMethod(FederationController::class, 'applyShareVisibility');
		$visible = $m->invoke($this->controller, [$entity], $this->share($crosses));
		$this->assertCount(1, $visible);
		return $visible[0];
	}

	public function testAShareWithoutADeclarationServesTheDataWithoutFiles(): void {
		$served = $this->served(null);

		$this->assertSame('Dorpsstraat 1', $served['title']);
		$this->assertArrayNotHasKey('files', $served['@self']);
	}

	public function testAShareDeclaringFilesServesThem(): void {
		$served = $this->served(['data', 'files']);

		$this->assertSame('Dorpsstraat 1', $served['title']);
		$this->assertSame([['id' => 7, 'title' => 'plan.pdf']], $served['@self']['files']);
	}

	public function testAShareDeclaringOnlyFilesServesNoProperties(): void {
		$served = $this->served(['files']);

		$this->assertArrayNotHasKey('title', $served);
		$this->assertSame('obj-1', $served['@self']['id']);
	}
}
