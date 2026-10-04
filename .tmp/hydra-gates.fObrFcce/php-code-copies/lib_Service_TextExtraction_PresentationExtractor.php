<?php

   
                                      
  
                                                                         
                                                                              
                                                                               
                                                                                
                                                                           
                                                                              
                                                                             
                                                                                
                                                                                
                                                   
  
                                                                  
                                                                                
                                                                                  
                                                                 
  
                                    
                                               
  
                    
                                                    
  
                                                              
                                  
                                                                                    
  
                                 
  
                                                                                           
   

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use Exception;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

   
                                                    
  
                                         
                   
                    
                     
                          
                     
                                                                                             
    
  
                                                                                           
   
class PresentationExtractor {

	   
                                                                               
   
            
    
	public const MAX_SLIDES = 500;

	   
                                                                      
   
            
    
	public const MAX_PART_BYTES = 20971520;

	   
                                          
   
                     
    
	private const SUPPORTED_MIME_TYPES = [
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.ms-powerpoint.presentation.macroenabled.12',
		'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
	];

	   
                                                                            
   
                     
    
	private const GENERIC_MIME_TYPES = ['', 'application/octet-stream', 'application/zip', 'application/x-zip-compressed'];

	   
                                                  
   
                     
    
	private const SUPPORTED_EXTENSIONS = ['pptx', 'pptm', 'ppsx'];

	   
                                          
   
                                
    
	private readonly PresentationSlideParser $slideParser;

	   
                
   
                                          
    
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
		$this->slideParser = new PresentationSlideParser();
	}                   

	   
                                                                                                         
   
                                               
                                          
   
                
   
                                                                                                                                                            
    
	public function supports(string $mimeType, string $fileName): bool {
		$mimeType = strtolower($mimeType);
		if (in_array($mimeType, self::SUPPORTED_MIME_TYPES, true) === true) {
			return true;
		}

		if (in_array($mimeType, self::GENERIC_MIME_TYPES, true) === false) {
			return false;
		}

		return in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), self::SUPPORTED_EXTENSIONS, true);
	}                

	   
                                       
   
                               
   
                                                                                                               
                                                                                                         
   
                                                                                
   
                                                                                                                                                                      
    
	public function extract(File $file): ?array {
		if (class_exists(ZipArchive::class) === false) {
			$this->logger->warning(
				message: '[PresentationExtractor] PHP zip extension not available',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId()]
			);
			throw new Exception('The PHP zip extension is not installed. Install php-zip to read presentations.');
		}

		$mimeType = (string)$file->getMimeType();
		if ($this->supports(mimeType: $mimeType, fileName: (string)$file->getName()) === false) {
			$this->logger->debug(
				message: '[PresentationExtractor] Not a presentation format this extractor reads',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId(), 'mimeType' => $mimeType]
			);
			return null;
		}

		$tempFile = null;
		$zip = null;
		try {
			                                                                                              
			$tempFile = tmpfile();
			fwrite($tempFile, $file->getContent());

			$zip = new ZipArchive();
			$opened = $zip->open(stream_get_meta_data($tempFile)['uri'], ZipArchive::RDONLY);
			if ($opened !== true) {
				$zip = null;
				throw new RuntimeException('Not a zip package (ZipArchive code ' . (int)$opened . ')');
			}

			$package = new OoxmlPackage(zip: $zip, maxPartBytes: self::MAX_PART_BYTES);
			$result = $this->readPresentation(package: $package);
			$this->logRefusedParts(package: $package, file: $file);

			if ($result === null || $result['slides'] === []) {
				$this->logger->warning(
					message: '[PresentationExtractor] Presentation holds no readable slides',
					context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId()]
				);
				return null;
			}

			$this->logger->debug(
				message: '[PresentationExtractor] Presentation extracted',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'slides' => count($result['slides']),
					'truncated' => $result['truncated'],
				]
			);

			return $result;
		} catch (Throwable $e) {
			                                                                              
			$this->logger->error(
				message: '[PresentationExtractor] Presentation extraction failed; returning null',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'fileId' => $file->getId(),
					'mimeType' => $mimeType,
					'exception' => get_class($e),
				]
			);
			return null;
		} finally {
			if ($zip !== null) {
				$zip->close();
			}

			if (is_resource($tempFile) === true) {
				fclose($tempFile);
			}
		}         
	}               

	   
                                                                         
   
                                                    
   
                                                                                                                 
   
                                                                                                                                                            
                                                                                                                                              
    
	private function readPresentation(OoxmlPackage $package): ?array {
		$mainPath = ($package->mainPartPath() ?? 'ppt/presentation.xml');
		$presentation = $package->readXml(path: $mainPath);
		if ($presentation === null) {
			return null;
		}

		$relationships = $package->relationships(partPath: $mainPath);
		$slides = [];
		$truncated = false;
		foreach ($this->slideParser->slideRelationshipIds(presentation: $presentation) as $position => $relationshipId) {
			if ($position >= self::MAX_SLIDES) {
				$truncated = true;
				break;
			}

			$relationship = ($relationships[$relationshipId] ?? null);
			if ($relationship === null || $relationship['external'] === true) {
				continue;
			}

			$slides[] = $this->readSlide(package: $package, path: $relationship['target'], number: ($position + 1));
		}

		return ['slides' => $slides, 'truncated' => $truncated];
	}                        

	   
                                                                                        
   
                                                    
                                            
                                                                
   
                             
   
                                                                                                                                                                                   
    
	private function readSlide(OoxmlPackage $package, string $path, int $number): array {
		$slide = ['number' => $number, 'hidden' => false, 'title' => '', 'body' => [], 'notes' => '', 'images' => []];

		$document = $package->readXml(path: $path);
		if ($document === null) {
			return $slide;
		}

		$relationships = $package->relationships(partPath: $path);
		$parsed = $this->slideParser->parseSlide(slide: $document, relationships: $relationships);

		$slide['hidden'] = $parsed['hidden'];
		$slide['title'] = $parsed['title'];
		$slide['body'] = $parsed['body'];
		$slide['images'] = $parsed['images'];
		$slide['notes'] = $this->readNotes(package: $package, relationships: $relationships);

		return $slide;
	}                 

	   
                                                                            
   
                                                    
                                                                                                                       
   
                                                            
   
                                                                                                                                                          
    
	private function readNotes(OoxmlPackage $package, array $relationships): string {
		foreach ($relationships as $relationship) {
			if ($relationship['external'] === true || str_ends_with($relationship['type'], '/notesSlide') === false) {
				continue;
			}

			$document = $package->readXml(path: $relationship['target']);
			if ($document === null) {
				return '';
			}

			return $this->slideParser->parseNotes(notes: $document);
		}

		return '';
	}                 

	   
                                                                                     
   
                                             
                               
   
                
    
	private function logRefusedParts(OoxmlPackage $package, File $file): void {
		$refused = $package->refusedParts();
		if ($refused === []) {
			return;
		}

		$this->logger->warning(
			message: '[PresentationExtractor] Refused parts that were too large, declared a DOCTYPE or were not XML',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId(), 'parts' => $refused]
		);
	}                       
}           
