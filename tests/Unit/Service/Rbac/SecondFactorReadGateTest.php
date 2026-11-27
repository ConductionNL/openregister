<?php

/**
 * A scope requiring a second factor refuses a reader without one (REQ-IHC-003).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\SecondFactorReadGate;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for {@see SecondFactorReadGate}.
 */
#[CoversClass(SecondFactorReadGate::class)]
final class SecondFactorReadGateTest extends TestCase {

	/**
	 * The providers enabled for `bea`.
	 *
	 * @var array<string, bool>
	 */
	private array $providers = [];

	/**
	 * The app password in the session, if the request signed in with one.
	 *
	 * @var string|null
	 */
	private ?string $appPassword = null;

	/**
	 * Configurations of the registers holding the schema.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $registerConfigurations = [];

	/**
	 * Whether reading the registers fails.
	 *
	 * @var boolean
	 */
	private bool $registersFail = false;

	/**
	 * How often the provider states were read.
	 *
	 * @var integer
	 */
	private int $providerReads = 0;

	public function testAConfidentialSchemaRefusesASingleFactorNamingTheRequirement(): void {
		$refusal = $this->gate()->readRefusal($this->schema(declares: true), 'bea');

		$this->assertSame("Reading schema 'Dossiers' requires a verified second factor", $refusal);
	}//end testAConfidentialSchemaRefusesASingleFactorNamingTheRequirement()

	public function testAVerifiedSecondFactorReads(): void {
		$this->providers = ['totp' => true, 'backup_codes' => true];

		$this->assertNull($this->gate()->readRefusal($this->schema(declares: true), 'bea'));
	}//end testAVerifiedSecondFactorReads()

	public function testBackupCodesAloneAreNotASecondFactor(): void {
		$this->providers = ['backup_codes' => true, 'totp' => false];

		$this->assertNotNull($this->gate()->readRefusal($this->schema(declares: true), 'bea'));
	}//end testBackupCodesAloneAreNotASecondFactor()

	public function testAnAppPasswordSignInSkipsTheFactorAndIsRefused(): void {
		$this->providers = ['totp' => true];
		$this->appPassword = 'token-1';

		$this->assertNotNull($this->gate()->readRefusal($this->schema(declares: true), 'bea'));
	}//end testAnAppPasswordSignInSkipsTheFactorAndIsRefused()

	public function testAnAnonymousReaderIsRefused(): void {
		$this->assertNotNull($this->gate()->readRefusal($this->schema(declares: true), null));
	}//end testAnAnonymousReaderIsRefused()

	public function testAnUnknownAccountIsRefused(): void {
		$this->providers = ['totp' => true];

		$this->assertNotNull($this->gate()->readRefusal($this->schema(declares: true), 'nobody'));
	}//end testAnUnknownAccountIsRefused()

	public function testAScopeDeclaringNothingReadsAsBefore(): void {
		$this->registerConfigurations = [[], ['other' => true]];

		$this->assertNull($this->gate()->readRefusal($this->schema(declares: false), 'bea'));
		$this->assertNull($this->gate()->readRefusal($this->schema(declares: false), null));
	}//end testAScopeDeclaringNothingReadsAsBefore()

	public function testARegisterHoldingTheSchemaDeclaresIt(): void {
		$this->registerConfigurations = [[], [SecondFactorReadGate::ANNOTATION => true]];

		$refusal = $this->gate()->readRefusal($this->schema(declares: false), 'bea');

		$this->assertSame("Reading register 'Register 2' requires a verified second factor", $refusal);
	}//end testARegisterHoldingTheSchemaDeclaresIt()

	public function testUnreadableRegistersRequireTheFactor(): void {
		$this->registersFail = true;

		$this->assertNotNull($this->gate()->readRefusal($this->schema(declares: false), 'bea'));
	}//end testUnreadableRegistersRequireTheFactor()

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function declarations(): array {
		return [
			'true' => [true, true],
			'a string' => ['yes', false],
			'one' => [1, false],
			'false' => [false, false],
		];
	}//end declarations()

	#[DataProvider('declarations')]
	public function testOnlyTheLiteralTrueDeclares(mixed $value, bool $declares): void {
		$this->assertSame($declares, SecondFactorReadGate::declares([SecondFactorReadGate::ANNOTATION => $value]));
	}//end testOnlyTheLiteralTrueDeclares()

	public function testTheFactorIsReadOncePerRequest(): void {
		$gate = $this->gate();

		$gate->readRefusal($this->schema(declares: true), 'bea');
		$gate->readRefusal($this->schema(declares: true), 'bea');

		$this->assertSame(1, $this->providerReads);
	}//end testTheFactorIsReadOncePerRequest()

	private function gate(): SecondFactorReadGate {
		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnMap([['bea', $user], ['nobody', null]]);

		$registry = $this->createMock(IRegistry::class);
		$registry->method('getProviderStates')->willReturnCallback(
			function (): array {
				$this->providerReads++;
				return $this->providers;
			}
		);

		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(
			function (string $key): ?string {
				if ($key !== 'app_password') {
					return null;
				}

				return $this->appPassword;
			}
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('getAllRegisterIdsWithSchema')->willReturnCallback(
			function (): array {
				if ($this->registersFail === true) {
					throw new RuntimeException('database gone');
				}

				return array_keys(array_fill(1, count($this->registerConfigurations), true));
			}
		);
		$registers->method('find')->willReturnCallback(
			function (int|string $id): Register {
				$register = new Register();
				$register->setTitle('Register ' . $id);
				$register->setConfiguration($this->registerConfigurations[((int)$id - 1)]);

				return $register;
			}
		);

		return new SecondFactorReadGate($registry, $users, $session, $registers, $this->createMock(LoggerInterface::class));
	}//end gate()

	private function schema(bool $declares): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Dossiers');
		$configuration = [];
		if ($declares === true) {
			$configuration[SecondFactorReadGate::ANNOTATION] = true;
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()
}//end class
