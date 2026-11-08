<?php

declare(strict_types=1);

/**
 * DsarPolicyPackResolver + PrivacyOfficerRecipientResolver golden test.
 *
 * Pins pack resolution (jurisdiction match wins, else the LAST `default`
 * pack, else null), the RBAC / tenancy flags of the pack read (scoped for a
 * case read, unscoped only for a system read), the fail-closed answers on a
 * read failure, and the privacy-officer recipient expansion with every
 * fail-safe refusal.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Policy
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Policy;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\Gdpr\PrivacyOfficerRecipientResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for the policy-pack resolver and the officer recipient resolver.
 */
class DsarPolicyPackResolverGoldenTest extends TestCase {

	/**
	 * findAll() calls: [config, rbac, multitenancy].
	 *
	 * @var list<array{0: array, 1: bool, 2: bool}>
	 */
	private array $reads = [];

	/**
	 * Warnings logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Build a resolver over the given rows (null = the read throws).
	 *
	 * @param array<int, mixed>|null $rows Rows findAll() returns.
	 *
	 * @return DsarPolicyPackResolver
	 */
	private function resolver(?array $rows): DsarPolicyPackResolver {
		$objectService = $this->createMock(ObjectOperationsInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $rbac, bool $multitenancy) use ($rows) {
				$this->reads[] = [$config, $rbac, $multitenancy];
				if ($rows === null) {
					throw new RuntimeException('store down');
				}

				return $rows;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message) {
				$this->warnings[] = (string)$message;
			}
		);

		return new DsarPolicyPackResolver(objectService: $objectService, logger: $logger);

	}//end resolver()

	/**
	 * The packs every resolution test reads.
	 *
	 * @return array<int, mixed>
	 */
	private function packs(): array {
		return [
			'junk',
			['jurisdiction' => 'default', 'identityVerifyProvider' => 'first-default'],
			['jurisdiction' => 'nl', 'identityVerifyProvider' => 'digid', 'regulatorEscalateProvider' => 'ap'],
			['jurisdiction' => 'nl', 'identityVerifyProvider' => 'second-nl'],
			['jurisdiction' => 'default', 'identityVerifyProvider' => 'last-default', 'regulatorEscalateProvider' => ''],
			['jurisdiction' => 7, 'identityVerifyProvider' => 'non-string'],
		];

	}//end packs()

	/**
	 * Selectors and active pack, scoped and system reads.
	 *
	 * @return void
	 */
	public function testResolution(): void {
		$resolver = $this->resolver($this->packs());
		$query = ['filters' => ['register' => DsarPolicyPackResolver::REGISTER_SLUG, 'schema' => DsarPolicyPackResolver::SCHEMA_SLUG]];

		$this->assertSame('digid', $resolver->identityVerifyProviderId(case: ['jurisdiction' => 'nl']));
		$this->assertSame('ap', $resolver->regulatorEscalateProviderId(case: ['jurisdiction' => 'nl']));
		$this->assertSame('last-default', $resolver->identityVerifyProviderId(case: ['jurisdiction' => 'be']));
		$this->assertNull($resolver->regulatorEscalateProviderId(case: []));
		$this->assertSame('last-default', $resolver->identityVerifyProviderId(case: ['jurisdiction' => 7]));

		$this->assertSame($this->packs()[2], $resolver->activePackForCase(case: ['jurisdiction' => 'nl']));
		$this->assertSame($this->packs()[4], $resolver->activePackForCase(case: ['jurisdiction' => '']));
		$this->assertSame($this->packs()[2], $resolver->activePackForCase(case: ['jurisdiction' => 'nl'], systemContext: true));
		$this->assertSame($this->packs()[4], $resolver->activePackForCase(case: [], systemContext: true));

		$scoped = [$query, true, true];
		$system = [$query, false, false];
		$this->assertSame([$scoped, $scoped, $scoped, $scoped, $scoped, $scoped, $scoped, $system, $system], $this->reads);
		$this->assertSame([], $this->warnings);

	}//end testResolution()

	/**
	 * No default pack: an unmatched jurisdiction resolves nothing.
	 *
	 * @return void
	 */
	public function testNoDefault(): void {
		$resolver = $this->resolver([['jurisdiction' => 'nl', 'identityVerifyProvider' => 'digid']]);

		$this->assertNull($resolver->activePackForCase(case: ['jurisdiction' => 'de']));
		$this->assertNull($resolver->activePackForCase(case: ['jurisdiction' => 'de'], systemContext: true));
		$this->assertNull($resolver->identityVerifyProviderId(case: ['jurisdiction' => 'de']));

	}//end testNoDefault()

	/**
	 * A failing read resolves nothing (fail-closed) and warns.
	 *
	 * @return void
	 */
	public function testReadFailureFailsClosed(): void {
		$resolver = $this->resolver(null);

		$this->assertNull($resolver->identityVerifyProviderId(case: ['jurisdiction' => 'nl']));
		$this->assertNull($resolver->activePackForCase(case: ['jurisdiction' => 'nl'], systemContext: true));
		$this->assertSame(
			[
				'[DsarPolicyPackResolver] policy-pack read failed: store down',
				'[DsarPolicyPackResolver] policy-pack read failed: store down',
			],
			$this->warnings
		);

	}//end testReadFailureFailsClosed()

	/**
	 * Officer recipients: system-scoped pack read, deduplicated uids, every refusal.
	 *
	 * @return void
	 */
	public function testOfficerRecipients(): void {
		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$case->setObject(['jurisdiction' => 'nl']);

		$users = [];
		foreach (['alice', 'bob', 'alice'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			static function (string $gid) use ($group) {
				if ($gid === 'officers') {
					return $group;
				}

				if ($gid === 'broken') {
					throw new RuntimeException('ldap down');
				}

				return null;
			}
		);

		$expectations = [
			'officers' => ['alice', 'bob'],
			'ghost' => [],
			'broken' => [],
			'<privacy-officer-group>' => [],
			'' => [],
		];
		foreach ($expectations as $gid => $expected) {
			$this->reads = [];
			$resolver = new PrivacyOfficerRecipientResolver(
				packResolver: $this->resolver([['jurisdiction' => 'nl', 'privacyOfficerGroup' => $gid]]),
				groupManager: $groupManager,
				logger: $this->createMock(LoggerInterface::class)
			);
			$this->assertSame($expected, $resolver->resolve(object: $case, context: []), (string)$gid);
			$this->assertFalse($this->reads[0][1]);
			$this->assertFalse($this->reads[0][2]);
		}

		$nonString = new PrivacyOfficerRecipientResolver(
			packResolver: $this->resolver([['jurisdiction' => 'nl', 'privacyOfficerGroup' => ['officers']]]),
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class)
		);
		$this->assertSame([], $nonString->resolve(object: $case, context: []));

		$noPack = new PrivacyOfficerRecipientResolver(
			packResolver: $this->resolver([]),
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class)
		);
		$this->assertSame([], $noPack->resolve(object: $case, context: []));

	}//end testOfficerRecipients()
}//end class
