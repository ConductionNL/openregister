<?php

/**
 * Unit tests for ImportRedactionPolicyRegister.
 *
 * The step imports the shipped redaction-policy descriptor under its own id.
 * Its listed objects are the base layer: each carries `layer: base`, a stable
 * slug and a version, so the importer finds it again by slug, and nothing it
 * lists is a `local` object, so an organisation's own rules are never written.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\AbstractRegisterImportStep;
use OCA\OpenRegister\Repair\ImportRedactionPolicyRegister;
use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ImportRedactionPolicyRegister::class)]
#[CoversClass(AbstractRegisterImportStep::class)]
#[CoversClass(RegisterDescriptorImport::class)]
class ImportRedactionPolicyRegisterTest extends TestCase {

	/**
	 * Run the step against the shipped descriptor and return what the importer was handed.
	 *
	 * @return array{appId: string, data: array<string, mixed>, version: string, force: bool}
	 */
	private function imported(): array {
		$imported = null;
		$importer = $this->createMock(ConfigurationService::class);
		$importer->expects($this->once())
			->method('importFromApp')
			->willReturnCallback(
				static function (string $appId, array $data, string $version, bool $force) use (&$imported): array {
					$imported = ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force];
					return [];
				}
			);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->with('openregister')->willReturn(dirname(__DIR__, 3));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');

		(new ImportRedactionPolicyRegister(
			new RegisterDescriptorImport($importer, $apps, $this->createMock(LoggerInterface::class))
		))->run($output);

		return $imported;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function listedObjects(): array {
		return $this->imported()['data']['components']['objects'];
	}

	public function testTheShippedWooProfileIsPresent(): void {
		$imported = $this->imported();

		$this->assertSame('openregister.redaction-policy', $imported['appId']);
		$this->assertSame('1.0.0', $imported['version']);
		$this->assertFalse($imported['force']);
		$this->assertSame(
			['termRule', 'detectionPattern', 'redactionProfile'],
			$imported['data']['components']['registers']['redaction-policy']['schemas']
		);

		$woo = array_values(array_filter(
			$imported['data']['components']['objects'],
			static fn (array $object): bool => ($object['@self']['slug'] ?? null) === 'woo'
		));
		$this->assertCount(1, $woo);
		$this->assertSame('redactionProfile', $woo[0]['@self']['schema']);
		$this->assertSame(['PERSON', 'BSN', 'PHONE', 'EMAIL', 'IBAN', 'ADDRESS'], $woo[0]['redact']);
		$this->assertSame(['ORGANIZATION', 'LOCATION', 'DATE'], $woo[0]['keep']);
	}

	public function testABaseObjectIsUpdatedBySlug(): void {
		$slugs = [];
		foreach ($this->listedObjects() as $object) {
			// The importer finds a listed object again by register, schema and
			// @self.slug, and updates it when @self.version rises.
			$this->assertSame('redaction-policy', $object['@self']['register']);
			$this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $object['@self']['slug']);
			$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $object['@self']['version']);
			$slugs[] = $object['@self']['schema'] . '/' . $object['@self']['slug'];
		}

		$this->assertNotSame([], $slugs);
		$this->assertSame($slugs, array_unique($slugs), 'two base objects share a slug');
	}

	public function testAnUpdateKeepsLocalObjects(): void {
		// The importer writes only what the descriptor lists; every listed
		// object is base, so no local object is ever created, updated or deleted.
		foreach ($this->listedObjects() as $object) {
			$this->assertSame('base', $object['layer'], $object['@self']['slug'] . ' is not a base object');
			$this->assertArrayNotHasKey('overrides', $object);
		}

		$schemas = $this->imported()['data']['components']['schemas'];
		foreach (['termRule', 'detectionPattern', 'redactionProfile'] as $schema) {
			$this->assertSame(['base', 'local'], $schemas[$schema]['properties']['layer']['enum']);
			$this->assertSame('local', $schemas[$schema]['properties']['layer']['default'], $schema . ': a new object defaults to local');
		}
	}
}
