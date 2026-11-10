<?php

/**
 * Behaviour tests for ContactMatchingService: guards, cache, fail-safe searches and result shaping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\ContactMatchingMappers;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ContactMatchingService
 * @covers \OCA\OpenRegister\Service\ContactMatchQuery
 * @uses   \OCA\OpenRegister\Service\ContactMatchingMappers
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ContactMatchingServiceBehaviourTest extends TestCase
{
    /** @var ObjectOperationsInterface&MockObject */
    private ObjectOperationsInterface $objects;

    /** @var SchemaMapperInterface&MockObject */
    private SchemaMapperInterface $schemas;

    /** @var RegisterMapperInterface&MockObject */
    private RegisterMapperInterface $registers;

    /** @var ICacheFactory&MockObject */
    private ICacheFactory $cacheFactory;

    /** @var ICache&MockObject */
    private ICache $cache;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->objects      = $this->createMock(ObjectOperationsInterface::class);
        $this->schemas      = $this->createMock(SchemaMapperInterface::class);
        $this->registers    = $this->createMock(RegisterMapperInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cache        = $this->createMock(ICache::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
    }//end setUp()

    private function service(bool $withCache = true): ContactMatchingService
    {
        if ($withCache === true) {
            $this->cacheFactory->method('createDistributed')->with('openregister_contacts')->willReturn($this->cache);
        }

        return new ContactMatchingService(
            $this->objects,
            new ContactMatchingMappers($this->schemas, $this->registers),
            $this->cacheFactory,
            $this->logger
        );
    }//end service()

    private function linked(bool $linked = true): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getLinkedTypes')->willReturn($linked ? ['contact'] : ['document']);
        $this->schemas->method('findAll')->willReturn([$schema]);
    }//end linked()

    private function searchReturns(array|int $results): void
    {
        $this->objects->method('searchObjects')->willReturn($results);
    }//end searchReturns()

    public function testAFailingCacheFactoryIsLoggedAndMatchingStillWorksWithoutCache(): void
    {
        $this->cacheFactory->method('createDistributed')->willThrowException(new Exception('no redis'));
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('[ContactMatching] Failed to create cache: {error}', ['error' => 'no redis']);
        $this->linked();
        $this->searchReturns([['email' => 'a@b.nl', '@self' => ['uuid' => 'u-1']]]);
        $service = $this->service(withCache: false);

        $matches = $service->matchByEmail('A@B.nl');

        $this->assertSame('u-1', $matches[0]['uuid']);
        $this->assertFalse($matches[0]['cached']);
        $service->invalidateCache('a@b.nl');
        $service->invalidateCacheForObject(['email' => 'a@b.nl']);
    }//end testAFailingCacheFactoryIsLoggedAndMatchingStillWorksWithoutCache()

    public function testNameAndOrganizationSkipEmptyInputAndUnlinkedInstances(): void
    {
        $this->linked(false);
        $this->objects->expects($this->never())->method('searchObjects');
        $service = $this->service();

        $this->assertSame([], $service->matchByEmail(''));
        $this->assertSame([], $service->matchByName(null));
        $this->assertSame([], $service->matchByName(''));
        $this->assertSame([], $service->matchByName('Jan de Vries'));
        $this->assertSame([], $service->matchByOrganization(null));
        $this->assertSame([], $service->matchByOrganization('Acme'));
    }//end testNameAndOrganizationSkipEmptyInputAndUnlinkedInstances()

    public function testASchemaLookupFailureFailsClosedAndIsAskedOnlyOnce(): void
    {
        $this->schemas->expects($this->once())->method('findAll')->willThrowException(new RuntimeException('db'));
        $this->objects->expects($this->never())->method('searchObjects');
        $service = $this->service();

        $this->assertSame([], $service->matchByEmail('a@b.nl'));
        $this->assertSame([], $service->matchByName('Jan Jansen'));
    }//end testASchemaLookupFailureFailsClosedAndIsAskedOnlyOnce()

    public function testCachedNameAndOrganizationMatchesAreServedAndMarked(): void
    {
        $this->linked();
        $this->objects->expects($this->never())->method('searchObjects');
        $this->cache->method('get')->willReturnCallback(
            static fn(string $key): string => (string) json_encode([['uuid' => substr($key, 0, (int) strrpos($key, '_')), 'cached' => false]])
        );
        $service = $this->service();

        $name = $service->matchByName(' Jan  ');
        $org  = $service->matchByOrganization('Acme');

        $this->assertSame([['uuid' => 'or_contact_match_name', 'cached' => true]], $name);
        $this->assertSame([['uuid' => 'or_contact_match_org', 'cached' => true]], $org);
    }//end testCachedNameAndOrganizationMatchesAreServedAndMarked()

    public function testACorruptCacheEntryIsIgnoredAndReplacedBySearch(): void
    {
        $this->linked();
        $this->cache->method('get')->willReturn('{not json');
        $this->searchReturns([['email' => 'a@b.nl', '@self' => ['uuid' => 'u-1']]]);
        $this->cache->expects($this->once())
            ->method('set')
            ->with('or_contact_match_email_'.hash('sha256', 'a@b.nl'), $this->stringContains('"uuid":"u-1"'), 60);

        $this->assertSame('u-1', $this->service()->matchByEmail('a@b.nl')[0]['uuid']);
    }//end testACorruptCacheEntryIsIgnoredAndReplacedBySearch()

    public function testAFailingSearchIsLoggedAndYieldsNoMatches(): void
    {
        $this->linked();
        $this->objects->method('searchObjects')->willThrowException(new Exception('solr down'));
        $this->logger->expects($this->exactly(2))
            ->method('warning')
            ->with(
                $this->logicalOr('[ContactMatching] Search failed: {error}', '[ContactMatching] Name search failed: {error}'),
                ['error' => 'solr down']
            );
        $service = $this->service();

        $this->assertSame([], $service->matchByEmail('a@b.nl'));
        $this->assertSame([], $service->matchByName('Jan Jansen'));
    }//end testAFailingSearchIsLoggedAndYieldsNoMatches()

    public function testACountInsteadOfResultsYieldsNoMatches(): void
    {
        $this->linked();
        $this->searchReturns(5);

        $this->assertSame([], $this->service()->matchByEmail('a@b.nl'));
    }//end testACountInsteadOfResultsYieldsNoMatches()

    public function testNameMatchingScoresFullAndPartialMatchesAndSkipsTheRest(): void
    {
        $this->linked();
        $this->searchReturns(
            [
                'not-an-array',
                ['voornaam' => 'Jan', 'achternaam' => 'Jansen', 'age' => 4, '@self' => ['uuid' => 'full']],
                ['naam' => 'Jan Pietersen', '@self' => ['uuid' => 'partial']],
                ['naam' => 'Kees', 'city' => 'Jansen', '@self' => ['uuid' => 'none']],
            ]
        );

        $matches = $this->service()->matchByName('Jan Jansen');

        $this->assertSame(
            [['full', 0.7, 'name'], ['partial', 0.4, 'name']],
            array_map(static fn(array $m): array => [$m['uuid'], $m['confidence'], $m['matchType']], $matches)
        );
    }//end testNameMatchingScoresFullAndPartialMatchesAndSkipsTheRest()

    public function testOrganizationMatchingNeedsAnOrganisationSchemaAndAPartialValue(): void
    {
        $this->linked();
        $this->searchReturns(
            [
                ['bedrijfsnaam' => 'Acme BV', 'schema' => ['title' => 'Organisaties'], '@self' => ['uuid' => 'org']],
                ['bedrijfsnaam' => 'Acme BV', 'schema' => ['title' => 'Personen'], '@self' => ['uuid' => 'person']],
                ['naam' => 'Other', 'schema' => ['name' => 'company'], '@self' => ['uuid' => 'other-value']],
                ['city' => 'Acme', 'schema' => ['title' => 'Organisatie'], '@self' => ['uuid' => 'not-an-org-field']],
            ]
        );

        $matches = $this->service()->matchByOrganization('acme');

        $this->assertSame(['org'], array_column($matches, 'uuid'));
        $this->assertSame(0.5, $matches[0]['confidence']);
    }//end testOrganizationMatchingNeedsAnOrganisationSchemaAndAPartialValue()

    public function testMatchContactKeepsTheStrongestPerUuidOrderedByConfidence(): void
    {
        $this->linked();
        $this->cache->method('get')->willReturnCallback(
            static function (string $key): ?string {
                if (str_starts_with($key, 'or_contact_match_email_') === true) {
                    return (string) json_encode([['uuid' => 'a', 'confidence' => 0.95], ['uuid' => '', 'confidence' => 0.95]]);
                }

                if (str_starts_with($key, 'or_contact_match_name_') === true) {
                    return (string) json_encode([['uuid' => 'a', 'confidence' => 0.7], ['uuid' => 'b', 'confidence' => 0.4], ['confidence' => 0.9]]);
                }

                return (string) json_encode([['uuid' => 'b', 'confidence' => 0.5], ['uuid' => 'c', 'confidence' => 0.5]]);
            }
        );

        $matches = $this->service()->matchContact('a@b.nl', 'Jan', 'Acme');

        $this->assertSame(
            [['a', 0.95], ['b', 0.5], ['c', 0.5]],
            array_map(static fn(array $m): array => [$m['uuid'], $m['confidence']], $matches)
        );
    }//end testMatchContactKeepsTheStrongestPerUuidOrderedByConfidence()

    public function testMatchContactWithoutNameOrOrganizationOnlyMatchesEmail(): void
    {
        $this->linked();
        $this->cache->method('get')->willReturn((string) json_encode([['uuid' => 'a', 'confidence' => 1.0]]));
        $this->cache->expects($this->once())->method('get');

        $this->assertSame(['a'], array_column($this->service()->matchContact('a@b.nl', '', null), 'uuid'));
    }//end testMatchContactWithoutNameOrOrganizationOnlyMatchesEmail()

    public function testAMatchCarriesSchemaRegisterTitleAndOnlyScalarPublicProperties(): void
    {
        $this->linked();
        $schema = new Schema();
        $schema->setTitle('Personen');
        $register = new Register();
        $register->setTitle('Burgers');
        $this->schemas->method('find')->with(3)->willReturn($schema);
        $this->registers->method('find')->with(9)->willReturn($register);
        $this->searchReturns(
            [[
                'email' => 'a@b.nl', 'naam' => 'Jan', 'age' => 40, 'tags' => ['x'], '_score' => 1.0,
                '@self' => ['uuid' => 'u-1', 'schema' => '3', 'register' => '9'],
            ]]
        );

        $match = $this->service()->matchByEmail('a@b.nl')[0];

        $this->assertSame(
            [
                'uuid' => 'u-1',
                'register' => ['id' => 9, 'title' => 'Burgers'],
                'schema' => ['id' => 3, 'title' => 'Personen'],
                'title' => 'Jan',
                'matchType' => 'email',
                'confidence' => 1.0,
                'properties' => ['email' => 'a@b.nl', 'naam' => 'Jan', 'age' => 40],
                'cached' => false,
            ],
            $match
        );
    }//end testAMatchCarriesSchemaRegisterTitleAndOnlyScalarPublicProperties()

    public function testUnreadableSchemaAndRegisterAreNamedUnknownAndMissingOnesAreEmpty(): void
    {
        $this->linked();
        $this->schemas->method('find')->willThrowException(new Exception('gone'));
        $this->registers->method('find')->willThrowException(new Exception('gone'));
        $this->searchReturns(
            [
                ['email' => 'a@b.nl', 'uuid' => 'flat', '@self' => ['schema' => 3, 'register' => 9]],
                ['email' => 'a@b.nl'],
            ]
        );

        $matches = $this->service()->matchByEmail('a@b.nl');

        $this->assertSame(['id' => 3, 'title' => 'Unknown'], $matches[0]['schema']);
        $this->assertSame(['id' => 9, 'title' => 'Unknown'], $matches[0]['register']);
        $this->assertSame('flat', $matches[0]['uuid']);
        $this->assertSame([[], [], '', 'Unknown'], [$matches[1]['schema'], $matches[1]['register'], $matches[1]['uuid'], $matches[1]['title']]);
    }//end testUnreadableSchemaAndRegisterAreNamedUnknownAndMissingOnesAreEmpty()

    public function testInvalidateCacheForObjectOnlyDropsValidEmailProperties(): void
    {
        $removed = [];
        $this->cache->method('remove')->willReturnCallback(
            static function (string $key) use (&$removed): bool {
                $removed[] = $key;
                return true;
            }
        );
        $service = $this->service();

        $service->invalidateCacheForObject(
            ['email' => 'Jan@B.nl', 'emailadres' => 'not-an-email', 'name' => 'x@y.nl', 'mail' => '', 'e-mail' => ['a@b.nl'], 0 => 'z@z.nl']
        );
        $service->invalidateCache('');

        $this->assertSame(['or_contact_match_email_'.hash('sha256', 'jan@b.nl')], $removed);
    }//end testInvalidateCacheForObjectOnlyDropsValidEmailProperties()

    public function testRelatedObjectCountsGroupBySchemaTitle(): void
    {
        $this->assertSame(
            ['Personen' => 2, 'Unknown' => 1],
            $this->service()->getRelatedObjectCounts(
                [['schema' => ['title' => 'Personen']], ['schema' => []], ['schema' => ['title' => 'Personen']]]
            )
        );
        $this->assertSame([], $this->service()->getRelatedObjectCounts([]));
    }//end testRelatedObjectCountsGroupBySchemaTitle()
}//end class
