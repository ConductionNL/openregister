#!/bin/bash
# Extract method from ChatService.php by line range
FILE="lib/Service/ChatService.php"
START=$1
END=$2
sed -n "${START},${END}p" "$FILE"
