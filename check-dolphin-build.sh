#!/bin/bash
# Quick status checker for Dolphin container build

echo "======================================"
echo "Dolphin Build Status Checker"
echo "======================================"
echo ""

# Check if build process is running
if ps aux | grep -q "[d]ocker-compose.*dolphin-vlm"; then
    echo "✓ Build process is RUNNING"
    echo ""
    echo "Active build processes:"
    ps aux | grep "[d]ocker-compose.*dolphin-vlm" | awk '{print "  PID: "$2" - Started at "$9}'
    echo ""
else
    echo "✗ No build process detected"
    echo ""
fi

# Check if image exists
echo "Checking for Dolphin image..."
if docker images | grep -q "dolphin"; then
    echo "✓ Dolphin image found:"
    docker images | grep "dolphin" | head -5
    echo ""
    echo "✅ BUILD COMPLETE! You can now start the container."
else
    echo "✗ Dolphin image not built yet"
    echo "   Build is still in progress..."
fi

echo ""
echo "To monitor build progress in real-time:"
echo "  cd ~/nextcloud-docker-dev/workspace/server/apps-extra/openregister"
echo "  docker-compose -f docker-compose.dev.yml --profile huggingface build dolphin-vlm"
echo ""

