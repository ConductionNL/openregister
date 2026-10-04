<?php

   
                                         
  
                                                                                 
                                                                           
                                                                            
                                                                              
                                                                           
                                                                   
                                                  
  
                                                                           
                             
  
                                    
                                               
  
                    
                                                    
  
                                                              
                                  
                                                                                    
  
                                 
  
                                                                                                                                                                                  
   

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use DOMDocument;
use DOMElement;

   
                                                                         
  
                                                                                                    
                                                                                                       
  
                                                                                                                                                                                  
   
class PresentationSlideParser {

	   
                                                                         
   
            
    
	public const MAX_GROUP_DEPTH = 20;

	   
                                                
   
                     
    
	private const TITLE_TYPES = ['title', 'ctrTitle'];

	   
                                                           
   
                     
    
	private const FURNITURE_TYPES = ['sldNum', 'dt', 'ftr', 'hdr', 'sldImg'];

	   
                                                                            
   
                                                                  
   
                                                                      
   
                                                                                                                                                            
    
	public function slideRelationshipIds(DOMDocument $presentation): array {
		$ids = [];
		foreach ($presentation->getElementsByTagNameNS('*', 'sldId') as $slideId) {
			$ids[] = $this->relationshipAttribute(element: $slideId, localNames: ['id']);
		}

		return $ids;
	}                            

	   
                                                                              
   
                                                    
                                                                                                                       
   
                                                                                            
   
                                                                                                                                                                                   
                                                                                                                                                                            
    
	public function parseSlide(DOMDocument $slide, array $relationships): array {
		$content = ['titles' => [], 'body' => [], 'images' => []];
		$tree = $this->firstDescendant(element: $slide, localName: 'spTree');
		if ($tree !== null) {
			$this->walk(container: $tree, relationships: $relationships, content: $content, depth: 0);
		}

		return [
			'hidden' => in_array((string)$slide->documentElement?->getAttribute('show'), ['0', 'false'], true),
			'title' => implode(' ', $content['titles']),
			'body' => $content['body'],
			'images' => $content['images'],
		];
	}                  

	   
                                                                      
   
                                                                                 
                                                                                 
                                                                                
   
                                                    
   
                                                                      
   
                                                                                                                                                          
    
	public function parseNotes(DOMDocument $notes): string {
		$paragraphs = [];
		foreach ($notes->getElementsByTagNameNS('*', 'sp') as $shape) {
			if (in_array($this->placeholderType(shape: $shape), self::FURNITURE_TYPES, true) === true) {
				continue;
			}

			array_push($paragraphs, ...$this->paragraphs(textBody: $this->child(parent: $shape, localName: 'txBody')));
		}

		return implode("\n", $paragraphs);
	}                  

	   
                                                                        
   
                                                                                           
                                                                                                                       
                                                                            
                                                               
   
                
    
