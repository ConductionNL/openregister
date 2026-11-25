<?php

/**
 * The serve and write paths ask the recipient gate (REQ-PCF-001, design D-1).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Federation\RemoteRecipientGate;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FederationControllerFactory;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Controller\FederationController
 * @uses   \OCA\OpenRegister\Controller\Support\FederationControllerServices
 * @uses   \OCA\OpenRegister\Service\Federation\ShareCrosses
 */
final class FederationControllerRemoteRecipientTest extends TestCase {

	/**
	 * The verbs the gate was asked about, as `uuid|verb`.
	 *
	 * @var array<int, string>
	 */
	public array $asked = [];

	private function controller(array $refused): FederationController {
		$test = $this;
		$gate = new class ($refused, $test) extends RemoteRecipientGate {
			public function __construct(private readonly array $refused, private readonly FederationControllerRemoteRecipientTest $test) {
			}

			public function refuses(FederatedShare $share, ObjectEntity $object, string $verb): bool {
				$this->test->asked[] = $object->getUuid() . '|' . $verb;
				return in_array($object->getUuid() . '|' . $verb, $this->refused, true);
			}
		};

		return FederationControllerFactory::create(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			shareMapper: $this->createMock(FederatedShareMapper::class),
			objectService: $this->createMock(ObjectService::class),
			shareService: $this->createMock(FederationShareService::class),
			throttler: $this->createMock(IThrottler::class),
			logger: $this->createMock(LoggerInterface::class),
			remoteRecipients: $gate
		);
	}

	private static function share(): FederatedShare {
		$share = new FederatedShare();
		$share->setScope('object');
		$share->setSharedWith('alice@other.example');
		return $share;
	}

	private static function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject(['title' => $uuid]);
		return $object;
	}

	public function testAnObjectDeniedToTheRecipientIsNotServed(): void {
		$controller = $this->controller(refused: ['o-2|read']);

		$m = new ReflectionMethod(FederationController::class, 'applyShareVisibility');
		$visible = $m->invoke($controller, [self::object('o-1'), self::object('o-2'), self::object('o-3')], self::share());

		$this->assertSame(['o-1', 'o-3'], array_map(static fn (array $o): string => $o['id'] ?? $o['@self']['id'], $visible));
		$this->assertSame(['o-1|read', 'o-2|read', 'o-3|read'], $this->asked);
	}

	public function testNothingDeniedServesEverything(): void {
		$controller = $this->controller(refused: []);

		$m = new ReflectionMethod(FederationController::class, 'applyShareVisibility');
		$visible = $m->invoke($controller, [self::object('o-1'), self::object('o-2')], self::share());

		$this->assertCount(2, $visible);
	}
}
