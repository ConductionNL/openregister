#!/bin/bash

echo "==================================="
echo "Dolphin Advanced Parsing Test"
echo "==================================="
echo ""

# Create test image inside container
echo "1. Creating test document inside container..."
docker exec openregister-dolphin-vlm python3 << 'PYTHON_EOF'
from PIL import Image, ImageDraw
img = Image.new('RGB', (800, 600), color='white')
draw = ImageDraw.Draw(img)
draw.text((50, 50), 'Test Document - Dolphin Parser', fill='black')
draw.text((50, 120), 'This is a sample document for testing', fill='black')
draw.rectangle([(50, 200), (400, 350)], outline='black', width=2)
draw.line([(50, 250), (400, 250)], fill='black', width=2)
draw.text((100, 215), 'Product | Price', fill='black')
draw.text((100, 270), 'Widget A | $19.99', fill='black')
draw.text((100, 300), 'Widget B | $29.99', fill='black')
img.save('/app/test_doc.png')
print('✓ Test document created')
PYTHON_EOF

echo ""
echo "2. Testing document parsing..."
RESULT=$(docker exec openregister-dolphin-vlm curl -s -X POST \
  http://localhost:5000/parse \
  -F 'file=@/app/test_doc.png' \
  -F 'parse_layout=true' \
  -F 'extract_tables=true')

echo ""
echo "3. Parsing Results:"
echo "$RESULT" | python3 -m json.tool 2>&1 | head -100

echo ""
echo "4. Checking if text was extracted..."
if echo "$RESULT" | grep -q "Test Document"; then
    echo "✓ Text extraction working!"
else
    echo "⚠ Text may not have been extracted"
fi

echo ""
echo "==================================="
echo "Test Complete!"
echo "==================================="

