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

def _load_model(model_path):
    """Load the Dolphin model into the module global, on the GPU when there is one"""
    global dolphin_model
    from transformers import VisionEncoderDecoderModel

    dolphin_model = VisionEncoderDecoderModel.from_pretrained(
        model_path,
        trust_remote_code=True
    )
    if torch.cuda.is_available():
        dolphin_model = dolphin_model.cuda()
        print("Model loaded on GPU")
    else:
        print("Model loaded on CPU (slower)")
    dolphin_model.eval()

def _load_processor(model_path):
    """Load the Dolphin processor into the module global"""
    global dolphin_processor
    from transformers import AutoProcessor

    print(f"Loading from {model_path}")
    dolphin_processor = AutoProcessor.from_pretrained(
        model_path,
        trust_remote_code=True
    )

def load_dolphin_model():
    """Load Dolphin model on first request"""
    if dolphin_model is None:
        try:
            print("Loading Dolphin model...")
            model_path = os.environ.get('MODEL_PATH', '/app/models')
            _load_processor(model_path)
            _load_model(model_path)
            print("Dolphin model loaded successfully")
            
        except Exception as e:
            print(f"Error loading Dolphin model: {e}")
            raise
    
    return dolphin_model, dolphin_processor

def _device():
    """The device Dolphin runs on"""
    return 'cuda' if torch.cuda.is_available() else 'cpu'

def _run_dolphin(dolphin, image):
    """Run Dolphin, given as (model, processor), on one image and return the decoded text"""
    model, processor = dolphin
    inputs = processor(images=image, return_tensors="pt")
    if torch.cuda.is_available():
        inputs = {k: v.cuda() for k, v in inputs.items()}
    with torch.no_grad():
        outputs = model.generate(**inputs, max_new_tokens=2048, do_sample=False)
    return processor.batch_decode(outputs, skip_special_tokens=True)[0]

def _parsed(generated_text, fallback):
    """Dolphin's output read as JSON, or the fallback when it is plain text"""
    try:
        return json.loads(generated_text)
    except json.JSONDecodeError:
        return fallback

@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint"""
    return jsonify({'status': 'ok', 'service': 'dolphin-api'})

def _request_image():
    """The image sent as a file upload or as base64, or None when there is none"""
    if 'file' in request.files:
        file = request.files['file']
        # Read file content into memory to avoid tempfile issues
        file_bytes = file.read()
        return Image.open(io.BytesIO(file_bytes))
    if request.json and 'image_base64' in request.json:
        image_data = base64.b64decode(request.json['image_base64'])
        return Image.open(io.BytesIO(image_data))
    return None

def _document_result(parsed_result, generated_text):
    """The text, layout and tables of a parsed document"""
    return {
        'text': parsed_result.get('text', generated_text),
        'layout': parsed_result.get('layout', {
            'elements': parsed_result.get('elements', []),
            'reading_order': parsed_result.get('reading_order', [])
        }),
        'tables': parsed_result.get('tables', []),
    }

def _parse_image(image):
    """Parse one document image with Dolphin"""
    dolphin = load_dolphin_model()
    if image.mode != 'RGB':
        image = image.convert('RGB')
    print(f"Processing image size: {image.size}")
    generated_text = _run_dolphin(dolphin, image)
    plain = {'text': generated_text, 'layout': {'elements': [], 'reading_order': []}, 'tables': []}
    result = _document_result(_parsed(generated_text, plain), generated_text)
    result['metadata'] = {'model': 'Dolphin-1.5', 'image_size': list(image.size), 'device': _device()}
    return result

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
        image = _request_image()
        if image is None:
            return jsonify({'error': 'No image provided. Send file or image_base64'}), 400
        result = _parse_image(image)
        print(f"Parsing complete. Text length: {len(result['text'])}")
        return jsonify(result)
    
    except Exception:
        app.logger.exception("Parse error")
        return jsonify({'error': 'Document parsing failed'}), 500

def _save_upload(file):
    """Save an uploaded PDF to a temporary file and return its path"""
    import tempfile

    with tempfile.NamedTemporaryFile(suffix='.pdf', delete=False) as tmp:
        file.save(tmp.name)
        return tmp.name

def _page_result(page_num, generated_text):
    """The text, layout and tables of one parsed PDF page"""
    parsed = _parsed(generated_text, {'text': generated_text, 'layout': {}})
    return {
        'page': page_num,
        'text': parsed.get('text', generated_text),
        'layout': parsed.get('layout', {}),
        'tables': parsed.get('tables', [])
    }

def _parse_pdf_pages(pdf_path):
    """Parse every page of a PDF with Dolphin"""
    import pdf2image

    images = pdf2image.convert_from_path(pdf_path)
    dolphin = load_dolphin_model()
    pages_result = []
    for page_num, img in enumerate(images, 1):
        print(f"Processing page {page_num}/{len(images)}")
        pages_result.append(_page_result(page_num, _run_dolphin(dolphin, img)))
    return {
        'pages': pages_result,
        'metadata': {'model': 'Dolphin-1.5', 'total_pages': len(images), 'device': _device()}
    }

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
        pdf_path = _save_upload(request.files['file'])
        try:
            return jsonify(_parse_pdf_pages(pdf_path))
        finally:
            # Clean up temp file
            os.unlink(pdf_path)
    
    except Exception:
        app.logger.exception("PDF parse error")
        return jsonify({'error': 'PDF parsing failed'}), 500

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

