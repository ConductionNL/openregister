<?php

/**
 * DeclaredProviderDecisionsTest — administrators approve, reject and revoke declared providers.
 *
 * @category Test
 * @package  Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Controller\CredentialProviderApprovalController;
use OCA\OpenRegister\Controller\Support\AdministratorOnlyGate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Credential\DeclarationValidator;
use OCA\OpenRegister\Service\Credential\DeclaredProviderApprovals;
use OCA\OpenRegister\Service\Credential\DeclaredProviderDecisionRefused;
use OCA\OpenRegister\Service\Credential\DeclaredProviderDecisions;
use OCA\OpenRegister\Service\Credential\DeclaredProviderLoader;
use OCA\OpenRegister\Service\Credential\DeclaredProviderResolver;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\DeclaredProviderDecisions
 * @covers \OCA\OpenRegister\Service\Credential\DeclaredProviderDecisionRefused
 * @covers \OCA\OpenRegister\Controller\CredentialProviderApprovalController
 * @uses   \OCA\OpenRegister\Service\Credential\DeclaredProviderResolver
 * @uses   \OCA\OpenRegister\Service\Credential\DeclaredProviderLoader
 * @uses   \OCA\OpenRegister\Service\Credential\DeclaredProviderApprovals
 * @uses   \OCA\OpenRegister\Service\Credential\DeclarationValidator
 * @uses   \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 * @uses   \OCA\OpenRegister\Controller\Support\AdministratorOnlyGate
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */
class DeclaredProviderDecisionsTest extends TestCase {

	private string $appsRoot;

	/** @var array<int, string> */
	private array $installed = [];

	/** @var array<int, array<string, mixed>> Approval records the register holds; a save appends. */
	private array $records = [];

	/** @var array<int, string> The register targets the saves named. */
	private array $savedTo = [];

	private MockObject&ObjectOperationsInterface $objects;

	private DeclarationValidator $validator;

