<?php

/**
 * The search scopes advertised to the current user.
 *
 * 🔑 The scopes are the USER'S: a schema the reader may not read is not
 * offered, and neither is a register or app reached only through such schemas.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Capabilities
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Capabilities;

use OCA\OpenRegister\Capabilities\SearchScopesCapability;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\SearchScopeCatalog;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCP\Capabilities\IInitialStateExcludedCapability;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Capabilities\SearchScopesCapability
 * @uses \OCA\OpenRegister\Db\Schema
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md
 */
final class SearchScopesCapabilityTest extends TestCase {

	private SearchScopeCatalog&MockObject $catalog;
	private PermissionCheckInterface&MockObject $permissions;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;

	/**
	 * The schema entities by id.
	 *
	 * @var array<int, Schema>
	 */
	private array $entities = [];

	protected function setUp(): void {
		$this->catalog     = $this->createMock(SearchScopeCatalog::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger      = $this->createMock(LoggerInterface::class);

		$rows = [
			$this->row(id: 1, slug: 'case', title: 'Case', register: 'zaken', registerTitle: 'Zaken', apps: ['procest', 'dossiq']),
			$this->row(id: 2, slug: 'contact', title: 'Contact', register: 'crm', registerTitle: 'CRM', apps: ['pipelinq']),
			$this->row(id: 3, slug: 'Secret', title: 'Secret', register: 'vault', registerTitle: 'Vault', apps: ['keepiq']),
			// Same slug as 1, in another register: one `schema:case` scope.
			$this->row(id: 4, slug: 'case', title: 'Other case', register: 'archive', registerTitle: '', apps: []),
		];
		$this->catalog->method('describe')->willReturn($rows);
	}

	/**
	 * One described schema row.
	 *
	 * @param int           $id            The id.
	 * @param string        $slug          The slug.
	 * @param string        $title         The title.
	 * @param string        $register      The owning register slug.
	 * @param string        $registerTitle The owning register title.
	 * @param array<string> $apps          The claiming apps.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(int $id, string $slug, string $title, string $register, string $registerTitle, array $apps): array {
		$schema = new Schema();
		$schema->setId($id);
		$this->entities[$id] = $schema;

		return [
			'id' => $id,
			'slug' => $slug,
			'title' => $title,
			'register' => $register,
			'registerTitle' => $registerTitle,
			'apps' => $apps,
			'schema' => $schema,
		];
	}

	/**
	 * Sign a user in.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * The capability under test.
	 *
	 * @return SearchScopesCapability The capability.
	 */
	private function capability(): SearchScopesCapability {
		return new SearchScopesCapability(
			scopes: $this->catalog,
			permissions: $this->permissions,
			userSession: $this->userSession,
			logger: $this->logger
		);
	}

	/**
	 * The ids of the advertised scopes.
	 *
	 * @param array<string, mixed> $capabilities The capability block.
	 *
	 * @return array<int, string> The scope ids.
	 */
	private static function ids(array $capabilities): array {
		return array_map(static fn (array $scope): string => $scope['id'], $capabilities['openregister']['search']['scopes']);
	}

	/**
	 * 🔴 Only the scopes of schemas the user may read are offered, each once, apps first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testOffersOnlyTheScopesOfReadableSchemas(): void {
		$this->signIn('alice');
		$asked = [];
		$this->permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId = null) use (&$asked): bool {
				$asked[] = [$schema->getId(), $action, $userId];
				return $schema->getId() !== 3;
			}
		);

		$capabilities = $this->capability()->getCapabilities();

		self::assertSame(
			[
				'app:dossiq', 'app:pipelinq', 'app:procest',
				'register:archive', 'register:crm', 'register:zaken',
				'schema:case', 'schema:contact',
				'files',
			],
			self::ids($capabilities),
			'the vault register, its schema and its app are not offered; files comes last, now that hits carry a kind'
		);
		self::assertSame([[1, 'read', 'alice'], [2, 'read', 'alice'], [3, 'read', 'alice'], [4, 'read', 'alice']], $asked);

		$search = $capabilities['openregister']['search'];
		self::assertSame(1, $search['contractVersion']);
		self::assertSame('openregister_objects', $search['provider']);
		self::assertSame('scopes', $search['filter']);
		self::assertSame(
			['id' => 'schema:case', 'type' => 'schema', 'value' => 'case', 'label' => 'Case'],
			$search['scopes'][6],
			'the first schema to name a scope labels it'
		);
		self::assertSame(
			['id' => 'register:archive', 'type' => 'register', 'value' => 'archive', 'label' => 'archive'],
			$search['scopes'][3],
			'a register without a title is labelled by its slug'
		);
	}

	/**
	 * A stored slug with capitals is offered in lower case, the way the provider reads the token.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testAScopeIsOfferedInLowerCase(): void {
		$this->signIn('admin');
		$this->permissions->method('hasPermission')->willReturn(true);

		self::assertContains('schema:secret', self::ids($this->capability()->getCapabilities()));
	}

	/**
	 * 🔴 A read check that throws keeps the schema out (fail closed) and is reported.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testAReadCheckThatThrowsKeepsTheSchemaOut(): void {
		$this->signIn('bob');
		$this->permissions->method('hasPermission')->willReturnCallback(
			static function (Schema $schema): bool {
				if ($schema->getId() === 2) {
					throw new \RuntimeException('unresolvable');
				}

				return false;
			}
		);
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('Read check failed'), self::callback(static fn (array $context): bool => $context['schema'] === 2));

		self::assertSame([], self::ids($this->capability()->getCapabilities()));
	}

	/**
	 * Nobody signed in is offered nothing, and no permission is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testNobodySignedInIsOfferedNothing(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->permissions->expects(self::never())->method('hasPermission');

		self::assertSame([], self::ids($this->capability()->getCapabilities()));
	}

	/**
	 * The list stays out of the initial state rendered into every page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	public function testIsExcludedFromTheInitialState(): void {
		self::assertInstanceOf(IInitialStateExcludedCapability::class, $this->capability());
	}
}
