<?php

/**
 * Unit tests for DiscoveryManifest — parsing and publishing the `discovery`
 * block of an app manifest.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Discovery
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Discovery;

use OCA\OpenRegister\AppHost\Discovery\DiscoveryManifest;
use PHPUnit\Framework\TestCase;

class DiscoveryManifestTest extends TestCase {

	private function manifest(array $discovery): array {
		return ['version' => '1.0.0', 'discovery' => $discovery];
	}

	public function testMissingBlockIsEmptyAndNotPublic(): void {
		$manifest = DiscoveryManifest::fromManifest(appId: 'demo', manifest: ['version' => '1.0.0']);

		$this->assertFalse($manifest->public);
		$this->assertTrue($manifest->isEmpty());
		$this->assertSame([], $manifest->getProblems());
	}

	public function testValidStandardIsKeptAndPublishedWithEndpoint(): void {
		$manifest = DiscoveryManifest::fromManifest(appId: 'decidiq', manifest: $this->manifest([
			'standards' => [[
				'id' => 'ori',
				'name' => 'Open Raadsinformatie',
				'version' => '1.0',
				'role' => 'provides',
				'access' => 'public',
				'endpoint' => '/apps/decidiq/api/ori/v1',
				'specUrl' => 'https://example.org/ori',
			]],
		]));

		$this->assertTrue($manifest->public);
		$this->assertSame([], $manifest->getProblems());
		$payload = $manifest->toPublicPayload();
		$this->assertSame(DiscoveryManifest::CONTRACT_VERSION, $payload['contractVersion']);
		$this->assertSame('/apps/decidiq/api/ori/v1', $payload['standards'][0]['endpoint']);
		$this->assertArrayNotHasKey('links', $payload);
		$this->assertArrayNotHasKey('ocm', $payload);
	}

	public function testAccessDefaultsToAuthenticatedAndHidesEndpoint(): void {
		$manifest = DiscoveryManifest::fromManifest(appId: 'zaak', manifest: $this->manifest([
			'standards' => [[
				'id' => 'zgw-zaken',
				'name' => 'ZGW Zaken API',
				'role' => 'provides',
				'endpoint' => '/apps/zaak/api/zrc',
			]],
		]));

		$this->assertSame('authenticated', $manifest->standards[0]['access']);
		$published = $manifest->toPublicPayload()['standards'][0];
		$this->assertArrayNotHasKey('endpoint', $published);
		$this->assertSame('zgw-zaken', $published['id']);
	}

	public function testTokenAccessKeepsEndpoint(): void {
		$manifest = DiscoveryManifest::fromManifest(appId: 'dossiq', manifest: $this->manifest([
			'standards' => [[
				'id' => 'zgw-zaken', 'name' => 'ZGW Zaken API', 'role' => 'provides',
				'access' => 'token', 'endpoint' => '/apps/dossiq/api/zgw/zaken/v1',
			]],
		]));

		$this->assertSame('/apps/dossiq/api/zgw/zaken/v1', $manifest->toPublicPayload()['standards'][0]['endpoint']);
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function invalidStandards(): array {
		$base = ['id' => 'ori', 'name' => 'ORI', 'role' => 'provides'];
		return [
			'not an object' => ['x', 'entry must be an object'],
			'bad id' => [['id' => 'Not A Slug'] + $base, 'id must be'],
			'missing name' => [['name' => ' '] + $base, 'name is required'],
			'unknown role' => [['role' => 'offers'] + $base, 'role must be'],
			'unknown access' => [$base + ['access' => 'anyone'], 'access must be'],
			'absolute endpoint' => [$base + ['endpoint' => 'https://evil.example/x'], 'endpoint must be'],
			'protocol relative' => [$base + ['endpoint' => '//evil.example/x'], 'endpoint must be'],
			'traversal endpoint' => [$base + ['endpoint' => '/apps/../../etc'], 'endpoint must be'],
			'http spec url' => [$base + ['specUrl' => 'http://example.org'], 'specUrl must be'],
			'empty version' => [$base + ['version' => ''], 'version must be'],
		];
	}

	/**
	 * @dataProvider invalidStandards
	 */
	public function testInvalidStandardIsDroppedWithReason(mixed $entry, string $reason): void {
		$manifest = DiscoveryManifest::fromManifest(appId: 'demo', manifest: $this->manifest([
			'standards' => [
				$entry,
				['id' => 'kept', 'name' => 'Kept', 'role' => 'consumes'],
			],
		]));

		$this->assertCount(1, $manifest->standards, 'only the invalid entry is dropped');
		$this->assertSame('kept', $manifest->standards[0]['id']);
		$this->assertCount(1, $manifest->getProblems());
		$this->assertStringContainsString('standards[0]', $manifest->getProblems()[0]);
		$this->assertStringContainsString($reason, $manifest->getProblems()[0]);
	}

	public function testLinksMustBeLocalPathsUnderSlugNames(): void {
		$manifest = DiscoveryManifest::fromManifest(appId: 'opencatalogi', manifest: $this->manifest([
			'links' => [
				'directory' => '/apps/opencatalogi/api/directory',
				'Bad Name' => '/x',
				'elsewhere' => 'https://other.example/api',
			],
		]));

		$this->assertSame(['directory' => '/apps/opencatalogi/api/directory'], $manifest->links);
		$this->assertCount(2, $manifest->getProblems());
		$this->assertSame(['directory' => '/apps/opencatalogi/api/directory'], $manifest->toPublicPayload()['links']);
	}

	public function testOcmResourceTypesAreValidatedAndPublishedByName(): void {
		$manifest = DiscoveryManifest::fromManifest(appId: 'dossiq', manifest: $this->manifest([
			'ocmResourceTypes' => [
				['name' => 'dossiq-case', 'shareTypes' => ['user', 'group'], 'protocols' => ['dossiq' => '/apps/dossiq/api/federation']],
				['name' => 'Bad', 'shareTypes' => ['user'], 'protocols' => ['x' => '/x']],
				['name' => 'no-share-types', 'shareTypes' => [], 'protocols' => ['x' => '/x']],
				['name' => 'no-protocols', 'shareTypes' => ['user'], 'protocols' => []],
				['name' => 'remote-protocol', 'shareTypes' => ['user'], 'protocols' => ['x' => 'https://evil.example']],
			],
		]));

		$this->assertCount(1, $manifest->ocmResourceTypes);
		$this->assertSame('dossiq-case', $manifest->ocmResourceTypes[0]['name']);
		$this->assertCount(4, $manifest->getProblems());
		$this->assertSame(['dossiq-case'], $manifest->toPublicPayload()['ocm']);
	}

	public function testPublicFalseIsRespected(): void {
		$manifest = DiscoveryManifest::fromManifest(appId: 'demo', manifest: $this->manifest([
			'public' => false,
			'standards' => [['id' => 'ori', 'name' => 'ORI', 'role' => 'provides']],
		]));

		$this->assertFalse($manifest->public);
		$this->assertFalse($manifest->isEmpty());
	}

	public function testNonListStandardsAreIgnored(): void {
		$manifest = DiscoveryManifest::fromManifest(appId: 'demo', manifest: $this->manifest([
			'standards' => ['ori' => ['id' => 'ori', 'name' => 'ORI', 'role' => 'provides']],
		]));

		$this->assertSame([], $manifest->standards);
		$this->assertTrue($manifest->isEmpty());
	}
}