	protected function setUp(): void {
		$this->appsRoot = sys_get_temp_dir() . '/or-decisions-' . bin2hex(random_bytes(6));
		mkdir($this->appsRoot);
		$this->validator = new DeclarationValidator();
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, array $filters): array {
				$rows = [];
				foreach ($this->records as $record) {
					if ($record['providerIdentifier'] === $filters['providerIdentifier']) {
						$object = new ObjectEntity();
						$object->setObject($record);
						$rows[] = $object;
					}
				}

				return $rows;
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				$this->savedTo[] = $options->target->register . '/' . $options->target->schema . '/' . var_export($options->uuid, true);
				$this->records[] = $object;
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->appsRoot));
	}

	/**
	 * Ship a declaration file in an installed app.
	 *
	 * @param string               $appId     The app.
	 * @param array<string, mixed> $providers The `providers` map.
	 */
	private function declare(string $appId, array $providers): void {
		$dir = $this->appsRoot . '/' . $appId . '/lib/Settings';
		mkdir($dir, 0777, true);
		file_put_contents($dir . '/credential-providers.json', (string)json_encode(['providers' => $providers]));
		$this->installed[] = $appId;
	}

	/**
	 * A novel Codeberg declaration of an app.
	 *
	 * @param array<int, array<string, string>> $rules The rules.
	 *
	 * @return array<string, mixed>
	 */
	private function codeberg(array $rules = [['method' => 'GET', 'pathPattern' => '/repos/*']]): array {
		return [
			'identifier' => 'alpha:codeberg',
			'title' => 'Codeberg',
			'baseUrl' => 'https://codeberg.org/api/v1',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => $rules,
		];
	}

	/**
	 * The decisions service and its resolver over the shipped declarations and the records.
	 *
	 * @return array{0: DeclaredProviderDecisions, 1: DeclaredProviderResolver}
	 */
	private function services(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturnCallback(fn (): array => $this->installed);
		$appManager->method('getAppPath')->willReturnCallback(
			fn (string $appId): string => $appId === 'openregister' ? dirname(__DIR__, 4) : $this->appsRoot . '/' . $appId
		);
		$logger    = $this->createMock(LoggerInterface::class);
		$catalogue = new ProviderCatalogue($appManager, $logger);
		$loader    = new DeclaredProviderLoader($appManager, $catalogue, $this->validator, $logger);
		$approvals = new DeclaredProviderApprovals($this->objects, $logger);
		$resolver  = new DeclaredProviderResolver(loader: $loader, approvals: $approvals, validator: $this->validator);

		return [new DeclaredProviderDecisions($loader, $resolver, $approvals, $this->validator, $this->objects, $logger), $resolver];
	}

	/**
	 * The status code and reason of a refused decision.
	 *
	 * @param callable $decide The decision.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function refusal(callable $decide): array {
		try {
			$decide();
		} catch (DeclaredProviderDecisionRefused $e) {
			return [$e->getStatus(), $e->getMessage()];
		}

		$this->fail('the decision was not refused');
	}

	public function testAnApprovalNamesItsApproverAndWhatTheySaw(): void {
		$entry = $this->codeberg();
		$this->declare('alpha', ['alpha:codeberg' => $entry]);
		[$decisions] = $this->services();

		$state = $decisions->decide('alpha:codeberg', 'approved', 'admin', '  Our forge.  ');

		$this->assertSame('admitted', $state['status']);
		$this->assertSame(['credential-broker/credentialproviderapproval/NULL'], $this->savedTo);
		$record = $this->records[0];
		$this->assertSame(
			[
				'providerIdentifier' => 'alpha:codeberg',
				'declaringApp' => 'alpha',
				'declarationDigest' => $this->validator->digest($entry),
				'status' => 'approved',
				'decidedBy' => 'admin',
				'decisionNote' => 'Our forge.',
				'baseUrl' => 'https://codeberg.org/api/v1',
				'allowRulesSnapshot' => [['method' => 'GET', 'pathPattern' => '/repos/*']],
			],
			array_diff_key($record, ['decidedAt' => true])
		);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $record['decidedAt']);
	}

	public function testRevokingMakesAnAdmittedDeclarationUnusableAndApprovingAgainReadmitsIt(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg()]);
		[$decisions, $resolver] = $this->services();

		$decisions->decide('alpha:codeberg', 'approved', 'admin');
		$this->assertSame('revoked', $decisions->decide('alpha:codeberg', 'revoked', 'admin')['status']);
		$this->assertNull($resolver->admittedEntry('alpha:codeberg'));
		$this->assertSame('admitted', $decisions->decide('alpha:codeberg', 'approved', 'other-admin')['status']);
		$this->assertSame(['approved', 'revoked', 'approved'], array_column($this->records, 'status'));
	}

	public function testARejectedDeclarationStaysUnusable(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg()]);
		[$decisions, $resolver] = $this->services();

		$this->assertSame('rejected', $decisions->decide('alpha:codeberg', 'rejected', 'admin')['status']);
		$this->assertNull($resolver->admittedEntry('alpha:codeberg'));
	}

	public function testATransitionThatMeansNothingIsRefusedAndWritesNothing(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg()]);
		[$decisions] = $this->services();

		$this->assertSame([409, 'The declared provider is pending; it cannot be revoked.'], $this->refusal(fn () => $decisions->decide('alpha:codeberg', 'revoked', 'admin')));
		$decisions->decide('alpha:codeberg', 'approved', 'admin');
		$this->assertSame([409, 'The declared provider is admitted; it cannot be rejected.'], $this->refusal(fn () => $decisions->decide('alpha:codeberg', 'rejected', 'admin')));
		$this->assertSame([400, 'Unknown decision.'], $this->refusal(fn () => $decisions->decide('alpha:codeberg', 'pending', 'admin')));
		$this->assertSame(['approved'], array_column($this->records, 'status'));
	}

	public function testOnlyADeclarationThatNeedsADecisionCanGetOne(): void {
		$this->declare('alpha', [
			'alpha:github-read' => [
				'identifier' => 'alpha:github-read',
				'title' => 'GitHub, read only',
				'extends' => 'github',
				'baseUrl' => 'https://api.github.com',
				'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
				'allowRules' => [['method' => 'GET', 'pathPattern' => '/repos/*']],
			],
		]);
		[$decisions] = $this->services();

		$this->assertSame(409, $this->refusal(fn () => $decisions->decide('alpha:github-read', 'approved', 'admin'))[0]);
		$this->assertSame(404, $this->refusal(fn () => $decisions->decide('alpha:unknown', 'approved', 'admin'))[0]);
		$this->assertSame(404, $this->refusal(fn () => $decisions->decide('github', 'approved', 'admin'))[0]);
		$this->assertSame(401, $this->refusal(fn () => $decisions->decide('alpha:github-read', 'approved', ' '))[0]);
		$this->assertSame([], $this->records);
	}

	public function testAnInvalidDeclarationCannotBeApproved(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg() + ['inject_only' => true]]);
		[$decisions] = $this->services();

		$this->assertSame(409, $this->refusal(fn () => $decisions->decide('alpha:codeberg', 'approved', 'admin'))[0]);
		$this->assertSame([], $this->records);
	}

	public function testAnApprovalOnAnEarlierDigestDoesNotAdmitTheEditedDeclaration(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg()]);
		[$decisions] = $this->services();
		$decisions->decide('alpha:codeberg', 'approved', 'admin');

		// The app edits its declaration: a fresh request reads the new file.
		file_put_contents(
			$this->appsRoot . '/alpha/lib/Settings/credential-providers.json',
			(string)json_encode(['providers' => ['alpha:codeberg' => $this->codeberg([['method' => 'POST', 'pathPattern' => '/repos/*']])]])
		);
		[, $resolver] = $this->services();

		$this->assertSame('pending', $resolver->resolve('alpha:codeberg')['status']);
	}

	public function testOnlyAnAdministratorMayDecideAndNothingIsWrittenOtherwise(): void {
		$this->declare('alpha', ['alpha:codeberg' => $this->codeberg()]);

		$this->assertSame(403, $this->controller(admin: false)->approve('alpha:codeberg')->getStatus());
		$this->assertSame(401, $this->controller(admin: false, signedIn: false)->revoke('alpha:codeberg')->getStatus());
		$this->assertSame([], $this->records);

		$response = $this->controller(admin: true)->approve('alpha:codeberg');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['identifier' => 'alpha:codeberg', 'status' => 'admitted', 'origin' => 'alpha'], $response->getData());
		$this->assertSame('root', $this->records[0]['decidedBy']);
		$this->assertSame('Checked the host.', $this->records[0]['decisionNote']);

		$refused = $this->controller(admin: true)->reject('alpha:codeberg');
		$this->assertSame(409, $refused->getStatus());
		$this->assertSame(['error' => 'The declared provider is admitted; it cannot be rejected.'], $refused->getData());
	}

	/**
	 * The controller with a caller who is, or is not, an administrator.
	 *
	 * @param bool $admin    Whether the caller is an administrator.
	 * @param bool $signedIn Whether anybody is signed in.
	 *
	 * @return CredentialProviderApprovalController
	 */
	private function controller(bool $admin, bool $signedIn=true): CredentialProviderApprovalController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $key === 'note' ? 'Checked the host.' : $default);
		[$decisions] = $this->services();

		return new CredentialProviderApprovalController($request, new AdministratorOnlyGate($session, $groups), $session, $decisions);
	}
}
