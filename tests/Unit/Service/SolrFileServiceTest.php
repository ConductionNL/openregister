<?php

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SolrFileService;
use Test\TestCase;
use ReflectionClass;

/**
 * Unit tests for SolrFileService
 *
 * @group DB
 */
class SolrFileServiceTest extends TestCase
{
	/** @var SolrFileService */
	private $service;
	
	protected function setUp(): void
	{
		parent::setUp();
		
		// Create partial mock to test private methods.
		$guzzleSolr = $this->createMock(\OCA\OpenRegister\Service\GuzzleSolrService::class);
		$settings = $this->createMock(\OCA\OpenRegister\Service\SettingsService::class);
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		
		$settings->method('getSolrSettingsOnly')->willReturn(['fileCollection' => 'test_files']);
		
		$this->service = new SolrFileService($guzzleSolr, $settings, $logger);
	}
	
	public function testChunkFixedSizeBasic()
	{
		$text = 'ABCDEFGHIJ'; // 10 characters
		$chunks = $this->invokePrivate('chunkFixedSize', [$text, 4, 1]);
		
		// Should create: [ABCD, DEFG, GHIJ].
		$this->assertCount(3, $chunks);
		$this->assertEquals('ABCD', $chunks[0]);
		$this->assertEquals('DEFG', $chunks[1]);
		$this->assertEquals('GHIJ', $chunks[2]);
	}
	
	public function testChunkFixedSizeWithOverlap()
	{
		$text = 'Hello World Test';
		$chunks = $this->invokePrivate('chunkFixedSize', [$text, 8, 3]);
		
		$this->assertIsArray($chunks);
		$this->assertGreaterThan(1, count($chunks));
		
		// Verify overlap exists.
		if (count($chunks) >= 2) {
			$endOfFirst = substr($chunks[0], -3);
			$startOfSecond = substr($chunks[1], 0, 3);
			$this->assertEquals($endOfFirst, $startOfSecond);
		}
	}
	
	public function testChunkFixedSizeLongText()
	{
		$text = str_repeat('A', 1500); // 1500 characters
		$chunks = $this->invokePrivate('chunkFixedSize', [$text, 500, 100]);
		
		$this->assertGreaterThanOrEqual(3, count($chunks));
		
		// Each chunk (except last) should be approximately chunk_size.
		for ($i = 0; $i < count($chunks) - 1; $i++) {
			$this->assertLessThanOrEqual(600, strlen($chunks[$i]));
		}
	}
	
	public function testCleanTextWhitespace()
	{
		$dirtyText = "Hello   World\n\n\n\n  Test  \t  End";
		$clean = $this->invokePrivate('cleanText', [$dirtyText]);
		
		$this->assertEquals("Hello World\n\nTest End", $clean);
	}
	
	public function testCleanTextEmptyLines()
	{
		$text = "Line 1\n\n\n\nLine 2\n\n\nLine 3";
		$clean = $this->invokePrivate('cleanText', [$text]);
		
		// Should reduce multiple newlines to max 2.
		$this->assertStringNotContainsString("\n\n\n", $clean);
	}
	
	public function testCleanTextTrimsSpaces()
	{
		$text = "   Start   ";
		$clean = $this->invokePrivate('cleanText', [$text]);
		
		$this->assertEquals("Start", $clean);
	}
	
	public function testExtractFromTextFile()
	{
		$testContent = "Hello World\nThis is a test file.\nLine 3";
		$tempFile = tmpfile();
		fwrite($tempFile, $testContent);
		$path = stream_get_meta_data($tempFile)['uri'];
		
		$extracted = $this->invokePrivate('extractFromTextFile', [$path]);
		
		$this->assertEquals($testContent, $extracted);
		
		fclose($tempFile);
	}
	
	public function testExtractFromTextFileEmpty()
	{
		$tempFile = tmpfile();
		$path = stream_get_meta_data($tempFile)['uri'];
		
		$extracted = $this->invokePrivate('extractFromTextFile', [$path]);
		
		$this->assertEquals('', $extracted);
		
		fclose($tempFile);
	}
	
	public function testExtractFromHtmlBasic()
	{
		$html = '<html><body><h1>Title</h1><p>Paragraph text</p></body></html>';
		$tempFile = tmpfile();
		fwrite($tempFile, $html);
		$path = stream_get_meta_data($tempFile)['uri'];
		
		$extracted = $this->invokePrivate('extractFromHtml', [$path]);
		
		$this->assertStringContainsString('Title', $extracted);
		$this->assertStringContainsString('Paragraph text', $extracted);
		$this->assertStringNotContainsString('<html>', $extracted);
		$this->assertStringNotContainsString('<p>', $extracted);
		
		fclose($tempFile);
	}
	
	public function testExtractFromHtmlRemovesScripts()
	{
		$html = '<html><body><script>alert("test");</script><p>Content</p></body></html>';
		$tempFile = tmpfile();
		fwrite($tempFile, $html);
		$path = stream_get_meta_data($tempFile)['uri'];
		
		$extracted = $this->invokePrivate('extractFromHtml', [$path]);
		
		$this->assertStringNotContainsString('alert', $extracted);
		$this->assertStringNotContainsString('script', $extracted);
		$this->assertStringContainsString('Content', $extracted);
		
		fclose($tempFile);
	}
	
	public function testJsonToText()
	{
		$json = [
			'title' => 'Test Title',
			'count' => 42,
			'active' => true,
			'nested' => [
				'key' => 'value',
				'items' => ['a', 'b', 'c']
			]
		];
		
		$text = $this->invokePrivate('jsonToText', [$json]);
		
		$this->assertIsString($text);
		$this->assertStringContainsString('Test Title', $text);
		$this->assertStringContainsString('42', $text);
		$this->assertStringContainsString('true', $text);
		$this->assertStringContainsString('value', $text);
		$this->assertStringContainsString('a', $text);
		$this->assertStringContainsString('b', $text);
		$this->assertStringContainsString('c', $text);
	}
	
	public function testChunkRecursiveByParagraph()
	{
		$text = "Paragraph 1.\n\nParagraph 2.\n\nParagraph 3.";
		$chunks = $this->invokePrivate('chunkRecursive', [$text, 20, ["\n\n", ". ", " "]]);
		
		$this->assertIsArray($chunks);
		$this->assertGreaterThan(1, count($chunks));
	}
	
	public function testChunkRecursiveLongParagraph()
	{
		// Single long paragraph that needs to be split.
		$text = str_repeat('Word ', 100); // 500 characters
		$chunks = $this->invokePrivate('chunkRecursive', [$text, 50, [". ", " "]]);
		
		$this->assertGreaterThan(1, count($chunks));
		
		// Each chunk should be relatively close to chunk_size.
		foreach ($chunks as $chunk) {
			$this->assertLessThanOrEqual(100, strlen($chunk)); // Some tolerance
		}
	}
	
	/**
	 * Helper method to invoke private methods
	 *
	 * @param string $methodName
	 * @param array $parameters
	 * @return mixed
	 */
	private function invokePrivate(string $methodName, array $parameters = [])
	{
		$reflection = new ReflectionClass($this->service);
		$method = $reflection->getMethod($methodName);
		$method->setAccessible(true);
		return $method->invokeArgs($this->service, $parameters);
	}
}

