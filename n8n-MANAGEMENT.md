# n8n Workflow Management

## Quick Commands

### Ensure Workflow is Imported and Active
```bash
# Copy workflow template to container
docker cp n8n-templates/ai-powered-phpqa-fixer-complete.json openregister-n8n:/tmp/

# Run the ensure script
docker exec openregister-n8n /tmp/n8n-ensure-workflow.sh
```

### Manual Import
```bash
docker cp n8n-templates/ai-powered-phpqa-fixer-complete.json openregister-n8n:/tmp/
docker exec openregister-n8n n8n import:workflow --input=/tmp/ai-powered-phpqa-fixer-complete.json
```

### List Workflows
```bash
docker exec openregister-n8n n8n list:workflow
```

### Check Database
```bash
docker exec openregister-n8n sqlite3 /root/.n8n/database.sqlite "SELECT id, name, active FROM workflow_entity;"
```

### Activate a Workflow
```bash
WORKFLOW_ID="your-workflow-id-here"
docker exec openregister-n8n sqlite3 /root/.n8n/database.sqlite "
UPDATE workflow_entity SET active = 1 WHERE id = '$WORKFLOW_ID';
"
docker restart openregister-n8n
```

### Delete Duplicate Workflows
```bash
# Keep only the latest
docker exec openregister-n8n sqlite3 /root/.n8n/database.sqlite "
DELETE FROM workflow_entity 
WHERE name = 'AI-Powered PHPQA Auto-Fixer (Complete)' 
AND id NOT IN (
  SELECT id FROM workflow_entity 
  WHERE name = 'AI-Powered PHPQA Auto-Fixer (Complete)' 
  ORDER BY createdAt DESC 
  LIMIT 1
);
"
```

## Backup and Restore

### Backup Workflow
```bash
docker exec openregister-n8n n8n export:workflow --id=WORKFLOW_ID --output=/tmp/backup.json
docker cp openregister-n8n:/tmp/backup.json ./n8n-backups/workflow-backup-$(date +%Y%m%d-%H%M%S).json
```

### Backup Entire Database
```bash
docker cp openregister-n8n:/root/.n8n/database.sqlite ./n8n-backups/database-backup-$(date +%Y%m%d-%H%M%S).sqlite
```

### Restore Database
```bash
docker cp ./n8n-backups/database-backup-YYYYMMDD-HHMMSS.sqlite openregister-n8n:/root/.n8n/database.sqlite
docker restart openregister-n8n
```

## Troubleshooting

### Workflow Not Visible in UI

1. **Check if it exists in database**:
   ```bash
   docker exec openregister-n8n sqlite3 /root/.n8n/database.sqlite "SELECT id, name, active FROM workflow_entity;"
   ```

2. **Check if it's assigned to your project**:
   ```bash
   docker exec openregister-n8n sqlite3 /root/.n8n/database.sqlite "SELECT * FROM shared_workflow;"
   ```

3. **Re-assign to project**:
   ```bash
   WORKFLOW_ID="your-id"
   PROJECT_ID="nZ70rwLC4cbgAwCw"
   docker exec openregister-n8n sqlite3 /root/.n8n/database.sqlite "
   UPDATE workflow_entity SET parentFolderId = '$PROJECT_ID' WHERE id = '$WORKFLOW_ID';
   INSERT OR IGNORE INTO shared_workflow (workflowId, projectId, role) VALUES ('$WORKFLOW_ID', '$PROJECT_ID', 'workflow:owner');
   "
   docker restart openregister-n8n
   ```

4. **Clear browser cache**: Ctrl+Shift+R or Cmd+Shift+R

### Database Lost After Restart

**Issue**: The n8n volume is not persisting correctly.

**Solution**: The volume is defined in `docker-compose.yml` and should persist. If it's getting cleared:

1. Check volume exists:
   ```bash
   docker volume ls | grep n8n
   ```

2. Check volume mount:
   ```bash
   docker inspect openregister-n8n | grep -A 10 "Mounts"
   ```

3. The volume should be mounted to `/root/.n8n` (because n8n runs as root for Docker socket access)

### Error: "Cannot read properties of null (reading 'id')"

**Issue**: User authentication issue or project not properly set up.

**Solution**: 
1. Log out and log back in
2. Clear browser cache
3. Check the workflow is assigned to your project (see above)

## Best Practices

1. **Always backup before major changes**:
   ```bash
   docker cp openregister-n8n:/root/.n8n/database.sqlite ./backup-$(date +%Y%m%d).sqlite
   ```

2. **Keep workflow template files** in `n8n-templates/` directory

3. **Use the ensure script** after any n8n container recreation

4. **Check logs** if workflow doesn't activate:
   ```bash
   docker logs openregister-n8n | grep -i "workflow\|error"
   ```

## Volume Persistence

The n8n data persists in a Docker volume:

```yaml
volumes:
  n8n:  # Named volume

services:
  n8n:
    volumes:
      - n8n:/root/.n8n  # Volume mounted to /root/.n8n
```

To verify the volume:
```bash
# List volumes
docker volume ls | grep openregister

# Inspect volume
docker volume inspect openregister_n8n
```

The data location on host (Docker Desktop/WSL2):
```bash
# WSL2
docker volume inspect openregister_n8n | grep Mountpoint

# Usually: /var/lib/docker/volumes/openregister_n8n/_data
```

