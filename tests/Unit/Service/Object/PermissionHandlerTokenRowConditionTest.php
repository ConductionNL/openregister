<?php

/**
 * The grant's row condition is part of the ceiling: it binds the administrator and the owner.
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
 * @spec openspec/changes/scoped-api-tokens/tasks.md#1-1d
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.


namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\GroupRuleRequest;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantReach;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantTarget;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use OCA\OpenRegister\Service\Rbac\TokenGrantUse;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies that a supplier's token reads the supplier's rows whoever holds it.
 */
final class PermissionHandlerTokenRowConditionTest extends TestCase {

	/**
	 * The supplier's grant: read on `zaak`, rows whose `leverancier` is the token.
	 *
	 * @return TokenGrant The grant.
	 */
	private static function supplierGrant(): TokenGrant {
		return new TokenGrant(
			verbs: ['read'],
			reach: new TokenGrantReach(schemas: ['zaak'], match: ['leverancier' => TokenGrantNarrower::TOKEN_SUBJECT]),
			terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('+1 year')),
			tokenId: 'lev-42'
		);
	}//end supplierGrant()

	/**
	 * A matcher that compares equality conditions.
	 *
	 * @return ConditionMatcher The matcher.
	 */
	private function matcher(): ConditionMatcher {
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('namesUnknownMetadata')->willReturn(false);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			static function (array $object, array $match): bool {
				foreach ($match as $key => $value) {
					if (($object[$key] ?? null) !== $value) {
						return false;
					}
				}

				return true;
			}
		);

		return $matcher;
	}//end matcher()

	/**
	 * A handler whose caller is the administrator `beheerder`.
	 *
	 * @param TokenGrantSource $source The request's grant.
	 *
	 * @return PermissionHandler The handler.
	 */
	private function handler(TokenGrantSource $source): PermissionHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['admin']);

		return PermissionHandlerFactory::create(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->matcher(),
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
			$source,
			new TokenGrantNarrower()
		);
	}//end handler()

	/**
	 * The `zaak` block narrowed by the supplier's grant.
	 *
	 * @return array<string, mixed> The block.
	 */
	private static function narrowed(): array {
		return (new TokenGrantNarrower())->narrow(
			authorization: ['read' => ['medewerkers']],
			use: new TokenGrantUse(grant: self::supplierGrant(), target: new TokenGrantTarget(schemaSlug: 'zaak', registerSlug: 'zaken'))
		);
	}//end narrowed()

	/**
	 * The group question for a caller on an object.
	 *
	 * @param string      $leverancier The object's supplier.
	 * @param string      $groupId     The group asked about.
	 * @param string|null $owner       The object's owner.
	 *
	 * @return GroupRuleRequest The question.
	 */
	private static function readOf(string $leverancier, string $groupId = 'admin', ?string $owner = null): GroupRuleRequest {
		return (new GroupRuleRequest(authorization: self::narrowed(), action: 'read'))
			->forGroup(groupId: $groupId, userGroup: $groupId)
			->forUser(userId: 'beheerder', objectOwner: $owner)
			->onObject(data: ['leverancier' => $leverancier], organisation: null);
	}//end readOf()

	public function testTheAdministratorReadsOnlyTheTokensRows(): void {
		$handler = $this->handler(source: new TokenGrantSource());

		$this->assertTrue($handler->hasGroupPermission(request: self::readOf(leverancier: 'lev-42')));
		$this->assertFalse(
			$handler->hasGroupPermission(request: self::readOf(leverancier: 'lev-99')),
			'the admin bypass must not reach past the row condition'
		);
	}//end testTheAdministratorReadsOnlyTheTokensRows()

	public function testTheOwnerReadsOnlyTheTokensRows(): void {
		$this->assertFalse(
			$this->handler(source: new TokenGrantSource())->hasGroupPermission(
				request: self::readOf(leverancier: 'lev-99', groupId: 'medewerkers', owner: 'beheerder')
			),
			'nor the owner bypass'
		);
	}//end testTheOwnerReadsOnlyTheTokensRows()

	public function testAQuestionAboutNoObjectIsAnsweredByTheVerbs(): void {
		$request = (new GroupRuleRequest(authorization: self::narrowed(), action: 'read'))
			->forGroup(groupId: 'admin', userGroup: 'admin')
			->forUser(userId: 'beheerder', objectOwner: null);

		$this->assertTrue($this->handler(source: new TokenGrantSource())->hasGroupPermission(request: $request));
	}//end testAQuestionAboutNoObjectIsAnsweredByTheVerbs()

	public function testHasPermissionRefusesTheAdministratorAnotherSuppliersObject(): void {
		$source = new TokenGrantSource();
		$source->bind(grant: self::supplierGrant());
		$handler = $this->handler(source: $source);

		$schema = new Schema();
		$schema->setId(3);
		$schema->setSlug('zaak');
		$schema->setAuthorization(['read' => ['medewerkers'], 'update' => ['medewerkers']]);

		$this->assertFalse($handler->hasPermission(schema: $schema, action: 'read', userId: 'beheerder', object: $this->object(leverancier: 'lev-99')));
		$this->assertTrue($handler->hasPermission(schema: $schema, action: 'read', userId: 'beheerder', object: $this->object(leverancier: 'lev-42')));
		$this->assertFalse($handler->hasPermission(schema: $schema, action: 'update', userId: 'beheerder', object: $this->object(leverancier: 'lev-42')));

		$untokened = $this->handler(source: new TokenGrantSource());
		$this->assertTrue(
			$untokened->hasPermission(schema: $schema, action: 'update', userId: 'beheerder', object: $this->object(leverancier: 'lev-99')),
			'without a token the administrator is unaffected'
		);
	}//end testHasPermissionRefusesTheAdministratorAnotherSuppliersObject()

	/**
	 * An object of `zaak` for a supplier.
	 *
	 * @param string $leverancier The supplier.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $leverancier): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('0b4a0b4a-0000-4000-8000-0000000000' . substr($leverancier, -2));
		$object->setSchema('3');
		$object->setObject(['leverancier' => $leverancier]);

		return $object;
	}//end object()
}//end class
