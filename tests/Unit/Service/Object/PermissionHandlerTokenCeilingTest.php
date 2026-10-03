<?php

/**
 * The token ceiling reaches past the admin and owner bypasses.
 *
 * This is the load-bearing claim of `scoped-api-tokens`, and it is the one the
 * other tests cannot make: `TokenGrantNarrower` can be perfect and the feature
 * still worthless, because `hasGroupPermission()` returns true for the `admin`
 * group and for an object's owner BEFORE it reads the block at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies that the grant binds the most privileged caller too.
 */
class PermissionHandlerTokenCeilingTest extends TestCase {

	/**
	 * A handler with a token narrower wired in.
	 *
	 * @return PermissionHandler The handler.
	 */
	private function handler(): PermissionHandler {
		return new PermissionHandler(
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class),
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			new TokenGrantSource(),
			new TokenGrantNarrower()
		);
	}//end handler()

	/**
	 * A block already narrowed to a read-only token.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function narrowedToReadOnly(): array {
		return (new TokenGrantNarrower())->narrow(
			authorization: ['read' => ['medewerkers'], 'update' => ['medewerkers']],
			grant: new TokenGrant(
				verbs: ['read'],
				schemas: ['zaak'],
				expiresAt: new DateTimeImmutable('2099-01-01T00:00:00+00:00'),
				tokenId: 'leverancier'
			),
			schemaSlug: 'zaak',
			registerSlug: 'zaken'
		);
	}//end narrowedToReadOnly()

	/**
	 * 🔴 An ADMIN holding a read-only token is refused the write.
	 *
	 * The least privileged principal that should be refused is, here, the MOST
	 * privileged one: the administrator is the caller who escapes every other
	 * rule in this method, so if the ceiling does not bind them it does not
	 * bind anyone who matters.
	 *
	 * @return void
	 */
	public function testAnAdminHoldingAReadOnlyTokenIsRefusedTheWrite(): void {
		$handler = $this->handler();
		$block = $this->narrowedToReadOnly();

		$this->assertFalse(
			$handler->hasGroupPermission(
				authorization: $block,
				groupId: 'admin',
				action: 'update',
				userId: 'beheerder',
				userGroup: 'admin'
			),
			'a grant that the admin group escapes is not a ceiling at all'
		);

		$this->assertTrue(
			$handler->hasGroupPermission(
				authorization: $block,
				groupId: 'admin',
				action: 'read',
				userId: 'beheerder',
				userGroup: 'admin'
			),
			'and the verb the token DOES hold still works, so this is a narrowing and not a wall'
		);
	}//end testAnAdminHoldingAReadOnlyTokenIsRefusedTheWrite()

	/**
	 * 🔴 The OWNER of an object, holding a read-only token, is refused the
	 * write to their own object.
	 *
	 * Most of what a supplier's token touches is objects it created itself, so
	 * an owner bypass the grant does not reach would leave the feature
	 * refusing almost nothing in practice.
	 *
	 * @return void
	 */
	public function testTheOwnerIsRefusedAWriteToTheirOwnObject(): void {
		$handler = $this->handler();

		$this->assertFalse(
			$handler->hasGroupPermission(
				authorization: $this->narrowedToReadOnly(),
				groupId: 'leveranciers',
				action: 'update',
				userId: 'leverancier',
				userGroup: 'leveranciers',
				objectOwner: 'leverancier'
			),
			'the owner bypass must not hand a read-only token a write on its own rows'
		);
	}//end testTheOwnerIsRefusedAWriteToTheirOwnObject()

	/**
	 * A caller with no token keeps both bypasses.
	 *
	 * The control: without it, the two tests above would pass on a handler
	 * that refused everybody everything.
	 *
	 * @return void
	 */
	public function testWithoutATokenTheAdminAndTheOwnerAreUnaffected(): void {
		$handler = $this->handler();
		$block = ['read' => ['medewerkers'], 'update' => ['medewerkers']];

		$this->assertTrue(
			$handler->hasGroupPermission(
				authorization: $block,
				groupId: 'admin',
				action: 'update',
				userId: 'beheerder',
				userGroup: 'admin'
			),
			'a person with no token is not narrowed by a feature about tokens'
		);

		$this->assertTrue(
			$handler->hasGroupPermission(
				authorization: $block,
				groupId: 'leveranciers',
				action: 'update',
				userId: 'anja',
				userGroup: 'leveranciers',
				objectOwner: 'anja'
			),
			'and neither is the owner of their own object'
		);
	}//end testWithoutATokenTheAdminAndTheOwnerAreUnaffected()

	/**
	 * An expired token is refused even the verb it names.
	 *
	 * @return void
	 */
	public function testAnExpiredTokenIsRefusedEvenItsOwnVerb(): void {
		$block = (new TokenGrantNarrower())->narrow(
			authorization: ['read' => ['medewerkers']],
			grant: new TokenGrant(
				verbs: ['read'],
				expiresAt: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
				tokenId: 'leverancier'
			),
			schemaSlug: 'zaak',
			registerSlug: 'zaken'
		);

		$this->assertFalse(
			$this->handler()->hasGroupPermission(
				authorization: $block,
				groupId: 'admin',
				action: 'read',
				userId: 'beheerder',
				userGroup: 'admin'
			),
			'a six-week migration token kept for six years reads nothing'
		);
	}//end testAnExpiredTokenIsRefusedEvenItsOwnVerb()
}//end class
