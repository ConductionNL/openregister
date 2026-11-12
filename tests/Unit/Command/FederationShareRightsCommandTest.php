<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\FederationShareRightsCommand;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The report lists shares past their creator's rights and changes nothing.
 *
 * @covers \OCA\OpenRegister\Command\FederationShareRightsCommand
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 */
class FederationShareRightsCommandTest extends TestCase {

	private function share(int $id, ?string $owner): FederatedShare {
		$share = (new FederatedShare())->hydrate(
			object: ['scope' => 'schema', 'permissions' => 'read-write', 'register' => 'zaken', 'schema' => 'zaak', 'owner' => $owner, 'organisation' => 'org-a']
		);
		$share->setId($id);
		return $share;
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testTheReportJudgesEachShareAsItsCreatorAndRestoresTheSession(): void {
		$alice   = $this->user(uid: 'alice');
		$bob     = $this->user(uid: 'bob');
		$admin   = $this->user(uid: 'admin');
		$current = $admin;

		$mapper = $this->createMock(FederatedShareMapper::class);
		$mapper->method('findLiveOutgoingAcrossOrganisations')->willReturn([
			$this->share(id: 1, owner: 'alice'),
			$this->share(id: 2, owner: 'bob'),
			$this->share(id: 3, owner: null),
			$this->share(id: 4, owner: 'gone'),
		]);
		$mapper->expects($this->never())->method('update');
		$mapper->expects($this->never())->method('updateFromArray');

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnMap([['alice', $alice], ['bob', $bob], ['gone', null]]);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(static function () use (&$current) {
			return $current;
		});
		$session->method('setVolatileActiveUser')->willReturnCallback(static function ($user) use (&$current): void {
			$current = $user;
		});

		$judgedAs = [];
		$grantor  = $this->createMock(ShareGrantor::class);
		$grantor->method('refusal')->willReturnCallback(static function (FederatedShare $share) use (&$current, &$judgedAs): ?string {
			$judgedAs[$share->getId()] = $current?->getUID();
			return ($current?->getUID() === 'bob' ? 'This share needs the right to manage its schema' : null);
		});

		$tester = new CommandTester(new FederationShareRightsCommand($mapper, $grantor, $users, $session));
		$code   = $tester->execute([]);
		$out    = $tester->getDisplay();

		$this->assertSame(0, $code);
		$this->assertSame([1 => 'alice', 2 => 'bob'], $judgedAs);
		$this->assertSame($admin, $current);
		$this->assertStringContainsString('3 of 4 live outgoing shares need review.', $out);
		$this->assertStringContainsString('This share needs the right to manage its schema', $out);
		$this->assertStringContainsString('creator not recorded', $out);
		$this->assertStringContainsString('creator no longer exists', $out);
		$this->assertDoesNotMatchRegularExpression('/^\|\s*1\s*\|/m', $out);
		$this->assertMatchesRegularExpression('/^\|\s*2\s*\|\s*org-a\s*\|\s*schema read-write/m', $out);
	}

	public function testAReportWithNothingToReviewSaysSo(): void {
		$mapper = $this->createMock(FederatedShareMapper::class);
		$mapper->method('findLiveOutgoingAcrossOrganisations')->willReturn([]);

		$tester = new CommandTester(new FederationShareRightsCommand(
			$mapper,
			$this->createMock(ShareGrantor::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class)
		));

		$this->assertSame(0, $tester->execute([]));
		$this->assertSame("0 of 0 live outgoing shares need review.\n", $tester->getDisplay());
	}
}
