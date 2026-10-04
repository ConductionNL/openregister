<?php

   
                                              
  
                                                                             
                                                                            
                                                                             
                                                                            
                                                                       
                                                          
                                                  
  
                                    
                                               
  
                    
                                                    
  
                                                              
                                  
                                                                                    
  
                                 
  
                                                                                                                                             
   

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use DOMDocument;
use DOMElement;
use ZipArchive;

   
                                                                                 
  
                                                                                                                                             
   
class OoxmlPackage {

	   
                                                                                    
   
                     
    
	private array $refusedParts = [];

	   
                
   
                                              
                                                                   
    
	public function __construct(
		private readonly ZipArchive $zip,
		private readonly int $maxPartBytes,
	) {
	}                   

	   
                                                                                   
   
                                                    
   
                                                                                                                                                                      
    
	public function mainPartPath(): ?string {
		foreach ($this->relationships(partPath: '') as $relationship) {
			if ($relationship['external'] === false && str_ends_with($relationship['type'], '/officeDocument') === true) {
				return $relationship['target'];
			}
		}

		return null;
	}                    

	   
                                                                                                
   
                                                                                  
   
                            
   
                                                                                                                                              
    
	public function readXml(string $path): ?DOMDocument {
		$index = $this->zip->locateName($path, ZipArchive::FL_NOCASE);
		if ($index === false) {
			return null;
		}

		                                                                          
		                                          
		$xml = $this->zip->getFromIndex($index, ($this->maxPartBytes + 1));
		if ($xml === false || $xml === '') {
			return null;
		}

		if (strlen($xml) > $this->maxPartBytes || stripos($xml, '<!DOCTYPE') !== false) {
			$this->refusedParts[] = $path;
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		$document = new DOMDocument();
		$loaded = $document->loadXML($xml, (LIBXML_NONET | LIBXML_COMPACT));
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		                                                                                    
		if ($loaded === false || $document->documentElement === null || $document->doctype !== null) {
			$this->refusedParts[] = $path;
			return null;
		}

		return $document;
	}               

	   
                                                          
   
                                                                                 
                                                                                
                                    
   
                                                                                            
   
                                                                              
   
                                                                                                                                                                            
    
	public function relationships(string $partPath): array {
		$folder = '';
		$relsPath = '_rels/.rels';
		if ($partPath !== '') {
			$folder = $this->folderOf(path: $partPath);
			$relsPath = ltrim($folder . '/_rels/' . basename($partPath) . '.rels', '/');
		}

		$document = $this->readXml(path: $relsPath);
		if ($document === null) {
			return [];
		}

		$relationships = [];
		foreach ($document->getElementsByTagNameNS('*', 'Relationship') as $element) {
			$relationships[$element->getAttribute('Id')] = $this->relationship(element: $element, folder: $folder);
		}

		return $relationships;
	}                     

	   
                                         
   
                        
    
	public function refusedParts(): array {
		return $this->refusedParts;
	}                    

	   
                                                     
   
                                                        
                                                                            
   
                                                               
    
	private function relationship(DOMElement $element, string $folder): array {
		$target = $element->getAttribute('Target');
		if ($element->getAttribute('TargetMode') === 'External') {
			return ['type' => $element->getAttribute('Type'), 'target' => $target, 'external' => true];
		}

		$resolved = $this->resolve(folder: $folder, target: rawurldecode($target));
		if ($resolved === null) {
			return ['type' => $element->getAttribute('Type'), 'target' => $target, 'external' => true];
		}

		return ['type' => $element->getAttribute('Type'), 'target' => $resolved, 'external' => false];
	}                    

	   
                                                                     
   
                                                                   
                                                                            
   
                                                                                
    
	private function resolve(string $folder, string $target): ?string {
		$combined = $folder . '/' . $target;
		if (str_starts_with($target, '/') === true) {
			$combined = $target;
		}

		$segments = [];
		foreach (explode('/', $combined) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}

			if ($segment === '..') {
				if ($segments === []) {
					return null;
				}

				array_pop($segments);
				continue;
			}

			$segments[] = $segment;
		}

		return implode('/', $segments);
	}               

	   
                                                                 
   
                                      
   
                  
    
	private function folderOf(string $path): string {
		$folder = dirname($path);
		if ($folder === '.') {
			return '';
		}

		return $folder;
	}                
}           
