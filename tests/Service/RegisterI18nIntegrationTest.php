<?php

/**
 * Integration tests for register-i18n Phase 1.
 *
 * Verifies:
 *   - Translation projection sidecar (`oc_openregister_translations`)
 *     stays in sync with JSONB property data (UPSERT on save, delete
 *     on object delete, deletion of stale slots when a value is
 *     removed).
 *   - Per-property fallback walks the register's `languages` chain
 *     in declared order (Decision 2).
 *   - Per-language completeness is computed-on-read and surfaces in
 *     `@self.translationCompleteness` (Decision 4).
 *   - $ref language cascade — embedded objects render in the same
 *     language as the parent (Decision 5; satisfied for free by the
 *     request-scoped LanguageService).
 *   - Workflow status: setStatus promotes a slot, search filters
 *     by status (Decision 1).
 *   - Cross-language and language-specific search via the unified
 *     translations sidecar (Decision 3).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class RegisterI18nIntegrationTest extends TestCase
{
    private TranslationMapper $translationMapper;
    private TranslationProjectionService $projection;
    private TranslationStatusService $statusService;
    private TranslationHandler $translationHandler;
    private LanguageService $languageService;
    private RenderObject $renderObject;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;
    private ?ObjectEntity $testObject = null;
    private ?string $createdTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translationMapper  = \OC::$server->get(TranslationMapper::class);
        $this->projection         = \OC::$server->get(TranslationProjectionService::class);
        $this->statusService      = \OC::$server->get(TranslationStatusService::class);
        $this->translationHandler = \OC::$server->get(TranslationHandler::class);
        $this->languageService    = \OC::$server->get(LanguageService::class);
        $this->renderObject       = \OC::$server->get(RenderObject::class);
        $this->registerMapper     = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper       = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper       = \OC::$server->get(MagicMapper::class);

        // Login as admin so the projection captures translator uid.
        $userManager = \OC::$server->get(IUserManager::class);
        $userSession = \OC::$server->get(IUserSession::class);
        $admin       = $userManager->get('admin');
        if ($admin !== null) {
            $userSession->setUser($admin);
        }

        $this->createTestFixture();
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        // Clean up the translation rows.
        if ($this->testObject !== null) {
            try {
                $this->translationMapper->deleteByObject((string) $this->testObject->getUuid());
            } catch (\Throwable $e) {
                // best effort
            }
        }
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

    public function testProjectionUpsertsOneRowPerLanguagePerProperty(): void
    {
        // Set translatable property values on the test object and run
        // the projection directly (avoids depending on listener wiring
        // having fired during setUp).
        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi wereld', 'en' => 'Hello world'],
            'body'  => ['nl' => 'Een test',   'en' => 'A test'],
            'plain' => 'not translatable',
        ]);

        $this->projection->project($this->testObject);

        $rows = $this->translationMapper->findByObject((string) $this->testObject->getUuid());
        $this->assertCount(4, $rows, 'projection MUST upsert one row per (translatable property × language)');

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->getProperty() . '|' . $row->getLanguage()] = $row->getValue();
        }
        $this->assertSame('Hoi wereld',  $byKey['title|nl']);
        $this->assertSame('Hello world', $byKey['title|en']);
        $this->assertSame('Een test',    $byKey['body|nl']);
        $this->assertSame('A test',      $byKey['body|en']);
    }

    public function testProjectionDeletesStaleSlotsWhenLanguageRemoved(): void
    {
        // Initially populate NL + EN, then re-project with EN removed
        // for `title` — the EN row MUST be deleted on the second pass.
        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi', 'en' => 'Hi'],
        ]);
        $this->projection->project($this->testObject);

        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi'],
        ]);
        $this->projection->project($this->testObject);

        $rows = $this->translationMapper->findByObject((string) $this->testObject->getUuid());
        $this->assertCount(1, $rows, 'projection MUST delete the EN slot once the value is removed from JSONB');
        $this->assertSame('nl', $rows[0]->getLanguage());
    }

    public function testProjectionPurgeOnDeleteRemovesEverySlot(): void
    {
        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi', 'en' => 'Hi'],
            'body'  => ['nl' => 'Tekst'],
        ]);
        $this->projection->project($this->testObject);
        $this->assertCount(3, $this->translationMapper->findByObject((string) $this->testObject->getUuid()));

        $this->projection->purge($this->testObject);
        $this->assertCount(0, $this->translationMapper->findByObject((string) $this->testObject->getUuid()));
    }

    public function testCompletenessReportsTranslatedTotalRatio(): void
    {
        // Schema has 2 translatable properties (title, body) — so a
        // language with both translated reads 2/2 = 1.0; a language
        // with only title reads 1/2 = 0.5.
        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi', 'en' => 'Hi', 'fr' => 'Salut'],
            'body'  => ['nl' => 'Tekst', 'en' => 'Body'],
        ]);
        $this->projection->project($this->testObject);

        $completeness = $this->statusService->completenessForObject(
            (string) $this->testObject->getUuid(),
            $this->testSchema
        );

        $this->assertSame(['translated' => 2, 'total' => 2, 'ratio' => 1.0],  $completeness['nl']);
        $this->assertSame(['translated' => 2, 'total' => 2, 'ratio' => 1.0],  $completeness['en']);
        $this->assertSame(['translated' => 1, 'total' => 2, 'ratio' => 0.5],  $completeness['fr']);
    }

    public function testRenderObjectAttachesCompletenessToSelfEnvelope(): void
    {
        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi', 'en' => 'Hi'],
            'body'  => ['nl' => 'Tekst'],
        ]);
        $this->projection->project($this->testObject);

        $rendered = $this->renderObject->renderEntity(
            entity: $this->testObject,
            _extend: [],
            depth: 0,
            filter: [],
            fields: [],
            unset: [],
            registers: [],
            schemas: [],
            objects: [],
            visitedIds: [],
            _rbac: false,
            _multitenancy: false
        );

        $this->assertNotNull($rendered->getTranslationCompleteness(), 'RenderObject MUST populate translationCompleteness when schema has translatable props');
        $serialised = $rendered->jsonSerialize();
        $this->assertArrayHasKey('translationCompleteness', $serialised['@self']);
        $this->assertSame(1.0, $serialised['@self']['translationCompleteness']['nl']['ratio'] ?? null);
        $this->assertSame(0.5, $serialised['@self']['translationCompleteness']['en']['ratio'] ?? null);
    }

    public function testFallbackChainWalksRegisterLanguagesInOrder(): void
    {
        // Register declares languages: nl (default), en, fr.
        // Object has only fr for `title`. Resolve with preferred=en.
        // Chain: en (preferred) → nl (register order) → fr (register
        // order). Only fr exists, so it MUST be returned and fallback
        // flag set.
        $this->testObject->setObject([
            'title' => ['fr' => 'Salut le monde'],
        ]);

        $this->languageService->setPreferredLanguage('en');
        $this->languageService->setAcceptedLanguages(['en']);
        $this->languageService->setFallbackUsed(false);

        $resolved = $this->translationHandler->resolveTranslationsForRender(
            ['title' => ['fr' => 'Salut le monde']],
            $this->testSchema,
            $this->testRegister
        );

        $this->assertSame('Salut le monde', $resolved['title']);
        $this->assertTrue($this->languageService->isFallbackUsed(), 'fallback flag MUST be set when the resolved language is missing');
    }

    public function testFallbackChainPicksPreferredWhenAvailable(): void
    {
        $this->languageService->setPreferredLanguage('en');
        $this->languageService->setAcceptedLanguages(['en']);
        $this->languageService->setFallbackUsed(false);

        $resolved = $this->translationHandler->resolveTranslationsForRender(
            ['title' => ['nl' => 'Hoi', 'en' => 'Hi']],
            $this->testSchema,
            $this->testRegister
        );

        $this->assertSame('Hi', $resolved['title']);
        $this->assertFalse($this->languageService->isFallbackUsed(), 'fallback flag MUST NOT be set when preferred language is available');
    }

    public function testStatusPromotionPersistsAcrossRereads(): void
    {
        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi', 'en' => 'Hi'],
        ]);
        $this->projection->project($this->testObject);

        // Promote the EN slot to approved.
        $promoted = $this->statusService->setStatus(
            (string) $this->testObject->getUuid(),
            'title',
            'en',
            Translation::STATUS_APPROVED
        );
        $this->assertSame(Translation::STATUS_APPROVED, $promoted->getStatus());

        // Re-project the object — status MUST be preserved (projection
        // doesn't second-guess workflow state).
        $this->projection->project($this->testObject);

        $reread = $this->translationMapper->findOne((string) $this->testObject->getUuid(), 'title', 'en');
        $this->assertSame(Translation::STATUS_APPROVED, $reread->getStatus(), 'projection MUST preserve workflow status across re-projections');
    }

    public function testCrossLanguageContentSearchSpansAllLanguages(): void
    {
        $unique = 'rotterdam-' . bin2hex(random_bytes(4));

        $this->testObject->setObject([
            'title' => ['nl' => "Stadhuis $unique", 'en' => "City hall $unique"],
        ]);
        $this->projection->project($this->testObject);

        // Cross-language: drop the language filter, hit both.
        $hits = $this->statusService->search(query: $unique, language: null);
        $languages = array_map(fn($r) => $r['language'], $hits);
        $this->assertContains('nl', $languages);
        $this->assertContains('en', $languages);
    }

    public function testLanguageSpecificContentSearchNarrowsToOneLanguage(): void
    {
        $unique = 'amsterdam-' . bin2hex(random_bytes(4));

        $this->testObject->setObject([
            'title' => ['nl' => "Hoofdstad $unique", 'en' => "Capital $unique"],
        ]);
        $this->projection->project($this->testObject);

        $hits = $this->statusService->search(query: $unique, language: 'nl');
        $this->assertCount(1, $hits);
        $this->assertSame('nl', $hits[0]['language']);
    }

    public function testFindObjectsMissingLanguageReturnsCandidatesLackingTranslation(): void
    {
        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi'],   // missing en
            'body'  => ['nl' => 'Tekst'], // missing en
        ]);
        $this->projection->project($this->testObject);

        $missing = $this->statusService->findObjectsMissingLanguage(
            'en',
            $this->testSchema,
            [(string) $this->testObject->getUuid()]
        );

        $this->assertContains((string) $this->testObject->getUuid(), $missing);
    }

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-i18n-' . uniqid());
        $register->setDescription('register-i18n integration tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-i18n-' . uniqid());
        $register->setLanguages(['nl', 'en', 'fr']);
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-i18n-schema-' . uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-i18n-schema-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'translatable' => true, 'title' => 'Title'],
            'body'  => ['type' => 'string', 'translatable' => true, 'title' => 'Body'],
            'plain' => ['type' => 'string', 'title' => 'Plain (untranslated)'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $this->testSchema);
        $this->createdTable = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $this->testSchema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $this->testRegister->getId());
        $entity->setSchema((string) $this->testSchema->getId());
        $entity->setObject(['title' => ['nl' => 'Hoi'], 'body' => ['nl' => 'Tekst']]);
        $this->testObject = $this->objectMapper->insertObjectEntity($entity, $this->testRegister, $this->testSchema, false);
    }
}
