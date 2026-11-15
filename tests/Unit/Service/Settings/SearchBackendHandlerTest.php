<?php

declare(strict_types=1);

/**
 * SearchBackendHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\AccentFoldingSetting;
use OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe;
use OCA\OpenRegister\Service\Settings\SearchBackendHandler;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SearchBackendHandler
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Comprehensive coverage requires many test methods
 *
 * @covers \OCA\OpenRegister\Service\Settings\SearchBackendHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\AccentFoldingSetting
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe
 */
class SearchBackendHandlerTest extends TestCase {
	/** @var SearchBackendHandler */
	private SearchBackendHandler $handler;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->handler = new SearchBackendHandler();
	}

	/**
	 * Test getSearchBackendConfig always returns database as active backend.
	 *
	 * @return void
	 */
	public function testGetSearchBackendConfigReturnsDefaultWhenEmpty(): void {
		$result = $this->handler->getSearchBackendConfig();

		$this->assertSame('database', $result['active']);
		$this->assertSame(['database'], $result['available']);
		$this->assertCount(1, $result['available']);
	}

	/**
	 * Test getSearchBackendConfig always returns database regardless of stored config.
	 *
	 * @return void
	 */
	public function testGetSearchBackendConfigReturnsDecodedConfig(): void {
		$result = $this->handler->getSearchBackendConfig();

		$this->assertSame('database', $result['active']);
		$this->assertSame(['database'], $result['available']);
	}

	/**
	 * The stored app config values of the fake.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * A handler on MySQL (folds through a collation) or SQLite (cannot fold).
	 *
	 * @param bool $foldable Whether the database can fold accents.
	 *
	 * @return SearchBackendHandler
	 */
	private function accentHandler(bool $foldable): SearchBackendHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('openregister', $app);
				$this->stored[$key] = $value;
				return true;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($foldable === true ? new MySQLPlatform() : new SqlitePlatform());

		return new SearchBackendHandler(appConfig: $appConfig, db: $db, logger: $this->createMock(LoggerInterface::class));
	}//end accentHandler()

	/**
	 * Where the database can fold, accent folding is on until switched off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
	 */
	public function testAccentFoldingIsOnByDefaultWhereAvailable(): void {
		$handler = $this->accentHandler(foldable: true);

		$this->assertSame(
			['enabled' => true, 'available' => true, 'reason' => ''],
			$handler->getSearchBackendConfig()['accentInsensitive']
		);

		$this->assertSame(['enabled' => false, 'available' => true, 'reason' => ''], $handler->setAccentInsensitive(enabled: false));
		$this->assertSame('false', $this->stored[AccentFoldingSetting::KEY]);
		$this->assertSame(['enabled' => true, 'available' => true, 'reason' => ''], $handler->setAccentInsensitive(enabled: true));
		$this->assertSame('true', $this->stored[AccentFoldingSetting::KEY]);
	}//end testAccentFoldingIsOnByDefaultWhereAvailable()

	/**
	 * Where the database cannot fold, the state says why, enabling is refused
	 * naming the reason and stores nothing, and switching off still works.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
	 */
	public function testUnavailableFoldingNamesTheReasonAndRefusesEnabling(): void {
		$handler = $this->accentHandler(foldable: false);
		$state   = ['enabled' => false, 'available' => false, 'reason' => MagicUnaccentProbe::REASON_UNSUPPORTED];

		$this->assertSame($state, $handler->getSearchBackendConfig()['accentInsensitive']);

		try {
			$handler->setAccentInsensitive(enabled: true);
			$this->fail('Enabling unavailable folding must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString(MagicUnaccentProbe::REASON_UNSUPPORTED, $e->getMessage());
		}

		$this->assertSame([], $this->stored);
		$this->assertSame($state, $handler->setAccentInsensitive(enabled: false));
		$this->assertSame('false', $this->stored[AccentFoldingSetting::KEY]);
	}//end testUnavailableFoldingNamesTheReasonAndRefusesEnabling()

	/**
	 * Without app config nothing can be stored and folding reads as off.
	 *
	 * @return void
	 */
	public function testWithoutDependenciesFoldingIsOffAndCannotBeStored(): void {
		$this->assertSame(
			['enabled' => false, 'available' => false, 'reason' => MagicUnaccentProbe::REASON_UNSUPPORTED],
			$this->handler->accentInsensitive()
		);

		$this->expectException(InvalidArgumentException::class);
		$this->handler->setAccentInsensitive(enabled: false);
	}//end testWithoutDependenciesFoldingIsOffAndCannotBeStored()
}
