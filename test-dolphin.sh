#!/bin/bash
# Test script for Dolphin document parsing container

set -e

echo "====================================="
echo "Dolphin Document Parser Test Script"
echo "====================================="
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if container is running
echo "1. Checking if Dolphin container is running..."
if docker ps | grep -q openregister-dolphin-vlm; then
    echo -e "${GREEN}✓ Dolphin container is running${NC}"
else
    echo -e "${RED}✗ Dolphin container is not running${NC}"
    echo "Starting Dolphin container..."
    docker-compose -f docker-compose.huggingface.yml up -d dolphin-vlm
    echo "Waiting for container to start (30 seconds)..."
    sleep 30
fi

echo ""

# Check health endpoint
echo "2. Checking health endpoint..."
if curl -f http://localhost:8083/health 2>/dev/null; then
    echo -e "${GREEN}✓ Health endpoint responding${NC}"
else
    echo -e "${YELLOW}⚠ Waiting for model to load...${NC}"
    echo "This can take 2-5 minutes on first startup"
    for i in {1..60}; do
        sleep 5
        if curl -f http://localhost:8083/health 2>/dev/null; then
            echo -e "${GREEN}✓ Health endpoint now responding${NC}"
            break
        fi
        echo -n "."
    done
fi

echo ""

# Check model info
echo "3. Getting model information..."
curl -s http://localhost:8083/info | jq '.'

echo ""
echo ""

# Test with sample image
echo "4. Testing document parsing..."
echo ""

# Create a test image with text
echo "Creating test document image..."
convert -size 800x600 xc:white \
    -font Arial -pointsize 24 -fill black \
    -annotate +50+50 "Test Document" \
    -annotate +50+100 "This is a sample document for testing." \
    -annotate +50+150 "It contains multiple lines of text." \
    -annotate +50+250 "Table:" \
    -draw "rectangle 50,280 750,480" \
    -annotate +60+310 "Name | Age | City" \
    -annotate +60+350 "John | 30  | Amsterdam" \
    -annotate +60+390 "Jane | 25  | Rotterdam" \
    /tmp/test_document.png 2>/dev/null || echo "Note: ImageMagick not installed, skipping image creation"

if [ -f /tmp/test_document.png ]; then
    echo "Test image created: /tmp/test_document.png"
    echo ""
    echo "Sending document to Dolphin for parsing..."
    
    RESULT=$(curl -s -X POST http://localhost:8083/parse \
        -F "file=@/tmp/test_document.png" \
        -F "parse_layout=true" \
        -F "extract_tables=true")
    
    echo "Parsing result:"
    echo "$RESULT" | jq '.'
    
    # Check if parsing succeeded
    if echo "$RESULT" | jq -e '.text' > /dev/null 2>&1; then
        echo ""
        echo -e "${GREEN}✓ Document parsing successful!${NC}"
        echo ""
        echo "Extracted text:"
        echo "$RESULT" | jq -r '.text'
    else
        echo -e "${RED}✗ Parsing failed${NC}"
        echo "$RESULT"
    fi
else
    echo -e "${YELLOW}⚠ Skipping image test (ImageMagick not available)${NC}"
    echo ""
    echo "You can test manually with:"
    echo "  curl -X POST http://localhost:8083/parse -F 'file=@/path/to/document.png'"
fi

echo ""
echo ""

# Test PDF parsing (if available)
echo "5. Testing PDF parsing capability..."
echo "To test PDF parsing:"
echo "  curl -X POST http://localhost:8083/parse_pdf -F 'file=@document.pdf' | jq '.'"

echo ""
echo "====================================="
echo "Test complete!"
echo "====================================="
echo ""
echo "Dolphin API endpoints:"
echo "  - Health: http://localhost:8083/health"
echo "  - Info:   http://localhost:8083/info"
echo "  - Parse:  POST http://localhost:8083/parse"
echo "  - PDF:    POST http://localhost:8083/parse_pdf"
echo ""
echo "View logs:"
echo "  docker logs -f openregister-dolphin-vlm"
echo ""

