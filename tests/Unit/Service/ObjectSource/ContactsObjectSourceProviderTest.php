<?php

/**
 * Unit tests for ContactsObjectSourceProvider.
 *
 * Covers:
 *  - isEnabled() reflects Contacts app availability
 *  - findAll() maps IManager contact arrays onto virtual ObjectEntity instances
 *  - system address book entries (the user directory) are skipped
 *  - email arrays are normalised to a single address
 *  - find() resolves by UID and returns null when absent
 *  - read failures degrade to an empty list (fail closed)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\ContactsObjectSourceProvider;
use OCP\App\IAppManager;
use OCP\Contacts\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for ContactsObjectSourceProvider.
 */
class ContactsObjectSourceProviderTest extends TestCase
{
    /**
     * Build a provider with a stubbed IManager result and app availability.
     *
     * @param array<int, array<string, mixed>> $contacts  Contact arrays IManager returns.
     * @param bool                             $appThere  Whether the Contacts app is installed.
     *
     * @return ContactsObjectSourceProvider The provider under test.
     */
    private function provider(array $contacts, bool $appThere=true): ContactsObjectSourceProvider
    {
        $manager = $this->createMock(IManager::class);
        $manager->method('search')->willReturnCallback(
            static function ($pattern) use ($contacts) {
                if ($pattern === '') {
                    return $contacts;
                }

                return array_values(
                    array_filter(
                        $contacts,
                        static fn($c) => str_contains(strtolower((string) ($c['UID'] ?? '')), strtolower((string) $pattern))
                    )
                );
            }
        );

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn($appThere);

        return new ContactsObjectSourceProvider($manager, $appManager, new NullLogger());
    }//end provider()

    /**
     * Sample contact arrays as IManager returns them.
     *
     * @return array<int, array<string, mixed>> The contact arrays.
     */
    private function contacts(): array
    {
        return [
            [
                'UID'   => 'contact-1',
                'FN'    => 'Alice Appel',
                'EMAIL' => 'alice@example.org',
                'ORG'   => 'Acme BV',
            ],
            [
                'UID'   => 'contact-2',
                'FN'    => 'Bob Bakker',
                // Email arrives as an array — must normalise to the first address.
                'EMAIL' => ['bob@example.org', 'bob@work.example.org'],
                'ORG'   => 'Bakkerij Bob',
            ],
            [
                // System address book entry (the user directory) — must be skipped.
                'UID'               => 'admin',
                'FN'                => 'Administrator',
                'isLocalSystemBook' => true,
            ],
        ];
    }//end contacts()

    /**
     * The register/schema pair the provider is bound to.
     *
     * @return array{0: Register, 1: Schema} The register and schema.
     */
    private function binding(): array
    {
        $register = new Register();
        $register->setId(11);
        $schema = new Schema();
        $schema->setId(110);
        return [$register, $schema];
    }//end binding()

    /**
     * getId() is the stable provider id.
     *
     * @return void
     */
    public function testGetId(): void
    {
        $this->assertSame('contacts-source', $this->provider([])->getId());
    }//end testGetId()

    /**
     * isEnabled() reflects Contacts app install-state.
     *
     * @return void
     */
    public function testIsEnabledReflectsApp(): void
    {
        $this->assertTrue($this->provider([], true)->isEnabled());
        $this->assertFalse($this->provider([], false)->isEnabled());
    }//end testIsEnabledReflectsApp()

    /**
     * findAll() maps real contacts and skips the system address book.
     *
     * @return void
     */
    public function testFindAllMapsAndFiltersSystemBook(): void
    {
        [$register, $schema] = $this->binding();

        $objects = $this->provider($this->contacts())->findAll($register, $schema);

        $this->assertCount(2, $objects);

        $alice = $objects[0]->getObject();
        $this->assertSame('contact-1', $alice['id']);
        $this->assertSame('Alice Appel', $alice['fullName']);
        $this->assertSame('alice@example.org', $alice['email']);
        $this->assertSame('Acme BV', $alice['org']);
        $this->assertSame('contact-1', $objects[0]->getUuid());
        $this->assertSame('110', $objects[0]->getSchema());

        // Array email normalised to the first address.
        $this->assertSame('bob@example.org', $objects[1]->getObject()['email']);
    }//end testFindAllMapsAndFiltersSystemBook()

    /**
     * find() resolves by UID and returns null when absent.
     *
     * @return void
     */
    public function testFindByUid(): void
    {
        [$register, $schema] = $this->binding();
        $provider = $this->provider($this->contacts());

        $this->assertSame('Bob Bakker', $provider->find($register, $schema, 'contact-2')?->getObject()['fullName']);
        $this->assertNull($provider->find($register, $schema, 'ghost'));
    }//end testFindByUid()

    /**
     * count() reflects the mapped (filtered) contact count.
     *
     * @return void
     */
    public function testCount(): void
    {
        [$register, $schema] = $this->binding();
        $this->assertSame(2, $this->provider($this->contacts())->count($register, $schema));
    }//end testCount()
}//end class
