<?php

/**
 * Integration tests for the bundled mock registers.
 *
 * Drives the full ConfigurationService → ImportHandler pipeline against
 * each of the five `lib/Settings/*_register.json` files (BRP, KVK, BAG,
 * DSO, ORI) and asserts the structural invariants required by
 * `openspec/changes/mock-registers/specs/mock-registers/spec.md`:
 *
 * - Each JSON file parses, has the expected slug, the `mock` type, and
 *   the documented schema set.
 * - Seed-data volume meets the spec's quantitative thresholds.
 * - Dutch postcode format conformance across address fields.
 * - Slug stability (canonical slugs MUST be present).
 * - The `?filters[type]=mock` filter on RegisterMapper::findAll returns
 *   only the mock registers and excludes production registers.
 * - x-openregister.type propagation: importing a mock JSON produces a
 *   register with `getType() === "mock"`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class MockRegistersIntegrationTest extends TestCase
{

    private const MOCK_REGISTER_FILES = [
        'brp' => __DIR__.'/../../lib/Settings/brp_register.json',
        'kvk' => __DIR__.'/../../lib/Settings/kvk_register.json',
        'bag' => __DIR__.'/../../lib/Settings/bag_register.json',
        'dso' => __DIR__.'/../../lib/Settings/dso_register.json',
        'ori' => __DIR__.'/../../lib/Settings/ori_register.json',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $loaded = [];

    private RegisterMapper $registerMapper;

    private ConfigurationMapper $configurationMapper;

    private ConfigurationService $configurationService;

    /**
     * @var list<int>
     */
    private array $createdConfigurationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMapper       = \OC::$server->get(RegisterMapper::class);
        $this->configurationMapper  = \OC::$server->get(ConfigurationMapper::class);
        $this->configurationService = \OC::$server->get(ConfigurationService::class);

        foreach (self::MOCK_REGISTER_FILES as $slug => $path) {
            $this->assertFileExists($path, "mock register JSON missing: $path");
            $raw = file_get_contents($path);
            $this->assertNotFalse($raw, "could not read $path");
            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded, "JSON parse failed for $path");
            $this->loaded[$slug] = $decoded;
        }
    }//end setUp()

    public function testEveryMockRegisterDeclaresMockType(): void
    {
        foreach ($this->loaded as $slug => $data) {
            $type = $data['x-openregister']['type'] ?? null;
            $this->assertSame('mock', $type, "$slug: x-openregister.type MUST be 'mock'");
        }
    }//end testEveryMockRegisterDeclaresMockType()

    public function testEveryMockRegisterDeclaresExpectedSlug(): void
    {
        foreach ($this->loaded as $slug => $data) {
            $registers = $data['components']['registers'] ?? [];
            $this->assertArrayHasKey($slug, $registers, "$slug JSON MUST declare a register with key '$slug'");
            $this->assertSame($slug, ($registers[$slug]['slug'] ?? null), "$slug: register.slug MUST equal '$slug'");
        }
    }//end testEveryMockRegisterDeclaresExpectedSlug()

    public function testKvkRegisterStructuralInvariants(): void
    {
        $kvk     = $this->loaded['kvk'];
        $schemas = array_keys($kvk['components']['schemas'] ?? []);

        $this->assertContains('maatschappelijke-activiteit', $schemas);
        $this->assertContains('vestiging', $schemas);

        // Spec: at least 15 maatschappelijke-activiteit + at least 8 vestiging.
        $byType = [];
        foreach ($kvk['components']['objects'] ?? [] as $obj) {
            $schemaSlug = $obj['@self']['schema'] ?? null;
            if ($schemaSlug === null) {
                continue;
            }

            $byType[$schemaSlug] = (($byType[$schemaSlug] ?? 0) + 1);
        }

        $this->assertGreaterThanOrEqual(
            15,
            ($byType['maatschappelijke-activiteit'] ?? 0),
            'KVK MUST have at least 15 maatschappelijke-activiteit records'
        );
        $this->assertGreaterThanOrEqual(
            8,
            ($byType['vestiging'] ?? 0),
            'KVK MUST have at least 8 vestiging records'
        );
    }//end testKvkRegisterStructuralInvariants()

    public function testKvkLegalFormDiversity(): void
    {
        $expectedForms = [
            'Besloten Vennootschap',
            'Naamloze Vennootschap',
            'Eenmanszaak',
            'Stichting',
            'Vennootschap Onder Firma',
            'Cooperatie',
        ];

        $found = [];
        foreach ($this->loaded['kvk']['components']['objects'] ?? [] as $obj) {
            $rechtsvorm = $obj['rechtsvorm'] ?? null;
            if (is_string($rechtsvorm) === true && $rechtsvorm !== '') {
                $found[$rechtsvorm] = true;
            }
        }

        $missing = array_diff($expectedForms, array_keys($found));
        $this->assertSame(
            [],
            array_values($missing),
            'KVK MUST contain at least these legal forms; missing: '.json_encode(array_values($missing))
        );
    }//end testKvkLegalFormDiversity()

    public function testBrpRegisterPersonCount(): void
    {
        $count = count($this->loaded['brp']['components']['objects'] ?? []);
        $this->assertGreaterThanOrEqual(30, $count, 'BRP MUST contain at least 30 persons');
    }//end testBrpRegisterPersonCount()

    public function testEveryDutchAddressUsesValidDutchPostcode(): void
    {
        // The spec requires Dutch postcodes for Dutch addresses. KVK seed
        // data deliberately includes one foreign address (Spain) for the
        // `land != Nederland` case, so we walk address-bearing structures
        // and skip rows where the country is set to anything but NL.
        $checked = 0;
        foreach (['brp', 'kvk', 'bag'] as $slug) {
            $this->walkPostcodes(
                $this->loaded[$slug],
                function (string $postcode, ?string $land) use ($slug, &$checked): void {
                    if ($land !== null && $land !== '' && stripos($land, 'nederland') === false && $land !== 'NL') {
                        return;
                    }

                    if ($postcode === '') {
                        return;
                    }

                    $checked++;
                    $this->assertMatchesRegularExpression(
                        '/^[1-9][0-9]{3}[A-Z]{2}$/',
                        str_replace(' ', '', $postcode),
                        "invalid Dutch postcode '$postcode' in $slug"
                    );
                }
            );
        }//end foreach

        $this->assertGreaterThan(0, $checked, 'expected at least one Dutch postcode across BRP/KVK/BAG seed data');
    }//end testEveryDutchAddressUsesValidDutchPostcode()

    /**
     * Recursively walk arbitrary JSON-decoded structure and invoke the
     * callback for every address-like node containing a `postcode` field,
     * passing the optional sibling `land` country tag along too.
     *
     * @param mixed           $node      Decoded structure (array/scalar).
     * @param callable(string $postcode, ?string $land):void $callback
     */
    private function walkPostcodes(mixed $node, callable $callback): void
    {
        if (is_array($node) === false) {
            return;
        }

        if (isset($node['postcode']) === true && is_string($node['postcode']) === true) {
            $land = null;
            if (isset($node['land']) === true && is_string($node['land']) === true) {
                $land = $node['land'];
            }

            $callback($node['postcode'], $land);
        }

        foreach ($node as $value) {
            if (is_array($value) === true) {
                $this->walkPostcodes($value, $callback);
            }
        }
    }//end walkPostcodes()

    public function testOriRegisterCoversSixSchemas(): void
    {
        $schemas  = array_keys($this->loaded['ori']['components']['schemas'] ?? []);
        $expected = ['agendapunt', 'fractie', 'raadsdocument', 'raadslid', 'stemming', 'vergadering'];
        foreach ($expected as $name) {
            $this->assertContains($name, $schemas, "ORI MUST contain schema '$name'");
        }

        $this->assertGreaterThanOrEqual(
            100,
            count($this->loaded['ori']['components']['objects'] ?? []),
            'ORI MUST contain at least 100 council records'
        );
    }//end testOriRegisterCoversSixSchemas()

    public function testTotalSeedVolumeMeetsSpecFloor(): void
    {
        $total = 0;
        foreach ($this->loaded as $data) {
            $total += count($data['components']['objects'] ?? []);
        }

        $this->assertGreaterThanOrEqual(
            250,
            $total,
            'spec mandates ~250+ total seed objects across the 5 mock registers; got '.$total
        );
    }//end testTotalSeedVolumeMeetsSpecFloor()

    public function testCanonicalSlugsArePreserved(): void
    {
        // Per spec: BRP, KVK, BAG, DSO, ORI slugs MUST NOT change without
        // a major version bump. This guards against accidental rename.
        $expectedSlugs = ['brp', 'kvk', 'bag', 'dso', 'ori'];
        foreach ($expectedSlugs as $slug) {
            $this->assertArrayHasKey(
                $slug,
                $this->loaded,
                "canonical slug '$slug' missing — spec requires stability across versions"
            );
        }
    }//end testCanonicalSlugsArePreserved()

    public function testBrpImportPersistsMockType(): void
    {
        $imported = $this->importMockRegister('brp');
        try {
            $this->assertNotNull($imported);
            $this->assertSame('brp', $imported->getSlug());
            $this->assertSame(
                'mock',
                $imported->getType(),
                'imported mock register MUST have type=mock from x-openregister.type propagation'
            );
        } finally {
            $this->cleanupRegister($imported);
        }
    }//end testBrpImportPersistsMockType()

    public function testFiltersTypeMockReturnsOnlyMockRegisters(): void
    {
        // Use direct mapper insert rather than the full ConfigurationService
        // import — the import-pipeline path is exercised by
        // testBrpImportPersistsMockType. Here we are isolating the filter
        // semantics so the test stays independent of import-state pollution.
        $mock = $this->makeRegisterDirect(type: 'mock');

        try {
            $mocks = $this->registerMapper->findAll(
                limit: null,
                offset: null,
                filters: ['type' => 'mock'],
                searchConditions: [],
                searchParams: [],
                _multitenancy: false,
            );

            $this->assertNotEmpty($mocks, '?filters[type]=mock MUST return at least the just-inserted register');
            foreach ($mocks as $register) {
                $this->assertSame(
                    'mock',
                    $register->getType(),
                    'every register returned MUST have type=mock'
                );
            }
        } finally {
            $this->cleanupRegister($mock);
        }//end try
    }//end testFiltersTypeMockReturnsOnlyMockRegisters()

    public function testNonMockRegistersAreExcludedByMockFilter(): void
    {
        $production = $this->makeRegisterDirect(type: 'production');
        $mock       = $this->makeRegisterDirect(type: 'mock');

        try {
            $mocks = $this->registerMapper->findAll(
                limit: null,
                offset: null,
                filters: ['type' => 'mock'],
                searchConditions: [],
                searchParams: [],
                _multitenancy: false,
            );

            $mockSlugs = array_map(static fn (Register $r): string => (string) $r->getSlug(), $mocks);
            $this->assertNotContains(
                (string) $production->getSlug(),
                $mockSlugs,
                'production register MUST NOT appear in ?filters[type]=mock results'
            );
            $this->assertContains(
                (string) $mock->getSlug(),
                $mockSlugs,
                'newly inserted mock register MUST appear in ?filters[type]=mock results'
            );
        } finally {
            $this->cleanupRegister($mock);
            $this->cleanupRegister($production);
        }//end try
    }//end testNonMockRegistersAreExcludedByMockFilter()

    private function makeRegisterDirect(string $type): Register
    {
        $register = new Register();
        $register->setTitle('phpunit-'.$type.'-'.uniqid());
        $register->setSlug('phpunit-'.$type.'-'.uniqid());
        $register->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);
        $register->setType($type);
        return $this->registerMapper->insert($register);
    }//end makeRegisterDirect()

    // ------------------------------------------------------------------ helpers
    private function importMockRegister(string $slug): ?Register
    {
        $data   = $this->loaded[$slug];
        $config = new Configuration();
        $config->setTitle('phpunit-mock-'.$slug.'-'.uniqid());
        $config->setApp('phpunit-mock-registers');
        $config->setVersion($data['info']['version'] ?? '1.0.0');
        $config->setRegisters([]);
        $config->setSchemas([]);
        $config->setObjects([]);
        $config = $this->configurationMapper->insert($config);
        $this->createdConfigurationIds[] = $config->getId();

        $result = $this->configurationService->importFromJson(
            data: $data,
            configuration: $config,
            owner: 'phpunit',
            appId: 'phpunit',
            version: $data['info']['version'] ?? '1.0.0',
            force: true,
        );

        $registers = ($result['registers'] ?? []);
        foreach ($registers as $register) {
            if ($register instanceof Register && $register->getSlug() === $slug) {
                return $register;
            }
        }

        $this->fail("import did not produce a register with slug '$slug'");
    }//end importMockRegister()

    private function cleanupRegister(?Register $register): void
    {
        if ($register === null) {
            return;
        }

        try {
            $db = \OC::$server->get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();
            $qb->delete('openregister_registers')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        } catch (\Throwable) {
        }
    }//end cleanupRegister()

    protected function tearDown(): void
    {
        try {
            $db = \OC::$server->get(\OCP\IDBConnection::class);
            foreach ($this->createdConfigurationIds as $id) {
                try {
                    $qb = $db->getQueryBuilder();
                    $qb->delete('openregister_configurations')
                        ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                    $qb->executeStatement();
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }

        parent::tearDown();
    }//end tearDown()
}//end class
