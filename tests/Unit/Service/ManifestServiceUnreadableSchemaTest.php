<?php

/**
 * Unit tests for a profile whose schema configuration cannot be read when the manifest is enriched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\ManifestService;
use OCA\OpenRegister\Service\ManifestSources;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When the schema's configuration cannot be read on the second lookup, no allowlist applies:
 * only the always-safe fields of the profile reach the manifest (fail closed).
 *
 * @covers \OCA\OpenRegister\Service\ManifestService
 */
class ManifestServiceUnreadableSchemaTest extends TestCase {

	/**
	 * What the configuration lookup does after the schema was first found.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function unreadable(): array {
		return [
			'the lookup throws'      => [static function (): never {
				throw new RuntimeException('db gone');
			}],
			'the schema has vanished' => [static fn (): array => []],
		];

	}//end unreadable()

	/**
	 * An unreadable configuration lets only id and roles through.
	 *
	 * @param callable $later The configuration lookup.
	 *
	 * @return void
	 */
	#[DataProvider('unreadable')]
	public function testAnUnreadableConfigurationFailsClosed(callable $later): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-manifest-user-fields' => ['name', 'ssn']]);
		$calls   = 0;
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findBySlug')->willReturnCallback(
			static function () use (&$calls, $schema, $later): array {
				$calls++;
				return $calls === 1 ? [$schema] : $later();
			}
		);

		$profile = new ObjectEntity();
		$profile->setObject(['roles' => ['teacher'], 'name' => 'Ada', 'ssn' => '123']);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('findAll')->willReturn([$profile]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$service = new ManifestService(
			new ManifestSources($objects, $schemas),
			$this->createMock(CalculationEvaluatorInterface::class),
			$session,
			$this->createMock(LoggerInterface::class)
		);

		$manifest = $service->getEnrichedManifest(['currentUserSchema' => 'user-profile']);

		$user = $manifest['runtime']['user'];
		ksort($user);
		$this->assertSame(['id' => 'alice', 'roles' => ['teacher']], $user, 'name and ssn stay out without a readable allowlist');
		$this->assertGreaterThan(1, $calls, 'the configuration was looked up again');

	}//end testAnUnreadableConfigurationFailsClosed()
}//end class
