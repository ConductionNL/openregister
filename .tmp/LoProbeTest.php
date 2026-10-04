<?php
declare(strict_types=1);
namespace OCA\OpenRegister\Tests\Unit\Probe;
use OCA\OpenRegister\Service\TextExtraction\PresentationExtractor;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
class LoProbeTest extends TestCase {
	public static function decks(): array {
		return [['python-pptx.pptx'], ['python-pptx-lo.pptx'], ['deck.pptx']];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('decks')]
	public function testLibreOfficeDeck(string $deck): void {
		fwrite(STDERR, "=== $deck\n");
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn((string)file_get_contents(__DIR__ . '/lo/' . $deck));
		$file->method('getMimeType')->willReturn('application/vnd.openxmlformats-officedocument.presentationml.presentation');
		$file->method('getName')->willReturn('deck.pptx');
		$file->method('getId')->willReturn(1);
		$result = (new PresentationExtractor($this->createMock(LoggerInterface::class)))->extract($file);
		fwrite(STDERR, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
		$this->assertIsArray($result);
	}
}
