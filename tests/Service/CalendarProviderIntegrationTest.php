<?php

/**
 * Integration tests for RegisterCalendarProvider + RegisterCalendar.
 *
 * Verifies the calendar provider end-to-end without browser involvement:
 * a schema with `calendarProvider.enabled = true` produces an
 * `ICalendar` for an authenticated principal, and that calendar's
 * `search()` returns events derived from the schema's objects, with
 * timerange filtering applied to the dtstart property.
 *
 * Replaces the browser-driven manual smoke from the spec — the same
 * data path is exercised here against a real schema + Postgres.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Calendar\RegisterCalendar;
use OCA\OpenRegister\Calendar\RegisterCalendarProvider;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class CalendarProviderIntegrationTest extends TestCase
{
    private RegisterCalendarProvider $provider;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;
    /** @var string[] */
    private array $createdObjectUuids = [];
    private ?string $createdTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider       = \OC::$server->get(RegisterCalendarProvider::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);

        // Login as admin so RegisterMapper::findAll() honours the same
        // session the provider expects. CLI / no-session would otherwise
        // get an empty register list because of multi-tenancy filtering.
        $userManager = \OC::$server->get(\OCP\IUserManager::class);
        $userSession = \OC::$server->get(\OCP\IUserSession::class);
        $admin       = $userManager->get('admin');
        if ($admin !== null) {
            $userSession->setUser($admin);
        }

        $this->createTestFixture();

        // Provider is a singleton in DI and caches the enabled-schemas
        // list per request. Tests create + destroy schemas per method, so
        // we have to reset that cache to see our fresh fixture.
        $this->resetProviderCache();
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        if ($this->testSchema !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testSchema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->createdTable !== null) {
            try {
                $db->prepare("DROP TABLE IF EXISTS \"{$this->createdTable}\"")->execute();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        parent::tearDown();
    }

    public function testProviderReturnsCalendarsForAuthenticatedPrincipal(): void
    {
        $calendars = $this->provider->getCalendars('principals/users/admin');

        // Filter to our test schema's calendar to avoid coupling the test
        // to whatever else is enabled in the running NC environment.
        $ours = array_filter(
            $calendars,
            fn($c) => $c instanceof RegisterCalendar
                && str_contains((string) $c->getUri(), (string) $this->testSchema->getId())
        );

        $this->assertCount(
            1,
            $ours,
            'authenticated principal MUST see the test schema calendar (calendarProvider.enabled=true)'
        );
    }

    public function testProviderReturnsNoCalendarsForAnonymousPrincipal(): void
    {
        $calendars = $this->provider->getCalendars('principals/system/anonymous');
        // The provider returns [] for non-user principals — nothing
        // should be added by our test schema either way.
        $ours = array_filter(
            $calendars,
            fn($c) => $c instanceof RegisterCalendar
                && str_contains((string) $c->getUri(), (string) $this->testSchema->getId())
        );

        $this->assertSame([], $ours, 'anonymous principals MUST receive no calendar from this provider');
    }

    public function testCalendarSearchReturnsEventsFromObjects(): void
    {
        $this->seedEvent(['title' => 'Past meeting',     'startsAt' => '2024-01-01T10:00:00+00:00']);
        $this->seedEvent(['title' => 'Upcoming meeting', 'startsAt' => '2099-06-01T10:00:00+00:00']);

        $calendars = $this->provider->getCalendars('principals/users/admin');
        $calendar  = $this->findOurCalendar($calendars);

        $events = $calendar->search('');

        // There may be other events in the same schema from other tests
        // running in the same DB; what we care about is that *our* two
        // titles round-tripped into VEVENT-compatible structures.
        $titles = array_map(fn($e) => $this->extractSummary($e), $events);
        $this->assertContains('Past meeting',     $titles, 'search() MUST surface seeded past event');
        $this->assertContains('Upcoming meeting', $titles, 'search() MUST surface seeded future event');
    }

    public function testCalendarSearchAppliesTimerangeFilter(): void
    {
        $this->seedEvent(['title' => 'Past meeting',     'startsAt' => '2024-01-01T10:00:00+00:00']);
        $this->seedEvent(['title' => 'Upcoming meeting', 'startsAt' => '2099-06-01T10:00:00+00:00']);

        $calendars = $this->provider->getCalendars('principals/users/admin');
        $calendar  = $this->findOurCalendar($calendars);

        // Window: future-only (2099 onwards) — past event MUST NOT appear.
        $futureOnly = $calendar->search('', [], [
            'timerange' => [
                'start' => new \DateTimeImmutable('2099-01-01T00:00:00+00:00'),
                'end'   => new \DateTimeImmutable('2099-12-31T23:59:59+00:00'),
            ],
        ]);

        $titles = array_map(fn($e) => $this->extractSummary($e), $futureOnly);
        $this->assertContains('Upcoming meeting', $titles, 'future-window search MUST include the upcoming event');
        $this->assertNotContains('Past meeting',  $titles, 'future-window search MUST exclude past events');
    }

    private function resetProviderCache(): void
    {
        try {
            $ref = new \ReflectionClass($this->provider);
            if ($ref->hasProperty('enabledSchemasCache')) {
                $prop = $ref->getProperty('enabledSchemasCache');
                $prop->setAccessible(true);
                $prop->setValue($this->provider, null);
            }
        } catch (\Throwable $e) {
            // best effort
        }
    }

    private function findOurCalendar(array $calendars): RegisterCalendar
    {
        foreach ($calendars as $cal) {
            if ($cal instanceof RegisterCalendar
                && str_contains((string) $cal->getUri(), (string) $this->testSchema->getId())
            ) {
                return $cal;
            }
        }
        $this->fail('test schema calendar not present in provider output');
    }

    /**
     * Pull the SUMMARY out of a calendar event result. The provider returns
     * `objects` as a list of VEVENT property maps where each property is
     * `[value, params]`. Concretely: `$event['objects'][0]['SUMMARY'][0]`.
     */
    private function extractSummary(array $event): ?string
    {
        if (isset($event['summary']) === true) {
            return (string) $event['summary'];
        }
        $vevent = $event['objects'][0] ?? null;
        if (is_array($vevent) === true && isset($vevent['SUMMARY']) === true) {
            $summary = $vevent['SUMMARY'];
            if (is_array($summary) === true && isset($summary[0]) === true) {
                return (string) $summary[0];
            }
            if (is_string($summary) === true) {
                return $summary;
            }
        }
        return null;
    }

    private function seedEvent(array $data): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $this->testRegister->getId());
        $entity->setSchema((string) $this->testSchema->getId());
        $entity->setObject($data);

        $entity = $this->objectMapper->insertObjectEntity($entity, $this->testRegister, $this->testSchema, false);
        $this->createdObjectUuids[] = $entity->getUuid();
        return $entity;
    }

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-cal-' . uniqid());
        $register->setDescription('Calendar provider integration tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-cal-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-cal-schema-' . uniqid());
        $schema->setDescription('Schema with calendarProvider enabled for integration tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-cal-schema-' . uniqid());
        $schema->setProperties([
            'title'    => ['type' => 'string', 'title' => 'Title'],
            'startsAt' => ['type' => 'string', 'title' => 'Starts At', 'format' => 'date-time'],
        ]);
        $schema->setConfiguration([
            'calendarProvider' => [
                'enabled'       => true,
                'dtstart'       => 'startsAt',
                'titleTemplate' => '{{title}}',
            ],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $this->testSchema);
        $this->createdTable = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $this->testSchema);
    }
}
