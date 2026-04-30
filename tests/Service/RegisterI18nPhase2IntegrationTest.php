<?php

/**
 * Integration tests for register-i18n Phase 2.
 *
 * Verifies:
 *   - LanguageMiddleware emits Content-Language + X-Content-Language-Fallback
 *     headers (already shipped — defensive coverage)
 *   - TranslationProvider abstraction (interface contract via the
 *     identity stub)
 *   - BulkTranslationService fills missing slots, preserves existing,
 *     skips when no source value, marks slot as machine_translated
 *   - TranslationCsvCodec round-trips translatable properties through
 *     `field_lang` columns
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
use OCA\OpenRegister\Middleware\LanguageMiddleware;
use OCA\OpenRegister\Service\BulkTranslationService;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\TranslationCsvCodec;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class RegisterI18nPhase2IntegrationTest extends TestCase
{
    private TranslationMapper $translationMapper;
    private BulkTranslationService $bulkService;
    private TranslationCsvCodec $codec;
    private LanguageService $languageService;
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
        $this->translationMapper = \OC::$server->get(TranslationMapper::class);
        $this->bulkService       = \OC::$server->get(BulkTranslationService::class);
        $this->codec             = \OC::$server->get(TranslationCsvCodec::class);
        $this->languageService   = \OC::$server->get(LanguageService::class);
        $this->registerMapper    = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper      = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper      = \OC::$server->get(MagicMapper::class);

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

    public function testLanguageMiddlewareAddsContentLanguageHeader(): void
    {
        // Drive the middleware directly via a stub IRequest.
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->with('Accept-Language')->willReturn('nl-NL, nl;q=0.9, en;q=0.8');
        $request->method('getParam')->with('_translations')->willReturn(null);

        $svc = \OC::$server->get(LanguageService::class);
        $svc->setFallbackUsed(false); // reset
        $middleware = new LanguageMiddleware($request, $svc);
        $middleware->beforeController(null, 'index');

        // After-controller adds the Content-Language header.
        $response = new JSONResponse(['ok' => true]);
        $modified = $middleware->afterController(null, 'index', $response);
        $headers  = $modified->getHeaders();

        $this->assertArrayHasKey('Content-Language', $headers);
        $this->assertSame('nl', $headers['Content-Language'], 'preferred lang from Accept-Language MUST surface in Content-Language');
    }

    public function testLanguageMiddlewareAddsFallbackHeaderWhenFallbackUsed(): void
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturn('');
        $request->method('getParam')->willReturn(null);

        $svc        = \OC::$server->get(LanguageService::class);
        $svc->setFallbackUsed(true); // simulate a render that fell back
        $middleware = new LanguageMiddleware($request, $svc);

        $response = new JSONResponse(['ok' => true]);
        $modified = $middleware->afterController(null, 'index', $response);
        $headers  = $modified->getHeaders();

        $this->assertArrayHasKey('X-Content-Language-Fallback', $headers);
        $this->assertSame('true', $headers['X-Content-Language-Fallback']);
    }

    public function testIdentityProviderReturnsInputVerbatim(): void
    {
        $provider = new IdentityTranslationProvider();
        $this->assertSame('Hello world', $provider->translate('Hello world', 'en', 'nl'));
        $this->assertSame('identity', $provider->getIdentifier());
    }

    public function testTranslationProviderInterfaceIsResolvableViaDi(): void
    {
        $provider = \OC::$server->get(TranslationProviderInterface::class);
        $this->assertInstanceOf(TranslationProviderInterface::class, $provider);
        // Default DI binding is the identity stub.
        $this->assertSame('identity', $provider->getIdentifier());
    }

    public function testBulkTranslateFillsMissingSlotsAndMarksMachineTranslated(): void
    {
        // Source is NL; translate to EN. Identity provider returns the
        // text verbatim — for this test we don't care about translation
        // accuracy, only that the slot fills with status=machine_translated.
        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi wereld'],
            'body'  => ['nl' => 'Tekst'],
        ]);
        $this->objectMapper->update($this->testObject);

        $result = $this->bulkService->translateObject(
            object: $this->testObject,
            fromLang: 'nl',
            toLang: 'en'
        );

        $this->assertArrayHasKey('title', $result['translated']);
        $this->assertArrayHasKey('body', $result['translated']);
        $this->assertSame('Hoi wereld', $result['translated']['title'], 'identity provider returns source verbatim');

        // Sidecar should now have EN slots with machine_translated status.
        $titleEn = $this->translationMapper->findOne((string) $this->testObject->getUuid(), 'title', 'en');
        $this->assertNotNull($titleEn);
        $this->assertSame(Translation::STATUS_MACHINE_TRANSLATED, $titleEn->getStatus());
        $this->assertSame('provider:identity', $titleEn->getTranslator());
    }

    public function testBulkTranslateSkipsTargetSlotsAlreadyFilled(): void
    {
        // EN slot for `title` is already filled — bulk translate MUST skip it.
        $this->testObject->setObject([
            'title' => ['nl' => 'Hoi', 'en' => 'Existing translation, do not overwrite'],
            'body'  => ['nl' => 'Tekst'],
        ]);
        $this->objectMapper->update($this->testObject);

        $result = $this->bulkService->translateObject(
            object: $this->testObject,
            fromLang: 'nl',
            toLang: 'en'
        );

        $this->assertArrayNotHasKey('title', $result['translated']);
        $this->assertSame('target-slot-already-filled', $result['skipped']['title'] ?? null);
        // `body` had no EN value → fills.
        $this->assertArrayHasKey('body', $result['translated']);
    }

    public function testBulkTranslateSkipsWhenSourceLangMissing(): void
    {
        // Object has only EN; asking to translate from NL → can't (no source).
        $this->testObject->setObject([
            'title' => ['en' => 'Hello'],
        ]);
        $this->objectMapper->update($this->testObject);

        $result = $this->bulkService->translateObject(
            object: $this->testObject,
            fromLang: 'nl',
            toLang: 'fr'
        );

        $this->assertSame('no-source-value', $result['skipped']['title'] ?? null);
        $this->assertArrayNotHasKey('title', $result['translated']);
    }

    public function testBulkTranslateRejectsSameLanguagePair(): void
    {
        $result = $this->bulkService->translateObject(
            object: $this->testObject,
            fromLang: 'nl',
            toLang: 'nl'
        );
        $this->assertSame([], $result['translated']);
        $this->assertArrayHasKey('_global', $result['skipped']);
    }

    public function testCsvCodecFlattensTranslatablePropertiesIntoLangSuffixedColumns(): void
    {
        $row = $this->codec->flattenForCsv(
            [
                'title' => ['nl' => 'Hoi', 'en' => 'Hi'],
                'body'  => ['nl' => 'Tekst'],
                'plain' => 'untranslated',
            ],
            $this->testSchema
        );

        $this->assertSame('Hoi', $row['title_nl']);
        $this->assertSame('Hi',  $row['title_en']);
        $this->assertSame('Tekst', $row['body_nl']);
        $this->assertSame('untranslated', $row['plain'],
            'untranslatable properties pass through under their original key');
        $this->assertArrayNotHasKey('title', $row, 'translatable property MUST NOT also appear under the bare key');
    }

    public function testCsvCodecUnflattensLangSuffixedColumnsBackIntoNestedShape(): void
    {
        $data = $this->codec->unflattenFromCsv(
            [
                'title_nl' => 'Hoi',
                'title_en' => 'Hi',
                'body_nl'  => 'Tekst',
                'plain'    => 'unchanged',
            ],
            $this->testSchema
        );

        $this->assertSame(['nl' => 'Hoi', 'en' => 'Hi'], $data['title']);
        $this->assertSame(['nl' => 'Tekst'], $data['body']);
        $this->assertSame('unchanged', $data['plain']);
    }

    public function testCsvCodecRoundTripsLossless(): void
    {
        $original = [
            'title' => ['nl' => 'Hoi', 'en' => 'Hi'],
            'body'  => ['nl' => 'Tekst'],
            'plain' => 'unchanged',
        ];

        $row     = $this->codec->flattenForCsv($original, $this->testSchema);
        $reverse = $this->codec->unflattenFromCsv($row, $this->testSchema);

        $this->assertSame($original, $reverse, 'flatten → unflatten MUST be lossless');
    }

    public function testCsvCodecHandlesEmptyTranslationCell(): void
    {
        // Empty cells produced by Excel for missing translations MUST
        // NOT be written back as empty-string slots — they should be omitted.
        $data = $this->codec->unflattenFromCsv(
            [
                'title_nl' => 'Hoi',
                'title_en' => '', // empty cell
            ],
            $this->testSchema
        );

        $this->assertSame(['nl' => 'Hoi'], $data['title'] ?? null,
            'empty cells MUST NOT result in empty-string translation slots');
    }

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-i18n2-' . uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-i18n2-' . uniqid());
        $register->setLanguages(['nl', 'en', 'fr']);
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-i18n2-schema-' . uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-i18n2-schema-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'translatable' => true, 'title' => 'Title'],
            'body'  => ['type' => 'string', 'translatable' => true, 'title' => 'Body'],
            'plain' => ['type' => 'string', 'title' => 'Plain'],
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
