#!/usr/bin/env python3
"""
Dolphin Document Parser API Server
Provides REST API for ByteDance Dolphin document parsing
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
from PIL import Image
import io
import base64
import sys
import os
import torch
import json
from pathlib import Path

# Add Dolphin to Python path
sys.path.insert(0, '/app/dolphin')

app = Flask(__name__)
CORS(app)

# Initialize Dolphin model (lazy loading)
dolphin_model = None
dolphin_processor = None

def load_dolphin_model():
    """Load Dolphin model on first request"""
    global dolphin_model, dolphin_processor
    
    if dolphin_model is None:
        try:
            print("Loading Dolphin model...")
            from transformers import AutoModel, AutoProcessor
            
            model_path = os.environ.get('MODEL_PATH', '/app/models')
            
            # Load processor and model
            print(f"Loading from {model_path}")
            dolphin_processor = AutoProcessor.from_pretrained(
                model_path,
                trust_remote_code=True
            )
            
            dolphin_model = AutoModel.from_pretrained(
                model_path,
                trust_remote_code=True,
                torch_dtype=torch.float16 if torch.cuda.is_available() else torch.float32
            )
            
            # Move to GPU if available
            if torch.cuda.is_available():
                dolphin_model = dolphin_model.cuda()
                print("Model loaded on GPU")
            else:
                print("Model loaded on CPU (slower)")
            
            dolphin_model.eval()
            print("Dolphin model loaded successfully")
            
        except Exception as e:
            print(f"Error loading Dolphin model: {e}")
            raise
    
    return dolphin_model, dolphin_processor

@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint"""
    return jsonify({'status': 'ok', 'service': 'dolphin-api'})

@app.route('/parse', methods=['POST'])
def parse_document():
    """
    Parse document image or PDF
    
    Request:
        - file: multipart file upload
        - OR image_base64: base64 encoded image
        - parse_layout: bool (optional, default=True)
        - extract_tables: bool (optional, default=True)
    
    Response:
        {
            "text": "extracted text",
            "layout": {...},
            "tables": [...],
            "metadata": {...}
        }
    """
    try:
        # Get image from request
        if 'file' in request.files:
            file = request.files['file']
            image = Image.open(file.stream)
        elif request.json and 'image_base64' in request.json:
            image_data = base64.b64decode(request.json['image_base64'])
            image = Image.open(io.BytesIO(image_data))
        else:
            return jsonify({'error': 'No image provided. Send file or image_base64'}), 400
        
        # Get options
        parse_layout = request.form.get('parse_layout', 'true').lower() == 'true'
        extract_tables = request.form.get('extract_tables', 'true').lower() == 'true'
        
        # Load model
        model, processor = load_dolphin_model()
        
        # Prepare image for Dolphin
        if image.mode != 'RGB':
            image = image.convert('RGB')
        
        # Run Dolphin parsing
        print(f"Processing image size: {image.size}")
        
        # Process with Dolphin
        inputs = processor(images=image, return_tensors="pt")
        
        if torch.cuda.is_available():
            inputs = {k: v.cuda() for k, v in inputs.items()}
        
        # Generate output
        with torch.no_grad():
            outputs = model.generate(
                **inputs,
                max_new_tokens=2048,
                do_sample=False
            )
        
        # Decode output
        generated_text = processor.batch_decode(outputs, skip_special_tokens=True)[0]
        
        # Parse Dolphin's JSON output
        try:
            parsed_result = json.loads(generated_text)
        except json.JSONDecodeError:
            # If not JSON, return as plain text
            parsed_result = {
                'text': generated_text,
                'layout': {'elements': [], 'reading_order': []},
                'tables': []
            }
        
        # Format result
        result = {
            'text': parsed_result.get('text', generated_text),
            'layout': parsed_result.get('layout', {
                'elements': parsed_result.get('elements', []),
                'reading_order': parsed_result.get('reading_order', [])
            }),
            'tables': parsed_result.get('tables', []),
            'metadata': {
                'model': 'Dolphin-1.5',
                'image_size': list(image.size),
                'device': 'cuda' if torch.cuda.is_available() else 'cpu'
            }
        }
        
        print(f"Parsing complete. Text length: {len(result['text'])}")
        return jsonify(result)
    
    except Exception as e:
        app.logger.error(f"Parse error: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/parse_pdf', methods=['POST'])
def parse_pdf():
    """
    Parse multi-page PDF document
    
    Request:
        - file: PDF file upload
        - pages: list of page numbers (optional, default=all)
    
    Response:
        {
            "pages": [
                {"page": 1, "text": "...", "layout": {...}},
                {"page": 2, "text": "...", "layout": {...}}
            ],
            "metadata": {...}
        }
    """
    try:
        if 'file' not in request.files:
            return jsonify({'error': 'No PDF file provided'}), 400
        
        file = request.files['file']
        
        # Save PDF temporarily
        import tempfile
        import pdf2image
        
        with tempfile.NamedTemporaryFile(suffix='.pdf', delete=False) as tmp:
            file.save(tmp.name)
            pdf_path = tmp.name
        
        try:
            # Convert PDF to images
            images = pdf2image.convert_from_path(pdf_path)
            
            model, processor = load_dolphin_model()
            
            pages_result = []
            
            for page_num, img in enumerate(images, 1):
                print(f"Processing page {page_num}/{len(images)}")
                
                # Process image with Dolphin
                inputs = processor(images=img, return_tensors="pt")
                
                if torch.cuda.is_available():
                    inputs = {k: v.cuda() for k, v in inputs.items()}
                
                with torch.no_grad():
                    outputs = model.generate(**inputs, max_new_tokens=2048, do_sample=False)
                
                generated_text = processor.batch_decode(outputs, skip_special_tokens=True)[0]
                
                try:
                    parsed = json.loads(generated_text)
                except json.JSONDecodeError:
                    parsed = {'text': generated_text, 'layout': {}}
                
                pages_result.append({
                    'page': page_num,
                    'text': parsed.get('text', generated_text),
                    'layout': parsed.get('layout', {}),
                    'tables': parsed.get('tables', [])
                })
            
            result = {
                'pages': pages_result,
                'metadata': {
                    'model': 'Dolphin-1.5',
                    'total_pages': len(images),
                    'device': 'cuda' if torch.cuda.is_available() else 'cpu'
                }
            }
            
            return jsonify(result)
            
        finally:
            # Clean up temp file
            os.unlink(pdf_path)
    
    except Exception as e:
        app.logger.error(f"PDF parse error: {str(e)}")
        return jsonify({'error': str(e)}), 500

@app.route('/info', methods=['GET'])
def info():
    """Get model information"""
    return jsonify({
        'model': 'ByteDance Dolphin-1.5',
        'version': '1.5',
        'capabilities': [
            'document_parsing',
            'layout_analysis',
            'table_extraction',
            'formula_extraction',
            'ocr'
        ],
        'model_path': '/app/models'
    })

if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=False)