	private function walk(DOMElement $container, array $relationships, array &$content, int $depth): void {
		if ($depth > self::MAX_GROUP_DEPTH) {
			return;
		}

		foreach ($container->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$this->visit(shape: $child, relationships: $relationships, content: $content, depth: $depth);
			}
		}
	}            

	   
                                                                                    
   
                                               
                                                                                                                       
                                                                            
                                                          
   
                
    
	private function visit(DOMElement $shape, array $relationships, array &$content, int $depth): void {
		if ($shape->localName === 'sp') {
			$this->collectText(shape: $shape, content: $content);
			return;
		}

		if ($shape->localName === 'grpSp') {
			$this->walk(container: $shape, relationships: $relationships, content: $content, depth: ($depth + 1));
			return;
		}

		if ($shape->localName === 'graphicFrame') {
			foreach ($shape->getElementsByTagNameNS('*', 'tc') as $cell) {
				array_push($content['body'], ...$this->paragraphs(textBody: $this->child(parent: $cell, localName: 'txBody')));
			}

			return;
		}

		if ($shape->localName === 'pic') {
			$content['images'][] = $this->picture(picture: $shape, relationships: $relationships);
			return;
		}

		if ($shape->localName === 'AlternateContent') {
			                                                               
			$branch = ($this->child(parent: $shape, localName: 'Fallback') ?? $this->child(parent: $shape, localName: 'Choice'));
			if ($branch !== null) {
				$this->walk(container: $branch, relationships: $relationships, content: $content, depth: ($depth + 1));
			}
		}
	}             

	   
                                                                                
   
                                            
                                                                            
   
                
    
	private function collectText(DOMElement $shape, array &$content): void {
		$type = $this->placeholderType(shape: $shape);
		if (in_array($type, self::FURNITURE_TYPES, true) === true) {
			return;
		}

		$paragraphs = $this->paragraphs(textBody: $this->child(parent: $shape, localName: 'txBody'));
		if (in_array($type, self::TITLE_TYPES, true) === false) {
			array_push($content['body'], ...$paragraphs);
			return;
		}

		if ($paragraphs !== []) {
			$content['titles'][] = implode(' ', $paragraphs);
		}
	}                   

	   
                                                                                        
   
                                               
                                                                                                                       
   
                      
    
	private function picture(DOMElement $picture, array $relationships): array {
		$properties = $this->firstDescendant(element: $picture, localName: 'cNvPr');
		$blip = $this->firstDescendant(element: $picture, localName: 'blip');

		                                                                                       
		$relationshipId = $this->relationshipAttribute(element: $blip, localNames: ['embed', 'link']);
		$relationship = ($relationships[$relationshipId] ?? ['target' => '', 'external' => false]);

		return [
			'target' => $relationship['target'],
			'external' => $relationship['external'],
			'name' => (string)$properties?->getAttribute('name'),
			'description' => (string)$properties?->getAttribute('descr'),
		];
	}               

	   
                                                                                  
   
                                                                 
   
                        
    
	private function paragraphs(?DOMElement $textBody): array {
		if ($textBody === null) {
			return [];
		}

		$paragraphs = [];
		foreach ($textBody->childNodes as $child) {
			if (($child instanceof DOMElement) === false || $child->localName !== 'p') {
				continue;
			}

			$text = $this->paragraphText(paragraph: $child);
			if ($text !== '') {
				$paragraphs[] = $text;
			}
		}

		return $paragraphs;
	}                  

	   
                                                                                
   
                                               
   
                  
    
	private function paragraphText(DOMElement $paragraph): string {
		$text = '';
		foreach ($paragraph->getElementsByTagNameNS('*', '*') as $node) {
			if ($node->localName === 't') {
				$text .= $node->textContent;
			}

			if ($node->localName === 'br') {
				$text .= ' ';
			}
		}

		return trim((string)preg_replace('/\s+/u', ' ', $text));
	}                     

	   
                                                                               
   
                                            
   
                                                  
    
	private function placeholderType(DOMElement $shape): string {
		$placeholder = $this->firstDescendant(element: ($this->child(parent: $shape, localName: 'nvSpPr') ?? $shape), localName: 'ph');
		if ($placeholder === null) {
			return '';
		}

		return $placeholder->getAttribute('type');
	}                       

	   
                                                                                                              
   
                                                         
                                                                               
   
                                                         
    
	private function relationshipAttribute(?DOMElement $element, array $localNames): string {
		if ($element === null) {
			return '';
		}

		foreach ($localNames as $localName) {
			foreach ($element->attributes as $attribute) {
				if ($attribute->localName === $localName && str_ends_with((string)$attribute->namespaceURI, '/relationships') === true) {
					return (string)$attribute->value;
				}
			}
		}

		return '';
	}                             

	   
                                                              
   
                                         
                                            
   
                           
    
	private function child(DOMElement $parent, string $localName): ?DOMElement {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === $localName) {
				return $child;
			}
		}

		return null;
	}             

	   
                                                            
   
                                                                                   
                                            
   
                           
    
	private function firstDescendant(DOMDocument|DOMElement $element, string $localName): ?DOMElement {
		$found = $element->getElementsByTagNameNS('*', $localName)->item(0);
		if ($found instanceof DOMElement) {
			return $found;
		}

		return null;
	}                       
}           
