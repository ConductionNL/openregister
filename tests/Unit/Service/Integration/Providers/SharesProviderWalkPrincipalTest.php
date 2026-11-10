<?php

/**
 * Characterisation test for SharesProvider's folder walk: the share manager is
 * asked for the signed-in user's shares only, so another user's shares are
 * never listed (allowed and refused outcomes).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Service\Integration\Providers\SharesProvider;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Pins whose shares the folder walk reads.
 */
class SharesProviderWalkPrincipalTest extends TestCase {

	/**
	 * The share ids the walk lists for a signed-in user, when the manager holds shares for `bob` only.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} The listed ids and the uids the manager was asked for.
	 */
	private function walkAs(string $uid): array {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(7);
		$node->method('getName')->willReturn('file-7');

		$share = $this->createMock(IShare::class);
		$share->method('getId')->willReturn('bob-share');
		$share->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$share->method('getSharedBy')->willReturn('bob');
		$share->method('getNode')->willReturn($node);
		$share->method('getPassword')->willReturn('');

		$asked   = [];
		$manager = $this->createMock(IManager::class);
		$manager->method('getSharesBy')->willReturnCallback(
			static function (string $owner, int $type) use (&$asked, $share): array {
				$asked[] = $owner;
				if ($owner === 'bob' && $type === IShare::TYPE_LINK) {
					return [$share];
				}

				return [];
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$node]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$fileService = new class($folder) {
			public function __construct(private Folder $folder) {
			}//end __construct()

			public function getObjectFolder(mixed $entityOrId): Folder {
				return $this->folder;
			}//end getObjectFolder()
		};

		$services  = [
			'OCP\\Share\\IManager' => $manager,
			'OCA\\OpenRegister\\Service\\FileService' => $fileService,
			'OCP\\IUserSession' => $session,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (array_key_exists($id, $services) === true) {
					return $services[$id];
				}

				throw new class extends RuntimeException implements NotFoundExceptionInterface {
				};
			}
		);

		$provider = new SharesProvider(
			l10n: $this->createMock(IL10N::class),
			container: $container,
		);

		$rows = $provider->list(register: 'r', schema: 's', objectId: 'o');

		return [array_column($rows, 'id'), array_values(array_unique($asked))];
	}//end walkAs()

	public function testTheSharerSeesTheirShare(): void {
		$this->assertSame([['bob-share'], ['bob']], $this->walkAs(uid: 'bob'));
	}//end testTheSharerSeesTheirShare()

	public function testAnotherUserIsNeverShownTheSharersShares(): void {
		$this->assertSame([[], ['alice']], $this->walkAs(uid: 'alice'));
	}//end testAnotherUserIsNeverShownTheSharersShares()
}//end class
