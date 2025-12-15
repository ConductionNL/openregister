#!/bin/bash
echo "=== HANDLER WIRING VERIFICATION ==="
echo ""
echo "FileService Handlers:"
grep "private readonly.*Handler" lib/Service/FileService.php | grep -o "\w*Handler" | sort
echo ""
echo "ChatService Handlers:"
grep "private readonly.*Handler" lib/Service/ChatService.php | grep -o "\w*Handler" | sort  
echo ""
echo "ObjectService Handlers:"
grep "private readonly.*Handler" lib/Service/ObjectService.php | grep -o "\w*Handler" | sort
echo ""
echo "ConfigurationService Handlers:"
grep "private readonly.*Handler" lib/Service/ConfigurationService.php | grep -o "\w*Handler" | sort
