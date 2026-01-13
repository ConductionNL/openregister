#!/usr/bin/env python3
from PIL import Image, ImageDraw, ImageFont

# Create a test document image with text and a table
img = Image.new('RGB', (800, 600), color='white')
draw = ImageDraw.Draw(img)

# Add title
draw.text((50, 50), 'Test Document for Dolphin Parser', fill='black')

# Add some text
draw.text((50, 120), 'This is a sample document to test the advanced', fill='black')
draw.text((50, 150), 'document parsing capabilities of ByteDance Dolphin.', fill='black')

# Draw a simple table structure
draw.rectangle([(50, 200), (750, 400)], outline='black', width=2)
draw.line([(50, 250), (750, 250)], fill='black', width=2)  # Header row
draw.line([(400, 200), (400, 400)], fill='black', width=2)  # Middle column

# Add table content
draw.text((100, 215), 'Product', fill='black')
draw.text((450, 215), 'Price', fill='black')
draw.text((100, 280), 'Widget A', fill='black')
draw.text((450, 280), '$19.99', fill='black')
draw.text((100, 330), 'Widget B', fill='black')
draw.text((450, 330), '$29.99', fill='black')

img.save('test_document.png')
print('Test document created: test_document.png')

