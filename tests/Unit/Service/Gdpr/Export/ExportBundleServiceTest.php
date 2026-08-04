<?php

declare(strict_types=1);

/**
 * ExportBundleService + UnsignedPadesSigner Unit Tests
 *
 * Verifies the bundle is assembled via DataSubjectRequestService::assembleAccessExport,
 * signed through the injected PadesSigner seam (default stub: SHA-256 hash +
 * signed:false), a one-time token is minted, and altering the bytes changes the
 * hash. Also covers the download replay-refused path.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Export
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Export;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService;
use OCA\OpenRegister\Service\Gdpr\Export\OneTimeDownloadTokenStore;
use OCA\OpenRegister\Service\Gdpr\Export\PadesSigner;
use OCA\OpenRegister\Service\Gdpr\Export\UnsignedPadesSigner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ExportBundleService.
 */
class ExportBundleServiceTest extends TestCase
{

    /**
     * A case entity carrying subject id + evidence + redactions.
     *
     * @return ObjectEntity
     */
    private function caseEntity(): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('00000000-0000-0000-0000-000000000000');
        $object->setObject(
            [
                'subjectId'  => 'subject@example.org',
                'status'     => 'in-progress',
                'evidence'   => [['sourceId' => 'or-objects', 'contentHash' => 'sha256:aaa', 'status' => 'collected']],
                'redactions' => [['field' => 'notes', 'before' => 'x', 'after' => '[redacted]', 'ground' => 'third-party-data']],
            ]
        );
        return $object;

    }//end caseEntity()

    /**
     * The default stub signer attaches a SHA-256 hash and marks unsigned.
     *
     * @return void
     */
    public function testUnsignedSignerAttachesHashAndMarksUnsigned(): void
    {
        $signer = new UnsignedPadesSigner();
        $bundle = $signer->sign('hello');

        $this->assertFalse($bundle->isSigned());
        $this->assertSame(UnsignedPadesSigner::STATE_PENDING_LIBRARY, $bundle->getSignatureState());
        $this->assertSame('sha256:'.hash('sha256', 'hello'), $bundle->getContentHash());

        // Altering the bytes changes the hash (integrity verifiable).
        $this->assertNotSame($bundle->getContentHash(), $signer->sign('hell0')->getContentHash());

    }//end testUnsignedSignerAttachesHashAndMarksUnsigned()

    /**
     * generate() assembles via assembleAccessExport, signs, audits, mints token.
     *
     * @return void
     */
    public function testGenerateAssemblesSignsAndMintsToken(): void
    {
        $case     = $this->caseEntity();
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('load')->willReturn($case);
        $accessor->expects($this->once())->method('save')->willReturn($case);

        $dsr = $this->createMock(DataSubjectRequestService::class);
        $dsr->expects($this->once())
            ->method('assembleAccessExport')
            ->with('subject@example.org', null)
            ->willReturn(['subject' => 'subject@example.org', 'objectCount' => 2, 'generatedAt' => '2026-07-03T00:00:00+00:00', 'objects' => []]);

        $tokenStore = $this->createMock(OneTimeDownloadTokenStore::class);
        $tokenStore->expects($this->once())->method('mint')->willReturn('THE_ONE_TIME_TOKEN');

        $service = new ExportBundleService(
            $dsr,
            $accessor,
            new UnsignedPadesSigner(),
            $tokenStore,
            $this->createMock(AuditTrailMapper::class),
            $this->createMock(LoggerInterface::class)
        );

        $result = $service->generate(caseUuid: '00000000-0000-0000-0000-000000000000');

        $this->assertStringStartsWith('sha256:', $result['contentHash']);
        $this->assertFalse($result['signed']);
        $this->assertSame('THE_ONE_TIME_TOKEN', $result['downloadToken']);

    }//end testGenerateAssemblesSignsAndMintsToken()

    /**
     * download() returns bytes on a valid token and null on a refused token.
     *
     * @return void
     */
    public function testDownloadReturnsBytesOnceThenRefuses(): void
    {
        $case     = $this->caseEntity();
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('load')->willReturn($case);

        $dsr = $this->createMock(DataSubjectRequestService::class);
        $dsr->method('assembleAccessExport')->willReturn(['subject' => 'subject@example.org', 'objectCount' => 0, 'generatedAt' => '', 'objects' => []]);

        $tokenStore = $this->createMock(OneTimeDownloadTokenStore::class);
        // First redeem valid, second replay refused.
        $tokenStore->method('redeem')->willReturnOnConsecutiveCalls(true, false);

        $service = new ExportBundleService(
            $dsr,
            $accessor,
            new UnsignedPadesSigner(),
            $tokenStore,
            $this->createMock(AuditTrailMapper::class),
            $this->createMock(LoggerInterface::class)
        );

        $first = $service->download(caseUuid: 'c1', token: 't1');
        $this->assertNotNull($first);
        $this->assertNotSame('', $first->getBytes());

        $replay = $service->download(caseUuid: 'c1', token: 't1');
        $this->assertNull($replay, 'replay must be refused');

    }//end testDownloadReturnsBytesOnceThenRefuses()

    /**
     * The regulator dossier reflects evidence + redactions + history.
     *
     * @return void
     */
    public function testDossierReflectsEvidenceRedactionsHistory(): void
    {
        $case     = $this->caseEntity();
        $accessor = $this->createMock(CaseObjectAccessor::class);
        $accessor->method('load')->willReturn($case);

        $audit = $this->createMock(AuditTrailMapper::class);
        $audit->method('findByObjectUntil')->willReturn([]);

        $service = new ExportBundleService(
            $this->createMock(DataSubjectRequestService::class),
            $accessor,
            new UnsignedPadesSigner(),
            $this->createMock(OneTimeDownloadTokenStore::class),
            $audit,
            $this->createMock(LoggerInterface::class)
        );

        $dossier = $service->assembleRegulatorDossier(caseUuid: '00000000-0000-0000-0000-000000000000');

        $this->assertCount(1, $dossier['evidence']);
        $this->assertCount(1, $dossier['redactions']);
        $this->assertSame('third-party-data', $dossier['redactions'][0]['ground']);
        $this->assertArrayHasKey('history', $dossier);

    }//end testDossierReflectsEvidenceRedactionsHistory()

    /**
     * The signer dependency is the swappable PadesSigner interface (so the real
     * PAdES-LTV impl drops in without touching the bundle service).
     *
     * @return void
     */
    public function testSignerDependencyIsTheSwappableInterface(): void
    {
        $ctor  = (new \ReflectionClass(ExportBundleService::class))->getConstructor();
        $types = [];
        foreach ($ctor->getParameters() as $p) {
            $t = $p->getType();
            if ($t instanceof \ReflectionNamedType) {
                $types[] = $t->getName();
            }
        }

        $this->assertContains(PadesSigner::class, $types);

    }//end testSignerDependencyIsTheSwappableInterface()
}//end class
