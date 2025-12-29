"""
AI Code Fixing Support Module for Container API.

This module adds PHPCS parsing and file manipulation endpoints
to support AI-powered code fixing workflows.
"""
import json
import subprocess
import re
from typing import Dict, Any, List


def process_phpcs_result(result: subprocess.CompletedProcess) -> Dict[str, Any]:
    """
    Post-process PHPCS results to extract detailed issues.
    
    Parses PHPCS JSON output to get file-by-file issues that can be
    sent to an LLM for fixing.
    
    :param result: The completed subprocess result.
    :return: Structured PHPCS issues for AI processing.
    """
    try:
        # Run PHPCS with JSON output.
        json_result = subprocess.run(
            [
                'docker', 'exec', 'master-nextcloud-1', 'bash', '-c',
                'cd /var/www/html/apps-extra/openregister && '
                './vendor/bin/phpcs --report=json --standard=PSR12 lib/ 2>&1'
            ],
            capture_output=True,
            text=True,
            timeout=60
        )
        
        # Parse PHPCS JSON.
        phpcs_data = json.loads(json_result.stdout)
        
        # Extract issues per file.
        issues_by_file = []
        total_errors = 0
        total_warnings = 0
        
        for filepath, file_data in phpcs_data.get('files', {}).items():
            if file_data['errors'] > 0 or file_data['warnings'] > 0:
                # Get the messages for this file.
                messages = file_data.get('messages', [])
                
                # Group by line for easier fixing.
                issues_by_line = {}
                for msg in messages:
                    line = msg['line']
                    if line not in issues_by_line:
                        issues_by_line[line] = []
                    
                    issues_by_line[line].append({
                        'column': msg['column'],
                        'type': msg['type'],
                        'severity': msg['severity'],
                        'message': msg['message'],
                        'source': msg['source'],
                        'fixable': msg.get('fixable', False)
                    })
                
                issues_by_file.append({
                    'file': filepath,
                    'relative_path': filepath.replace('/var/www/html/apps-extra/openregister/', ''),
                    'errors': file_data['errors'],
                    'warnings': file_data['warnings'],
                    'fixable': file_data['fixable'],
                    'issues_by_line': issues_by_line
                })
                
                total_errors += file_data['errors']
                total_warnings += file_data['warnings']
        
        return {
            "phpcs_issues": issues_by_file,
            "totals": {
                "files_with_issues": len(issues_by_file),
                "total_errors": total_errors,
                "total_warnings": total_warnings
            },
            "ready_for_ai_fixing": len(issues_by_file) > 0
        }
        
    except (json.JSONDecodeError, subprocess.TimeoutExpired, Exception) as e:
        return {
            "phpcs_issues": [],
            "error": f"Failed to parse PHPCS output: {str(e)}"
        }


# Command configurations for AI-powered code fixing.
AI_COMMANDS = {
    'phpcs-detailed': {
        'name': 'phpcs-detailed',
        'command': './vendor/bin/phpcs --report=json --standard=PSR12 lib/',
        'timeout': 60,
        'description': 'Run PHPCS and get detailed issues per file for AI fixing',
        'post_processor': process_phpcs_result
    },
    
    'read-file': {
        'name': 'read-file',
        'command': None,  # Special handling needed.
        'timeout': 10,
        'description': 'Read a file from the container (use POST body: {"file": "path/to/file.php"})',
        'handler': 'read_file_handler'
    },
    
    'write-file': {
        'name': 'write-file',
        'command': None,  # Special handling needed.
        'timeout': 10,
        'description': 'Write content to a file (use POST body: {"file": "path", "content": "..."})',
        'handler': 'write_file_handler'
    },
    
    'backup-file': {
        'name': 'backup-file',
        'command': None,  # Special handling.
        'timeout': 10,
        'description': 'Create a backup of a file before AI fixes',
        'handler': 'backup_file_handler'
    }
}


def read_file_handler(request_body: Dict[str, Any]) -> Dict[str, Any]:
    """
    Read a file from the container.
    
    :param request_body: Should contain {"file": "relative/path/to/file.php"}
    :return: File content and metadata.
    """
    if 'file' not in request_body:
        return {"error": "Missing 'file' parameter in request body"}
    
    file_path = request_body['file']
    
    # Security: prevent path traversal.
    if '..' in file_path or file_path.startswith('/'):
        return {"error": "Invalid file path"}
    
    try:
        result = subprocess.run(
            [
                'docker', 'exec', 'master-nextcloud-1', 'bash', '-c',
                f'cat /var/www/html/apps-extra/openregister/{file_path}'
            ],
            capture_output=True,
            text=True,
            timeout=10
        )
        
        if result.returncode != 0:
            return {
                "error": "File not found or not readable",
                "stderr": result.stderr
            }
        
        return {
            "file": file_path,
            "content": result.stdout,
            "size": len(result.stdout),
            "lines": len(result.stdout.split('\n'))
        }
        
    except Exception as e:
        return {"error": str(e)}


def write_file_handler(request_body: Dict[str, Any]) -> Dict[str, Any]:
    """
    Write content to a file in the container.
    
    :param request_body: Should contain {"file": "path", "content": "..."}
    :return: Write status.
    """
    if 'file' not in request_body or 'content' not in request_body:
        return {"error": "Missing 'file' or 'content' parameter"}
    
    file_path = request_body['file']
    content = request_body['content']
    
    # Security: prevent path traversal.
    if '..' in file_path or file_path.startswith('/'):
        return {"error": "Invalid file path"}
    
    try:
        # Write content to temp file, then move it.
        # Using heredoc for safe content transfer.
        result = subprocess.run(
            [
                'docker', 'exec', 'master-nextcloud-1', 'bash', '-c',
                f'cat > /var/www/html/apps-extra/openregister/{file_path} << \'EOF\'\n{content}\nEOF'
            ],
            capture_output=True,
            text=True,
            timeout=10
        )
        
        if result.returncode != 0:
            return {
                "error": "Failed to write file",
                "stderr": result.stderr
            }
        
        return {
            "file": file_path,
            "bytes_written": len(content),
            "lines_written": len(content.split('\n')),
            "status": "success"
        }
        
    except Exception as e:
        return {"error": str(e)}


def backup_file_handler(request_body: Dict[str, Any]) -> Dict[str, Any]:
    """
    Create a backup of a file before AI fixes it.
    
    :param request_body: Should contain {"file": "relative/path/to/file.php"}
    :return: Backup status and location.
    """
    if 'file' not in request_body:
        return {"error": "Missing 'file' parameter"}
    
    file_path = request_body['file']
    
    # Security check.
    if '..' in file_path or file_path.startswith('/'):
        return {"error": "Invalid file path"}
    
    try:
        from datetime import datetime
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_path = f"{file_path}.backup_{timestamp}"
        
        result = subprocess.run(
            [
                'docker', 'exec', 'master-nextcloud-1', 'bash', '-c',
                f'cp /var/www/html/apps-extra/openregister/{file_path} '
                f'/var/www/html/apps-extra/openregister/{backup_path}'
            ],
            capture_output=True,
            text=True,
            timeout=10
        )
        
        if result.returncode != 0:
            return {
                "error": "Failed to create backup",
                "stderr": result.stderr
            }
        
        return {
            "original_file": file_path,
            "backup_file": backup_path,
            "timestamp": timestamp,
            "status": "success"
        }
        
    except Exception as e:
        return {"error": str(e)}

