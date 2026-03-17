#!/bin/bash
echo "====================================="
echo "Dolphin Document Parsing - Final Test"
echo "====================================="
echo ""

# Step 1: Create test image inside container
echo "1. Creating test document..."
docker exec openregister-dolphin-vlm python3 /app/dolphin/demo_page.py --help > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "✓ Dolphin demo script is available"
else
    echo "⚠ Dolphin demo script not found, creating manual test image..."
    docker cp /dev/null openregister-dolphin-vlm:/app/test.txt
fi

# Create a simple test image
cat > /tmp/create_test.py << 'PYEOF'
from PIL import Image, ImageDraw
img = Image.new('RGB', (600, 400), 'white')
draw = ImageDraw.Draw(img)
draw.text((50, 50), 'Dolphin Parser Test', fill='black')
draw.text((50, 100), 'This tests document parsing', fill='black')
draw.rectangle([(50, 150), (550, 350)], outline='black', width=2)
draw.line([(50, 200), (550, 200)], fill='black', width=2)
draw.text((100, 170), 'Header 1 | Header 2', fill='black')
draw.text((100, 220), 'Data 1   | Data 2', fill='black')
img.save('/tmp/test_document.png')
print('Test image saved')
PYEOF

python3 /tmp/create_test.py 2>&1
docker cp /tmp/test_document.png openregister-dolphin-vlm:/app/test_document.png
echo "✓ Test document copied to container"
echo ""

# Step 2: Test the parsing API
echo "2. Testing document parsing API..."
RESPONSE=$(curl -s -X POST http://localhost:8083/parse \
  -F 'file=@/tmp/test_document.png' \
  -F 'parse_layout=true' \
  -F 'extract_tables=true')

echo "3. API Response:"
echo "$RESPONSE"
echo ""

# Step 4: Save results
echo "$RESPONSE" > DOLPHIN_TEST_RESULTS.md
echo ""
echo "✓ Results saved to DOLPHIN_TEST_RESULTS.md"
echo ""
echo "====================================="
echo "Test Complete!"
echo "====================================="

