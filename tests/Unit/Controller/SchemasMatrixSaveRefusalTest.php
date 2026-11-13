<?php

/**
 * The save the permission matrix makes is refused for a signed-in caller who
 * may not manage the schema: 403, and the stored authorization is unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/rights-screen-stated/specs/rbac-scopes/spec.md#REQ-RSS-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Rbac\DefinitionWriteRights;
use OCA\OpenRegister\Service\Rbac\RbacRuleChecks;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearer;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SchemasControllerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A signed-in non-manager sending the permission matrix's schema save.
 */
#[CoversClass(SchemasController::class)]
#[UsesClass(RbacRuleChecks::class)]
#[UsesClass(DefinitionWriteRights::class)]
#[UsesClass(Schema::class)]
final class SchemasMatrixSaveRefusalTest extends TestCase {
	/**
	 * The schema's stored authorization: `medewerkers` reads, `beheerders` manages.
	 *
	 * @var array<string, list<string>>
	 */
	private const STORED_AUTHORIZATION = [
		'read' => ['medewerkers'],
		'manage' => ['beheerders'],
	];

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The schema mapper; a refused save must never write.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * The group manager answering admin status and membership.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The controller, with a session signed in as `jan` in the given groups.
	 *
	 * @param list<string> $groups The groups `jan` is a member of.
	 *
	 * @return SchemasController
	 */
	private function controller(array $groups): SchemasController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->expects($this->never())->method('update');
		$this->schemaMapper->expects($this->never())->method('updateFromArray');

		$schemaService = $this->createMock(SchemaService::class);
		$schemaService->expects($this->never())->method($this->anything());

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->expects($this->atLeastOnce())->method('isAdmin')->with('jan')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->with($user)->willReturn($groups);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				IUserSession::class => $userSession,
				IGroupManager::class => $this->groupManager,
				default => throw new \RuntimeException('unexpected service ' . $id),
			}
		);

		return SchemasControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(UploadService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(SchemaCacheHandler::class),
			$this->createMock(FacetResultCacheClearer::class),
			$schemaService,
			$this->createMock(LoggerInterface::class),
			$container,
			$this->createMock(SchemaVersioningService::class)
		);
	}//end controller()

	/**
	 * The stored schema `melding`.
	 *
	 * @return Schema
	 */
	private function storedSchema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('melding');
		$schema->setAuthorization(self::STORED_AUTHORIZATION);

		return $schema;
	}//end storedSchema()

	/**
	 * The saves the matrix sends, and the groups of a caller who may not manage the schema.
	 *
	 * @return array<string, array{array<string, mixed>, list<string>}>
	 */
	public static function matrixSaves(): array {
		$grantRead = ['read' => ['medewerkers', 'wijkteam'], 'manage' => ['beheerders']];
		$publicRead = ['read' => ['medewerkers', 'public'], 'manage' => ['beheerders']];
		$bulkRole = ['roles' => ['behandelaar' => ['wijkteam']]];

		return [
			'a group added to the read cell, caller in no group' => [['title' => 'melding', 'authorization' => $grantRead], []],
			'a group added to the read cell, caller only reads' => [['title' => 'melding', 'authorization' => $grantRead], ['medewerkers']],
			'the public column switched on, caller in the granted group' => [['title' => 'melding', 'authorization' => $publicRead], ['wijkteam']],
			'a bulk role assignment' => [['title' => 'melding', 'authorization' => $bulkRole], ['medewerkers', 'wijkteam']],
			'the caller grants themselves manage' => [['title' => 'melding', 'authorization' => ['manage' => ['medewerkers']]], ['medewerkers']],
		];
	}//end matrixSaves()

	/**
	 * A caller who may not manage the schema gets 403 and the authorization stays as stored.
	 *
	 * @param array<string, mixed> $body   The save the matrix sends.
	 * @param list<string>         $groups The caller's groups.
	 *
	 * @return void
	 */
	#[DataProvider('matrixSaves')]
	public function testTheMatrixSaveOfANonManagerIs403(array $body, array $groups): void {
		$this->params = $body;
		$controller = $this->controller(groups: $groups);
		$schema = $this->storedSchema();
		$this->schemaMapper->expects($this->once())->method('find')->with(7)->willReturn($schema);

		$response = $controller->update(7);

		$this->assertSame(
			[403, ['error' => 'User does not have permission to manage this schema']],
			[$response->getStatus(), $response->getData()]
		);
		$this->assertSame(self::STORED_AUTHORIZATION, $schema->getAuthorization());
	}//end testTheMatrixSaveOfANonManagerIs403()
}//end class
