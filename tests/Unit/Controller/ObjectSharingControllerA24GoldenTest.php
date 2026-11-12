<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\ObjectSharingController}.
 *
 * Pins every allowed and refused answer (status and body) of the sharing
 * endpoints, and that a refusal at the RBAC boundary is reached before any
 * sharing write is attempted.
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
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Service\Rbac\LinkProtection;
use OCA\OpenRegister\Service\Rbac\LinkInvitation;
use OCA\OpenRegister\Service\Rbac\GrantTerms;
use OCA\OpenRegister\Service\Rbac\GrantRequest;
use OCA\OpenRegister\Tests\Unit\Controller\Support\MapperBackedSchemaCatalog;
use OCA\OpenRegister\Controller\ObjectSharingController;
use OCA\OpenRegister\Controller\Support\ObjectSharingControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of the sharing endpoints.
 */
class ObjectSharingControllerA24GoldenTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Sharing service mock.
	 *
	 * @var ObjectSharingService&MockObject
	 */
	private ObjectSharingService&MockObject $sharing;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Object resolver mock.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	protected function setUp(): void {
		parent::setUp();
		$this->sharing = $this->createMock(ObjectSharingService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
	}

	private function controller(?ObjectEntity $object): ObjectSharingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default)
		);
		$this->objects->method('getObject')->willReturn($object);
		$this->objects->method('getRegister')->willReturn(4);
		$this->objects->method('getSchema')->willReturn(9);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn(new Register());
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		return new ObjectSharingController(
			request: $request,
			services: new ObjectSharingControllerServices(
				objectService: $this->objects,
				catalog: new MapperBackedSchemaCatalog(registers: $registers, schemas: $schemas),
				sharing: $this->sharing,
				logger: $this->logger
			)
		);
	}

	private function object(?array $authorization = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setAuthorization($authorization);

		return $object;
	}

	private function assertOutcome(int $status, mixed $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	public function testUnreadableObjectIsNotFoundBeforeAnyWrite(): void {
		$this->sharing->expects($this->never())->method($this->anything());
		$this->params = ['scope' => 'private'];
		$controller = $this->controller(null);
		$notFound = ['message' => 'Object not found'];

		$this->assertOutcome(404, $notFound, $controller->scope('r', 's', 'x'));
		$this->assertOutcome(404, $notFound, $controller->setScope('r', 's', 'x'));
		$this->assertOutcome(404, $notFound, $controller->shares('r', 's', 'x'));
		$this->assertOutcome(404, $notFound, $controller->createShare('r', 's', 'x'));
		$this->assertOutcome(404, $notFound, $controller->createLink('r', 's', 'x'));
		$this->assertOutcome(404, $notFound, $controller->inviteByEmail('r', 's', 'x'));
		$this->assertOutcome(404, $notFound, $controller->destroyShare('r', 's', 'x', '7'));
	}

	public function testResolverFailureIsNotFound(): void {
		$this->objects->method('getObject')->willThrowException(new \RuntimeException('db'));
		$controller = new ObjectSharingController(
			request: $this->createMock(IRequest::class),
			services: new ObjectSharingControllerServices(
				objectService: $this->objects,
				catalog: new MapperBackedSchemaCatalog(registers: $this->createMock(RegisterMapperInterface::class), schemas: $this->createMock(SchemaMapperInterface::class)),
				sharing: $this->sharing,
				logger: $this->logger
			)
		);

		$this->assertOutcome(404, ['message' => 'Object not found'], $controller->scope('r', 's', 'x'));
	}

	public function testScopeReadsTheStoredScope(): void {
		$this->assertOutcome(200, ['scope' => 'private'], $this->controller($this->object(['scope' => 'private']))->scope('r', 's', 'x'));
	}

	public function testScopeWithoutBlockIsNull(): void {
		$this->assertOutcome(200, ['scope' => null], $this->controller($this->object())->scope('r', 's', 'x'));
	}

	public function testSetScopeOutcomes(): void {
		$this->assertOutcome(400, ['message' => 'A scope is required'], $this->controller($this->object())->setScope('r', 's', 'x'));

		$this->params = ['scope' => 'private'];
		$this->sharing->method('setScope')->willReturnOnConsecutiveCalls(
			['scope' => 'private'],
			$this->throwException(new NotAuthorizedException('Only the owner')),
			$this->throwException(new \InvalidArgumentException('Unknown scope')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(200, ['scope' => 'private'], $controller->setScope('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Only the owner'], $controller->setScope('r', 's', 'x'));
		$this->assertOutcome(400, ['message' => 'Unknown scope'], $controller->setScope('r', 's', 'x'));
		$this->logger->expects($this->once())->method('error')->with('[ObjectSharingController] setScope: boom');
		$this->assertOutcome(500, ['message' => 'Could not complete the sharing request'], $controller->setScope('r', 's', 'x'));
	}

	public function testSharesOutcomes(): void {
		$this->sharing->method('listGrants')->willReturnOnConsecutiveCalls(
			[['id' => '1']],
			$this->throwException(new NotAuthorizedException('Only the owner')),
			$this->throwException(new \InvalidArgumentException('bad'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(200, ['results' => [['id' => '1']]], $controller->shares('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Only the owner'], $controller->shares('r', 's', 'x'));
		$this->logger->expects($this->once())->method('error')->with('[ObjectSharingController] shares: bad');
		$this->assertOutcome(500, ['message' => 'Could not complete the sharing request'], $controller->shares('r', 's', 'x'));
	}

	public function testCreateShareDefaultsToUserAndRead(): void {
		$this->sharing->expects($this->once())->method('grantWithTerms')
			->with($this->isInstanceOf(ObjectEntity::class), new GrantRequest(type: 'user', shareWith: '', terms: new GrantTerms(permissions: 1)))
			->willReturn(['id' => '5']);

		$this->assertOutcome(201, ['id' => '5'], $this->controller($this->object())->createShare('r', 's', 'x'));
	}

	public function testCreateShareOutcomes(): void {
		$this->params = ['type' => 'group', 'shareWith' => 'staff', 'permissions' => '15'];
		$this->sharing->method('grantWithTerms')->with($this->anything(), new GrantRequest(type: 'group', shareWith: 'staff', terms: new GrantTerms(permissions: 15)))->willReturnOnConsecutiveCalls(
			['id' => '6'],
			$this->throwException(new NotAuthorizedException('Only the owner')),
			$this->throwException(new \InvalidArgumentException('Bad type')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(201, ['id' => '6'], $controller->createShare('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Only the owner'], $controller->createShare('r', 's', 'x'));
		$this->assertOutcome(400, ['message' => 'Bad type'], $controller->createShare('r', 's', 'x'));
		$this->assertOutcome(500, ['message' => 'Could not complete the sharing request'], $controller->createShare('r', 's', 'x'));
	}

	public function testCreateLinkOutcomes(): void {
		$this->params = ['password' => '  ', 'expiration' => '2030-01-01'];
		$this->sharing->method('createLink')->with($this->anything(), new LinkInvitation(recipient: null, permissions: 1, protection: new LinkProtection(password: null, expiration: '2030-01-01')))->willReturnOnConsecutiveCalls(
			['token' => 't'],
			$this->throwException(new NotAuthorizedException('Only the owner')),
			$this->throwException(new \InvalidArgumentException('Bad expiry')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(201, ['token' => 't'], $controller->createLink('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Only the owner'], $controller->createLink('r', 's', 'x'));
		$this->assertOutcome(400, ['message' => 'Bad expiry'], $controller->createLink('r', 's', 'x'));
		$this->logger->expects($this->once())->method('error')->with('[ObjectSharingController] createLink: boom');
		$this->assertOutcome(500, ['message' => 'Could not complete the sharing request'], $controller->createLink('r', 's', 'x'));
	}

	public function testInviteByEmailOutcomes(): void {
		$this->params = ['email' => 'a@example.org', 'permissions' => 3, 'password' => 'pw'];
		$this->sharing->method('inviteByEmail')->with($this->anything(), new LinkInvitation(recipient: 'a@example.org', permissions: 3, protection: new LinkProtection(password: 'pw', expiration: null)))->willReturnOnConsecutiveCalls(
			['id' => 'i'],
			$this->throwException(new NotAuthorizedException('Only the owner')),
			$this->throwException(new \InvalidArgumentException('Bad email')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(201, ['id' => 'i'], $controller->inviteByEmail('r', 's', 'x'));
		$this->assertOutcome(403, ['message' => 'Only the owner'], $controller->inviteByEmail('r', 's', 'x'));
		$this->assertOutcome(400, ['message' => 'Bad email'], $controller->inviteByEmail('r', 's', 'x'));
		$this->assertOutcome(500, ['message' => 'Could not complete the sharing request'], $controller->inviteByEmail('r', 's', 'x'));
	}

	public function testDestroyShareOutcomes(): void {
		$this->sharing->method('revoke')->with($this->anything(), '7')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new NotAuthorizedException('Only the owner')),
			$this->throwException(new \InvalidArgumentException('Not on this object')),
			$this->throwException(new \RuntimeException('boom'))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(204, [], $controller->destroyShare('r', 's', 'x', '7'));
		$this->assertOutcome(403, ['message' => 'Only the owner'], $controller->destroyShare('r', 's', 'x', '7'));
		$this->assertOutcome(400, ['message' => 'Not on this object'], $controller->destroyShare('r', 's', 'x', '7'));
		$this->logger->expects($this->once())->method('error')->with('[ObjectSharingController] destroyShare: boom');
		$this->assertOutcome(500, ['message' => 'Could not complete the sharing request'], $controller->destroyShare('r', 's', 'x', '7'));
	}
}
