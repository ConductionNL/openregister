<?php

/**
 * Golden test for ImportService::registerFromBundle().
 *
 * Records, for documents of every shape, whether it refuses the document as
 * no register bundle (with the exception's message, code and remedies) or
 * goes on to create the register from it, which the container stub stops at
 * the first step. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\Object\ValidateObjectInterface;
use OCA\OpenRegister\Service\Translation\TranslationCsvCodec;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ImportService
 * @uses   \OCA\OpenRegister\Exception\RegisterNotFoundException
 */
class ImportServiceRegisterBundleGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/import-register-bundle-golden.json';

	private static function documents(): array {
		$registers = ['registers' => ['r' => ['slug' => 'r']]];
		return [
			'empty' => [[], ['registerSlug' => 'want']],
			'list' => [[['components' => $registers]], ['registerSlug' => 'want']],
			'list of objects' => [[['a' => 1], ['b' => 2]], []],
			'no components' => [['results' => []], ['registerSlug' => 'want']],
			'components not an array' => [['components' => 'x'], ['registerSlug' => 'want']],
			'no registers' => [['components' => ['schemas' => ['s' => []]]], ['registerSlug' => 'want']],
			'registers null' => [['components' => ['registers' => null]], ['registerSlug' => 'want']],
			'registers string' => [['components' => ['registers' => 'r']], ['registerSlug' => 'want']],
			'registers empty' => [['components' => ['registers' => []]], []],
			'registers keyed' => [['components' => $registers], ['registerSlug' => 'want']],
			'registers list' => [['components' => ['registers' => [['slug' => 'r']]]], []],
			'registers list of null' => [['components' => ['registers' => [null]]], []],
			'non-list keys' => [[1 => ['components' => $registers], 'components' => $registers], []],
			'slug null' => [['openapi' => '3.0'], ['registerSlug' => null]],
		];
	}//end documents()

	public function testRegisterBundleMatchesGolden(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): never {
				throw new RuntimeException('container asked for ' . $id);
			}
		);
		$service = new ImportService(
			new \OCA\OpenRegister\Service\Import\ImportServiceStores($this->createMock(SchemaMapperInterface::class), $this->createMock(ObjectOperationsInterface::class), $this->createMock(AuditTrailMapperInterface::class), $this->createMock(ValidateObjectInterface::class)),
			new \OCA\OpenRegister\Service\Import\ImportServiceCodecs($this->createMock(TranslationCsvCodec::class), (new ReflectionClass(MappingEngine::class))->newInstanceWithoutConstructor()),
			new \OCA\OpenRegister\Service\Import\ImportServiceRuntime($container, new \OCA\OpenRegister\Listener\NotifyPushBatch(), $this->createMock(LoggerInterface::class)),
			$this->createMock(IGroupManager::class)
		);
		$method = new ReflectionMethod(ImportService::class, 'registerFromBundle');
		$method->setAccessible(true);

		$actual = [];
		foreach (self::documents() as $name => [$decoded, $target]) {
			$target['schema'] = null;
			try {
				$method->invoke($service, $decoded, $target);
				$actual[$name] = ['returned' => true];
			} catch (RegisterNotFoundException $e) {
				$actual[$name] = [
					'refused' => $e->getMessage(),
					'code' => $e->getCode(),
				];
			} catch (RuntimeException $e) {
				$actual[$name] = ['autoCreate' => $e->getMessage()];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testRegisterBundleMatchesGolden()
}//end class
