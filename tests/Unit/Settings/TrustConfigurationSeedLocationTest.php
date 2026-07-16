<?php

/**
 * Trust-configuration register seed-location test.
 *
 * Guards the MDM incident: the 6 trust rules were declared under
 * `components.schemas.trustConfiguration.x-openregister-seed.objects` — a
 * schema annotation NO engine reads — so they were never planted and the
 * trust tiers were silently never applied. ImportHandler seeds from
 * `components.objects` / top-level `objects` only.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/authorization-rbac/spec.md#requirement-declared-seed-data-is-planted
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class TrustConfigurationSeedLocationTest extends TestCase
{

    /**
     * The decoded trust-configuration register descriptor.
     *
     * @var array<string, mixed>
     */
    private array $register;


    /**
     * Load the shipped register descriptor.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $path = __DIR__.'/../../../lib/Settings/trust_configuration_register.json';
        $this->assertFileExists($path);

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertIsArray($decoded, 'trust_configuration_register.json must be valid JSON');
        $this->register = $decoded;
    }//end setUp()


    /**
     * The 6 trust rules must live at the engine-backed seed location.
     *
     * @return void
     */
    public function testTrustRulesAreDeclaredWhereTheImporterReadsThem(): void
    {
        $objects = ($this->register['components']['objects'] ?? null);

        $this->assertIsArray(
            $objects,
            'Seed objects MUST live at components.objects — the location ImportHandler reads.'
        );
        $this->assertCount(6, $objects, 'All 6 MDM trust rules must be present');
    }//end testTrustRulesAreDeclaredWhereTheImporterReadsThem()


    /**
     * Every seed object needs the @self identity the importer resolves by; an
     * object without a slug is skipped outright.
     *
     * @return void
     */
    public function testEverySeedObjectCarriesResolvableSelfIdentity(): void
    {
        foreach (($this->register['components']['objects'] ?? []) as $index => $object) {
            $self = ($object['@self'] ?? []);

            $this->assertNotEmpty($self['register'] ?? null, "Seed #{$index} needs @self.register");
            $this->assertNotEmpty($self['schema'] ?? null, "Seed #{$index} needs @self.schema");
            $this->assertNotEmpty(
                $self['slug'] ?? null,
                "Seed #{$index} needs @self.slug — ImportHandler skips slug-less objects"
            );
        }
    }//end testEverySeedObjectCarriesResolvableSelfIdentity()


    /**
     * The @self refs must resolve against the register's own declarations —
     * a ref that names a non-existent register/schema makes the importer skip
     * the object with a warning (the seed silently never plants).
     *
     * @return void
     */
    public function testSeedSelfRefsResolveAgainstDeclaredRegisterAndSchema(): void
    {
        $registers = array_keys(($this->register['components']['registers'] ?? []));
        $schemas   = array_keys(($this->register['components']['schemas'] ?? []));

        foreach (($this->register['components']['objects'] ?? []) as $object) {
            $this->assertContains($object['@self']['register'], $registers);
            $this->assertContains($object['@self']['schema'], $schemas);
        }
    }//end testSeedSelfRefsResolveAgainstDeclaredRegisterAndSchema()


    /**
     * The phantom annotation must not come back.
     *
     * @return void
     */
    public function testNoSchemaStillDeclaresThePhantomSeedAnnotation(): void
    {
        foreach (($this->register['components']['schemas'] ?? []) as $key => $schema) {
            $this->assertArrayNotHasKey(
                'x-openregister-seed',
                $schema,
                "Schema '{$key}' declares the engine-less x-openregister-seed annotation"
            );
        }
    }//end testNoSchemaStillDeclaresThePhantomSeedAnnotation()


}//end class
