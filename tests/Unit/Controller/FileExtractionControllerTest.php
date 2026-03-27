<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FileExtractionController;
use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\RiskLevelService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FileExtractionControllerTest extends TestCase
{
    private FileExtractionController $controller;
    private IRequest&MockObject $request;
    private TextExtractionService&MockObject $textExtractor;
    private VectorizationService&MockObject $vectorizationService;
    private ChunkMapper&MockObject $chunkMapper;
    private EntityRelationMapper&MockObject $entityRelationMapper;
    private RiskLevelService&MockObject $riskLevelService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->textExtractor = $this->createMock(TextExtractionService::class);
        $this->vectorizationService = $this->createMock(VectorizationService::class);
        $this->chunkMapper = $this->createMock(ChunkMapper::class);
        $this->entityRelationMapper = $this->createMock(EntityRelationMapper::class);
        $this->riskLevelService = $this->createMock(RiskLevelService::class);

        $this->controller = new FileExtractionController(
            'openregister',
            $this->request,
            $this->textExtractor,
            $this->vectorizationService,
            $this->chunkMapper,
            $this->entityRelationMapper,
            $this->riskLevelService
        );
    }

    // ─── index() ────────────────────────────────────────────────────

    public function testIndexSuccessEmpty(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn([]);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(0);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']);
        $this->assertEquals(0, $data['count']);
    }

    public function testIndexWithSummaries(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $summaries = [
            [
                'sourceId'      => 42,
                'fileName'      => 'report.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 1024,
                'chunkCount'    => 5,
                'lastExtracted' => '2026-01-01T00:00:00Z',
            ],
            [
                'sourceId'      => 43,
                'fileName'      => 'notes.txt',
                'mimeType'      => 'text/plain',
                'fileSize'      => 256,
                'chunkCount'    => 1,
                'lastExtracted' => '2026-01-02T00:00:00Z',
            ],
        ];

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn($summaries);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(2);

        $this->entityRelationMapper->method('findByFileId')
            ->willReturnMap([
                [42, ['entity1', 'entity2']],
                [43, []],
            ]);

        $this->riskLevelService->method('getRiskLevel')
            ->willReturnMap([
                [42, 'high'],
                [43, 'low'],
            ]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
        $this->assertEquals(2, $data['count']);

        // Verify first file data structure.
        $file1 = $data['data'][0];
        $this->assertEquals(42, $file1['id']);
        $this->assertEquals('report.pdf', $file1['fileName']);
        $this->assertEquals('application/pdf', $file1['mimeType']);
        $this->assertEquals(1024, $file1['fileSize']);
        $this->assertEquals('completed', $file1['extractionStatus']);
        $this->assertEquals(5, $file1['chunkCount']);
        $this->assertEquals('2026-01-01T00:00:00Z', $file1['extractedAt']);
        $this->assertNull($file1['extractionError']);
        $this->assertEquals(2, $file1['entityCount']);
        $this->assertEquals('high', $file1['riskLevel']);

        // Verify second file.
        $file2 = $data['data'][1];
        $this->assertEquals(43, $file2['id']);
        $this->assertEquals(0, $file2['entityCount']);
        $this->assertEquals('low', $file2['riskLevel']);
    }

    public function testIndexFilterNonCompletedStatus(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, 'pending'],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']);
        $this->assertEquals(0, $data['count']);
    }

    public function testIndexFilterStatusCompleted(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, 'completed'],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn([]);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(0);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testIndexFilterStatusEmpty(): void
    {
        // Empty string status should pass through (not trigger early return).
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, ''],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn([]);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(0);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testIndexWithSearchTerm(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, 'report'],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $this->chunkMapper->expects($this->once())
            ->method('getFileSourceSummaries')
            ->with(50, 0, 'report', 'extractedAt', 'DESC')
            ->willReturn([]);
        $this->chunkMapper->expects($this->once())
            ->method('countFileSourceSummaries')
            ->with('report')
            ->willReturn(0);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testIndexWithEmptySearchTerm(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, ''],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $this->chunkMapper->expects($this->once())
            ->method('getFileSourceSummaries')
            ->with(50, 0, null, 'extractedAt', 'DESC')
            ->willReturn([]);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(0);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testIndexWithRiskLevelFilter(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, 'high'],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $summaries = [
            [
                'sourceId'      => 1,
                'fileName'      => 'file1.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 100,
                'chunkCount'    => 2,
                'lastExtracted' => '2026-01-01T00:00:00Z',
            ],
            [
                'sourceId'      => 2,
                'fileName'      => 'file2.txt',
                'mimeType'      => 'text/plain',
                'fileSize'      => 200,
                'chunkCount'    => 1,
                'lastExtracted' => '2026-01-02T00:00:00Z',
            ],
        ];

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn($summaries);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(2);

        $this->entityRelationMapper->method('findByFileId')->willReturn([]);

        $this->riskLevelService->method('getRiskLevel')
            ->willReturnMap([
                [1, 'high'],
                [2, 'low'],
            ]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['data']);
        $this->assertEquals(1, $data['count']);
        $this->assertEquals('high', $data['data'][0]['riskLevel']);
    }

    public function testIndexWithEmptyRiskLevelFilter(): void
    {
        // Empty string riskLevel should not filter.
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, ''],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn([]);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(0);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame([], $data['data']);
    }

    public function testIndexSortByRiskLevelAsc(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'riskLevel'],
                ['order', 'DESC', 'ASC'],
            ]);

        $summaries = [
            [
                'sourceId'      => 1,
                'fileName'      => 'high-risk.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 100,
                'chunkCount'    => 2,
                'lastExtracted' => '2026-01-01T00:00:00Z',
            ],
            [
                'sourceId'      => 2,
                'fileName'      => 'low-risk.txt',
                'mimeType'      => 'text/plain',
                'fileSize'      => 200,
                'chunkCount'    => 1,
                'lastExtracted' => '2026-01-02T00:00:00Z',
            ],
            [
                'sourceId'      => 3,
                'fileName'      => 'medium-risk.doc',
                'mimeType'      => 'application/msword',
                'fileSize'      => 300,
                'chunkCount'    => 3,
                'lastExtracted' => '2026-01-03T00:00:00Z',
            ],
        ];

        // PHP sort: fetch all from DB (null limit/offset, fallback sort).
        $this->chunkMapper->expects($this->once())
            ->method('getFileSourceSummaries')
            ->with(null, null, null, 'extractedAt', 'ASC')
            ->willReturn($summaries);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(3);

        $this->entityRelationMapper->method('findByFileId')->willReturn([]);

        $this->riskLevelService->method('getRiskLevel')
            ->willReturnMap([
                [1, 'high'],
                [2, 'low'],
                [3, 'medium'],
            ]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(3, $data['data']);
        $this->assertEquals(3, $data['count']);

        // ASC risk order: none(0) < low(1) < medium(2) < high(3).
        $this->assertEquals('low', $data['data'][0]['riskLevel']);
        $this->assertEquals('medium', $data['data'][1]['riskLevel']);
        $this->assertEquals('high', $data['data'][2]['riskLevel']);
    }

    public function testIndexSortByRiskLevelDesc(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'riskLevel'],
                ['order', 'DESC', 'DESC'],
            ]);

        $summaries = [
            [
                'sourceId'      => 1,
                'fileName'      => 'file1.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 100,
                'chunkCount'    => 2,
                'lastExtracted' => '2026-01-01T00:00:00Z',
            ],
            [
                'sourceId'      => 2,
                'fileName'      => 'file2.txt',
                'mimeType'      => 'text/plain',
                'fileSize'      => 200,
                'chunkCount'    => 1,
                'lastExtracted' => '2026-01-02T00:00:00Z',
            ],
        ];

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn($summaries);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(2);
        $this->entityRelationMapper->method('findByFileId')->willReturn([]);

        $this->riskLevelService->method('getRiskLevel')
            ->willReturnMap([
                [1, 'high'],
                [2, 'low'],
            ]);

        $result = $this->controller->index();

        $data = $result->getData();
        // DESC risk order: high(3) > low(1).
        $this->assertEquals('high', $data['data'][0]['riskLevel']);
        $this->assertEquals('low', $data['data'][1]['riskLevel']);
    }

    public function testIndexSortByEntityCountAsc(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'entityCount'],
                ['order', 'DESC', 'ASC'],
            ]);

        $summaries = [
            [
                'sourceId'      => 1,
                'fileName'      => 'many-entities.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 100,
                'chunkCount'    => 2,
                'lastExtracted' => '2026-01-01T00:00:00Z',
            ],
            [
                'sourceId'      => 2,
                'fileName'      => 'few-entities.txt',
                'mimeType'      => 'text/plain',
                'fileSize'      => 200,
                'chunkCount'    => 1,
                'lastExtracted' => '2026-01-02T00:00:00Z',
            ],
        ];

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn($summaries);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(2);

        $this->entityRelationMapper->method('findByFileId')
            ->willReturnMap([
                [1, ['e1', 'e2', 'e3']],
                [2, ['e1']],
            ]);

        $this->riskLevelService->method('getRiskLevel')->willReturn('none');

        $result = $this->controller->index();

        $data = $result->getData();
        // ASC: 1 entity first, then 3.
        $this->assertEquals(1, $data['data'][0]['entityCount']);
        $this->assertEquals(3, $data['data'][1]['entityCount']);
    }

    public function testIndexSortByEntityCountDesc(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'entityCount'],
                ['order', 'DESC', 'DESC'],
            ]);

        $summaries = [
            [
                'sourceId'      => 1,
                'fileName'      => 'few.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 100,
                'chunkCount'    => 2,
                'lastExtracted' => '2026-01-01T00:00:00Z',
            ],
            [
                'sourceId'      => 2,
                'fileName'      => 'many.txt',
                'mimeType'      => 'text/plain',
                'fileSize'      => 200,
                'chunkCount'    => 1,
                'lastExtracted' => '2026-01-02T00:00:00Z',
            ],
        ];

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn($summaries);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(2);

        $this->entityRelationMapper->method('findByFileId')
            ->willReturnMap([
                [1, ['e1']],
                [2, ['e1', 'e2', 'e3']],
            ]);

        $this->riskLevelService->method('getRiskLevel')->willReturn('none');

        $result = $this->controller->index();

        $data = $result->getData();
        // DESC: 3 entities first, then 1.
        $this->assertEquals(3, $data['data'][0]['entityCount']);
        $this->assertEquals(1, $data['data'][1]['entityCount']);
    }

    public function testIndexPhpSortWithPagination(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 2],
                ['offset', 0, 1],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'riskLevel'],
                ['order', 'DESC', 'ASC'],
            ]);

        $summaries = [
            [
                'sourceId'      => 1,
                'fileName'      => 'f1.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 100,
                'chunkCount'    => 1,
                'lastExtracted' => '2026-01-01T00:00:00Z',
            ],
            [
                'sourceId'      => 2,
                'fileName'      => 'f2.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 200,
                'chunkCount'    => 2,
                'lastExtracted' => '2026-01-02T00:00:00Z',
            ],
            [
                'sourceId'      => 3,
                'fileName'      => 'f3.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 300,
                'chunkCount'    => 3,
                'lastExtracted' => '2026-01-03T00:00:00Z',
            ],
        ];

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn($summaries);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(3);
        $this->entityRelationMapper->method('findByFileId')->willReturn([]);

        $this->riskLevelService->method('getRiskLevel')
            ->willReturnMap([
                [1, 'high'],
                [2, 'low'],
                [3, 'medium'],
            ]);

        $result = $this->controller->index();

        $data = $result->getData();
        // Total count is all items (3), but data is sliced: offset=1, limit=2.
        $this->assertEquals(3, $data['count']);
        // ASC sorted: low(1), medium(2), high(3) → offset 1, limit 2 → medium, high.
        $this->assertCount(2, $data['data']);
        $this->assertEquals('medium', $data['data'][0]['riskLevel']);
        $this->assertEquals('high', $data['data'][1]['riskLevel']);
    }

    public function testIndexSortByRiskLevelWithUnknownRisk(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'riskLevel'],
                ['order', 'DESC', 'ASC'],
            ]);

        $summaries = [
            [
                'sourceId'      => 1,
                'fileName'      => 'f1.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 100,
                'chunkCount'    => 1,
                'lastExtracted' => '2026-01-01T00:00:00Z',
            ],
            [
                'sourceId'      => 2,
                'fileName'      => 'f2.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 200,
                'chunkCount'    => 2,
                'lastExtracted' => '2026-01-02T00:00:00Z',
            ],
        ];

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn($summaries);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(2);
        $this->entityRelationMapper->method('findByFileId')->willReturn([]);

        // Return an unknown risk level to hit the ?? 0 fallback.
        $this->riskLevelService->method('getRiskLevel')
            ->willReturnMap([
                [1, 'unknown_level'],
                [2, 'low'],
            ]);

        $result = $this->controller->index();

        $data = $result->getData();
        // unknown_level maps to 0, low maps to 1. ASC: unknown first.
        $this->assertEquals('unknown_level', $data['data'][0]['riskLevel']);
        $this->assertEquals('low', $data['data'][1]['riskLevel']);
    }

    public function testIndexSortByDbColumn(): void
    {
        // Non-riskLevel, non-entityCount sort should use DB-level sorting.
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 10],
                ['offset', 0, 5],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'fileName'],
                ['order', 'DESC', 'ASC'],
            ]);

        $this->chunkMapper->expects($this->once())
            ->method('getFileSourceSummaries')
            ->with(10, 5, null, 'fileName', 'ASC')
            ->willReturn([]);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(0);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testIndexException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, null],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);
        $this->chunkMapper->method('getFileSourceSummaries')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('DB error', $data['error']);
    }

    public function testIndexRiskLevelFilterNoMatch(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', 50, 50],
                ['offset', 0, 0],
                ['search', null, null],
                ['status', null, null],
                ['riskLevel', null, 'very_high'],
                ['sort', 'extractedAt', 'extractedAt'],
                ['order', 'DESC', 'DESC'],
            ]);

        $summaries = [
            [
                'sourceId'      => 1,
                'fileName'      => 'file1.pdf',
                'mimeType'      => 'application/pdf',
                'fileSize'      => 100,
                'chunkCount'    => 2,
                'lastExtracted' => '2026-01-01T00:00:00Z',
            ],
        ];

        $this->chunkMapper->method('getFileSourceSummaries')->willReturn($summaries);
        $this->chunkMapper->method('countFileSourceSummaries')->willReturn(1);
        $this->entityRelationMapper->method('findByFileId')->willReturn([]);
        $this->riskLevelService->method('getRiskLevel')->willReturn('low');

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']);
        $this->assertEquals(0, $data['count']);
    }

    // ─── show() ─────────────────────────────────────────────────────

    public function testShowSuccess(): void
    {
        $chunk1 = $this->createMock(\OCA\OpenRegister\Db\Chunk::class);
        $chunk1->method('jsonSerialize')->willReturn([
            'id'         => 1,
            'sourceType' => 'file',
            'sourceId'   => 42,
            'chunkIndex' => 0,
        ]);

        $chunk2 = $this->createMock(\OCA\OpenRegister\Db\Chunk::class);
        $chunk2->method('jsonSerialize')->willReturn([
            'id'         => 2,
            'sourceType' => 'file',
            'sourceId'   => 42,
            'chunkIndex' => 1,
        ]);

        $this->chunkMapper->method('findBySource')
            ->with('file', 42)
            ->willReturn([$chunk1, $chunk2]);

        $result = $this->controller->show(42);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
        $this->assertEquals(0, $data['data'][0]['chunkIndex']);
        $this->assertEquals(1, $data['data'][1]['chunkIndex']);
    }

    public function testShowNotFoundEmpty(): void
    {
        $this->chunkMapper->method('findBySource')->willReturn([]);

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('File not found in extraction system', $data['error']);
        $this->assertStringContainsString('999', $data['message']);
    }

    public function testShowException(): void
    {
        $this->chunkMapper->method('findBySource')
            ->willThrowException(new \Exception('Database connection failed'));

        $result = $this->controller->show(42);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('File not found in extraction system', $data['error']);
        $this->assertEquals('Database connection failed', $data['message']);
    }

    // ─── extract() ──────────────────────────────────────────────────

    public function testExtractSuccess(): void
    {
        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(1, false);

        $result = $this->controller->extract(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('File extraction completed', $data['message']);
    }

    public function testExtractWithForceReExtract(): void
    {
        $this->textExtractor->expects($this->once())
            ->method('extractFile')
            ->with(1, true);

        $result = $this->controller->extract(1, true);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testExtractFileNotFound(): void
    {
        $this->textExtractor->method('extractFile')
            ->willThrowException(new NotFoundException('File 999 not found'));

        $result = $this->controller->extract(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('File not found in Nextcloud', $data['error']);
        $this->assertEquals('File 999 not found', $data['message']);
    }

    public function testExtractGeneralException(): void
    {
        $this->textExtractor->method('extractFile')
            ->willThrowException(new \Exception('Extraction engine crashed'));

        $result = $this->controller->extract(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Extraction failed', $data['error']);
        $this->assertEquals('Extraction engine crashed', $data['message']);
    }

    // ─── discover() ─────────────────────────────────────────────────

    public function testDiscoverSuccess(): void
    {
        $stats = ['discovered' => 5, 'failed' => 0, 'total' => 5];
        $this->textExtractor->expects($this->once())
            ->method('discoverUntrackedFiles')
            ->with(100)
            ->willReturn($stats);

        $result = $this->controller->discover();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('File discovery completed', $data['message']);
        $this->assertEquals($stats, $data['data']);
    }

    public function testDiscoverWithCustomLimit(): void
    {
        $stats = ['discovered' => 10, 'failed' => 2, 'total' => 12];
        $this->textExtractor->expects($this->once())
            ->method('discoverUntrackedFiles')
            ->with(200)
            ->willReturn($stats);

        $result = $this->controller->discover(200);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals($stats, $data['data']);
    }

    public function testDiscoverException(): void
    {
        $this->textExtractor->method('discoverUntrackedFiles')
            ->willThrowException(new \Exception('Filesystem error'));

        $result = $this->controller->discover();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('File discovery failed', $data['error']);
        $this->assertEquals('Filesystem error', $data['message']);
    }

    // ─── extractAll() ───────────────────────────────────────────────

    public function testExtractAllSuccess(): void
    {
        $stats = ['processed' => 10, 'failed' => 0, 'total' => 10];
        $this->textExtractor->expects($this->once())
            ->method('extractPendingFiles')
            ->with(100)
            ->willReturn($stats);

        $result = $this->controller->extractAll();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Batch extraction completed', $data['message']);
        $this->assertEquals($stats, $data['data']);
    }

    public function testExtractAllWithCustomLimit(): void
    {
        $stats = ['processed' => 50, 'failed' => 5, 'total' => 55];
        $this->textExtractor->expects($this->once())
            ->method('extractPendingFiles')
            ->with(500)
            ->willReturn($stats);

        $result = $this->controller->extractAll(500);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testExtractAllException(): void
    {
        $this->textExtractor->method('extractPendingFiles')
            ->willThrowException(new \Exception('Batch processing error'));

        $result = $this->controller->extractAll();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Batch extraction failed', $data['error']);
        $this->assertEquals('Batch processing error', $data['message']);
    }

    // ─── retryFailed() ──────────────────────────────────────────────

    public function testRetryFailedSuccess(): void
    {
        $stats = ['retried' => 3, 'failed' => 1, 'total' => 4];
        $this->textExtractor->expects($this->once())
            ->method('retryFailedExtractions')
            ->with(50)
            ->willReturn($stats);

        $result = $this->controller->retryFailed();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Retry completed', $data['message']);
        $this->assertEquals($stats, $data['data']);
    }

    public function testRetryFailedWithCustomLimit(): void
    {
        $stats = ['retried' => 10, 'failed' => 0, 'total' => 10];
        $this->textExtractor->expects($this->once())
            ->method('retryFailedExtractions')
            ->with(25)
            ->willReturn($stats);

        $result = $this->controller->retryFailed(25);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testRetryFailedException(): void
    {
        $this->textExtractor->method('retryFailedExtractions')
            ->willThrowException(new \Exception('Retry engine failed'));

        $result = $this->controller->retryFailed();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Retry failed', $data['error']);
        $this->assertEquals('Retry engine failed', $data['message']);
    }

    // ─── stats() ────────────────────────────────────────────────────

    public function testStatsSuccess(): void
    {
        $stats = [
            'totalFiles'     => 100,
            'untrackedFiles' => 20,
            'totalChunks'    => 500,
            'totalObjects'   => 80,
            'totalEntities'  => 150,
        ];
        $this->textExtractor->method('getStats')->willReturn($stats);

        $result = $this->controller->stats();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals($stats, $data['data']);
    }

    public function testStatsException(): void
    {
        $this->textExtractor->method('getStats')
            ->willThrowException(new \Exception('Stats unavailable'));

        $result = $this->controller->stats();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Failed to retrieve statistics', $data['error']);
        $this->assertEquals('Stats unavailable', $data['message']);
    }

    // ─── cleanup() ──────────────────────────────────────────────────

    public function testCleanupSuccess(): void
    {
        $result = $this->controller->cleanup();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Cleanup completed', $data['message']);
        $this->assertEquals(0, $data['data']['deleted']);
        $this->assertSame([], $data['data']['reasons']);
    }

    // ─── fileTypes() ────────────────────────────────────────────────

    public function testFileTypesSuccess(): void
    {
        $result = $this->controller->fileTypes();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']);
    }

    // ─── vectorizeBatch() ───────────────────────────────────────────

    public function testVectorizeBatchSuccessDefaults(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->vectorizationService->expects($this->once())
            ->method('vectorizeBatch')
            ->with('file', [
                'mode'       => 'serial',
                'max_files'  => 0,
                'batch_size' => 50,
                'file_types' => [],
            ])
            ->willReturn(['processed' => 5, 'failed' => 0]);

        $result = $this->controller->vectorizeBatch();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals(['processed' => 5, 'failed' => 0], $data['data']);
    }

    public function testVectorizeBatchWithCustomParams(): void
    {
        $this->request->method('getParams')->willReturn([
            'mode'       => 'parallel',
            'max_files'  => 100,
            'batch_size' => 25,
            'file_types' => ['application/pdf', 'text/plain'],
        ]);

        $this->vectorizationService->expects($this->once())
            ->method('vectorizeBatch')
            ->with('file', [
                'mode'       => 'parallel',
                'max_files'  => 100,
                'batch_size' => 25,
                'file_types' => ['application/pdf', 'text/plain'],
            ])
            ->willReturn(['processed' => 50]);

        $result = $this->controller->vectorizeBatch();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testVectorizeBatchException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->vectorizationService->method('vectorizeBatch')
            ->willThrowException(new \Exception('Embedding service unavailable'));

        $result = $this->controller->vectorizeBatch();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Vectorization failed', $data['error']);
        $this->assertEquals('Embedding service unavailable', $data['message']);
    }

    public function testVectorizeBatchPartialParams(): void
    {
        // Only some params provided — rest should use defaults.
        $this->request->method('getParams')->willReturn([
            'mode' => 'parallel',
        ]);

        $this->vectorizationService->expects($this->once())
            ->method('vectorizeBatch')
            ->with('file', [
                'mode'       => 'parallel',
                'max_files'  => 0,
                'batch_size' => 50,
                'file_types' => [],
            ])
            ->willReturn(['processed' => 10]);

        $result = $this->controller->vectorizeBatch();

        $this->assertEquals(200, $result->getStatus());
    }
}
