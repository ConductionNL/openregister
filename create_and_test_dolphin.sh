#!/bin/bash
set -e

echo "======================================="
echo "Dolphin Document Parsing - Live Test"
echo "======================================="
echo ""

# Step 1: Create test document
echo "1. Creating test invoice document..."
docker exec openregister-dolphin-vlm python3 << 'PYEOF'
from PIL import Image, ImageDraw
import os

img = Image.new('RGB', (1000, 800), color='white')
draw = ImageDraw.Draw(img)

# Title
draw.text((50, 30), 'INVOICE #12345', fill='black')
draw.text((50, 70), 'Date: November 20, 2025', fill='black')

# Company info
draw.text((50, 130), 'From: Conduction B.V.', fill='black')
draw.text((50, 160), 'Email: info@conduction.nl', fill='black')

# Table with headers
draw.rectangle([(50, 220), (950, 550)], outline='black', width=2)
draw.line([(50, 270), (950, 270)], fill='black', width=2)
draw.line([(500, 220), (500, 550)], fill='black', width=2)
draw.line([(750, 220), (750, 550)], fill='black', width=2)

draw.text((100, 235), 'Description', fill='black')
draw.text((550, 235), 'Quantity', fill='black')
draw.text((800, 235), 'Price', fill='black')

# Items
draw.line([(50, 320), (950, 320)], fill='black', width=1)
draw.text((100, 285), 'Nextcloud Development', fill='black')
draw.text((570, 285), '40 hrs', fill='black')
draw.text((800, 285), 'EUR 4,000', fill='black')

draw.line([(50, 370), (950, 370)], fill='black', width=1)
draw.text((100, 335), 'OpenRegister Module', fill='black')
draw.text((570, 335), '20 hrs', fill='black')
draw.text((800, 335), 'EUR 2,000', fill='black')

# Total
draw.line([(50, 500), (950, 500)], fill='black', width=2)
draw.text((600, 515), 'TOTAL:', fill='black')
draw.text((800, 515), 'EUR 7,000', fill='black')

# Footer
draw.text((50, 600), 'Payment terms: 30 days', fill='black')
draw.text((50, 630), 'Bank: NL12 ABNA 0123 4567 89', fill='black')

img.save('/app/test_invoice.png')
print('Test invoice created successfully')
PYEOF

echo "   ✓ Test document created"
echo ""

# Step 2: Verify file exists
echo "2. Verifying test file..."
docker exec openregister-dolphin-vlm ls -lh /app/test_invoice.png
echo ""

# Step 3: Test the API
echo "3. Testing Dolphin parsing API..."
echo "   Sending document to parser..."
echo ""

RESULT=$(docker exec openregister-dolphin-vlm curl -s -X POST \
  http://localhost:5000/parse \
  -F 'file=@/app/test_invoice.png' \
  -F 'parse_layout=true' \
  -F 'extract_tables=true')

echo "4. API Response:"
echo "-----------------------------------"
if [ -z "$RESULT" ]; then
    echo "⚠️  No response received from API"
    echo ""
    echo "Checking container logs..."
    docker logs --tail 20 openregister-dolphin-vlm
else
    echo "$RESULT" | python3 -m json.tool 2>&1 || echo "$RESULT"
fi
echo "-----------------------------------"
echo ""

# Step 4: Check if parsing worked
if echo "$RESULT" | grep -q "INVOICE\|Conduction\|EUR"; then
    echo "✅ SUCCESS! Dolphin extracted text from the document!"
    echo ""
    echo "Sample extracted content:"
    echo "$RESULT" | grep -o "INVOICE\|Conduction\|EUR [0-9,]*" | head -5
else
    echo "⚠️  Text extraction may not have worked as expected"
    echo "   Raw response length: ${#RESULT} characters"
fi

echo ""
echo "======================================="
echo "Test Complete!"
echo "======================================="

