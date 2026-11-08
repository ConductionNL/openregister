<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\ObjectOwnershipController}.
 *
 * Pins every allowed and refused answer (status and body) of the ownership
 * endpoints, and that a refusal at the RBAC boundary or on validation is
 * reached before any ownership write is attempted.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectOwnershipController;
use OCA\OpenRegister\Controller\Support\ObjectOwnershipControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of the ownership endpoints.
 */
class ObjectOwnershipControllerA24GoldenTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Ownership service mock.
	 *
	 * @var ObjectOwnershipService&MockObject
	 */
	private ObjectOwnershipService&MockObject $ownership;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->ownership = $this->createMock(ObjectOwnershipService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(?ObjectEntity $object, bool $resolverThrows = false): ObjectOwnershipController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default)
		);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		if ($resolverThrows === true) {
			$objects->method('getObject')->willThrowException(new \RuntimeException('db'));
		} else {
			$objects->method('getObject')->willReturn($object);
		}

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn(new Register());
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		return new ObjectOwnershipController(
			appName: 'openregister',
			request: $request,
			services: new ObjectOwnershipControllerServices(
				objectService: $objects,
				registerMapper: $registers,
				schemaMapper: $schemas,
				ownership: $this->ownership
			),
			logger: $this->logger
		);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setOwner('alice');
		$object->setAuthorization(['ownerGroup' => 'team']);

		return $object;
	}

	private function assertOutcome(int $status, mixed $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	public function testUnreadableRecordIsNotFoundBeforeAnyWrite(): void {
		$this->ownership->expects($this->never())->method($this->anything());
		$this->params = ['owner' => 'bob', 'ownerGroup' => 'g'];
		$notFound = ['message' => 'Object not found'];

		foreach ([$this->controller(null), $this->controller(null, true)] as $controller) {
			$this->assertOutcome(404, $notFound, $controller->show('r', 's', 'x'));
			$this->assertOutcome(404, $notFound, $controller->claim('r', 's', 'x'));
			$this->assertOutcome(404, $notFound, $controller->assign('r', 's', 'x'));
			$this->assertOutcome(404, $notFound, $controller->setOwnerGroup('r', 's', 'x'));
		}
	}

	public function testShowReadsOwnerAndGroup(): void {
		$this->assertOutcome(200, ['owner' => 'alice', 'ownerGroup' => 'team'], $this->controller($this->object())->show('r', 's', 'x'));
	}

	public function testClaimOutcomes(): void {
		$this->params = ['cascade' => 'true'];
		$this->ownership->method('claim')->with($this->anything(), $this->anything(), $this->anything(), true)->willReturnOnConsecutiveCalls(
			['owner' => 'me'],
			$this->throwException(new NotAuthorizedException('Not yours')),
			$this->throwException(new \InvalidArgumentException('bad'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(200, ['owner' => 'me'], $controller->claim('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Not yours'], $controller->claim('r', 's', 'x'));
		$this->logger->expects($this->once())->method('error')->with('[ObjectOwnershipController] claim: bad');
		$this->assertOutcome(500, ['message' => 'Could not complete the ownership request'], $controller->claim('r', 's', 'x'));
	}

	public function testAssignRefusesWithoutOwnerBeforeAnyWrite(): void {
		$this->ownership->expects($this->never())->method($this->anything());
		$controller = $this->controller($this->object());

		$this->assertOutcome(400, ['message' => 'An owner is required'], $controller->assign('r', 's', 'x'));
		$this->params = ['owner' => ''];
		$this->assertOutcome(400, ['message' => 'An owner is required'], $controller->assign('r', 's', 'x'));
		$this->params = ['owner' => 5];
		$this->assertOutcome(400, ['message' => 'An owner is required'], $controller->assign('r', 's', 'x'));
	}

	public function testAssignOutcomes(): void {
		$this->params = ['owner' => 'bob', 'cascade' => 1];
		$this->ownership->method('assign')->with($this->anything(), $this->anything(), $this->anything(), 'bob', true)->willReturnOnConsecutiveCalls(
			['owner' => 'bob'],
			$this->throwException(new NotAuthorizedException('Admins only')),
			$this->throwException(new \InvalidArgumentException('No such user')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(200, ['owner' => 'bob'], $controller->assign('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Admins only'], $controller->assign('r', 's', 'x'));
		$this->assertOutcome(400, ['message' => 'No such user'], $controller->assign('r', 's', 'x'));
		$this->logger->expects($this->once())->method('error')->with('[ObjectOwnershipController] assign: boom');
		$this->assertOutcome(500, ['message' => 'Could not complete the ownership request'], $controller->assign('r', 's', 'x'));
	}

	public function testSetOwnerGroupOutcomes(): void {
		$this->params = ['ownerGroup' => 7];
		$this->ownership->expects($this->exactly(5))->method('setOwnerGroup')->willReturnOnConsecutiveCalls(
			['ownerGroup' => 'staff'],
			['other' => true],
			$this->throwException(new NotAuthorizedException('Not yours')),
			$this->throwException(new \InvalidArgumentException('No such group')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(400, ['message' => 'An owner group must be a group id'], $controller->setOwnerGroup('r', 's', 'x'));
		$this->params = ['ownerGroup' => 'staff'];
		$this->assertOutcome(200, ['ownerGroup' => 'staff'], $controller->setOwnerGroup('r', 's', 'x'));
		$this->params = [];
		$this->assertOutcome(200, ['ownerGroup' => null], $controller->setOwnerGroup('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Not yours'], $controller->setOwnerGroup('r', 's', 'x'));
		$this->assertOutcome(400, ['message' => 'No such group'], $controller->setOwnerGroup('r', 's', 'x'));
		$this->assertOutcome(500, ['message' => 'Could not complete the ownership request'], $controller->setOwnerGroup('r', 's', 'x'));
	}

	public function testReassignRefusesBadInputBeforeAnyWrite(): void {
		$this->ownership->expects($this->never())->method($this->anything());
		$controller = $this->controller(null);

		$this->assertOutcome(400, ['message' => 'An owner is required'], $controller->reassign());
		$this->params = ['owner' => 'bob'];
		$this->assertOutcome(400, ['message' => 'Name at least one record to reassign'], $controller->reassign());
		$this->params = ['owner' => 'bob', 'objects' => []];
		$this->assertOutcome(400, ['message' => 'Name at least one record to reassign'], $controller->reassign());
		$this->params = ['owner' => 'bob', 'objects' => 'a'];
		$this->assertOutcome(400, ['message' => 'Name at least one record to reassign'], $controller->reassign());
	}

	public function testReassignOutcomes(): void {
		$this->params = ['owner' => 'bob', 'objects' => ['k' => 'a', 'l' => 'b'], 'cascade' => 'no'];
		$this->ownership->method('reassignMany')->with(['a', 'b'], 'bob', false)->willReturnOnConsecutiveCalls(
			['moved' => ['a', 'b']],
			$this->throwException(new NotAuthorizedException('Admins only')),
			$this->throwException(new \InvalidArgumentException('No such user')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller(null);

		$this->assertOutcome(200, ['moved' => ['a', 'b']], $controller->reassign());
		$this->assertOutcome(403, ['message' => 'Admins only'], $controller->reassign());
		$this->assertOutcome(400, ['message' => 'No such user'], $controller->reassign());
		$this->logger->expects($this->once())->method('error')->with('[ObjectOwnershipController] reassign: boom');
		$this->assertOutcome(500, ['message' => 'Could not complete the ownership request'], $controller->reassign());
	}

	/**
	 * Only the four spellings of "yes" turn the cascade on.
	 *
	 * @return void
	 */
	public function testCascadeSpellings(): void {
		$seen = [];
		$this->ownership->method('reassignMany')->willReturnCallback(
			function (array $identifiers, string $newOwner, bool $cascade) use (&$seen): array {
				$seen[] = $cascade;
				return [];
			}
		);
		$controller = $this->controller(null);
		foreach ([true, 'true', '1', 1, 'yes', 0, false, null] as $value) {
			$this->params = ['owner' => 'bob', 'objects' => ['a'], 'cascade' => $value];
			$controller->reassign();
		}

		$this->assertSame([true, true, true, true, false, false, false, false], $seen);
	}
}
