# SOLR Management Dashboard

The SOLR Management Dashboard provides a comprehensive interface for monitoring and managing your SOLR search index through the OpenRegister Settings page.

## Accessing the Dashboard

1. Navigate to **Settings** in your OpenRegister application
2. Scroll to the **SOLR Search Management** section
3. The dashboard will automatically load current statistics

## Dashboard Overview

The dashboard displays real-time information about your SOLR instance:

### Connection Status
- **Connected**: SOLR is available and responding
- **Disconnected**: SOLR is unavailable or misconfigured
- **Error**: Specific error messages for troubleshooting

### Statistics Display
- **Total Documents**: Number of objects indexed in SOLR (e.g., 13,489)
- **Active Collection**: Current tenant-specific collection name
- **Tenant ID**: Your unique tenant identifier

## Operations

### Warmup Index

The Warmup Index operation prepares your SOLR index for optimal performance by pre-loading data and schemas.

#### Configuration Options

**Execution Mode**:
- **Serial Mode**: Processes objects one by one (safer, slower)
- **Parallel Mode**: Processes multiple objects simultaneously (faster, more resource intensive)

**Processing Limits**:
- **Max Objects**: Set to 0 to process all objects, or specify a limit
- **Batch Size**: Number of objects processed in each batch (default: 1000)

#### Predictions
Before starting, the dashboard shows:
- **Total Objects**: Objects available for processing
- **Objects to Process**: Based on your max objects setting
- **Estimated Batches**: Number of batches required
- **Estimated Duration**: Time estimate based on your configuration

#### Starting Warmup
1. Configure your preferred settings
2. Review the predictions
3. Click **Start Warmup**
4. Monitor progress through the loading interface
5. Review detailed results when complete

#### Results
After completion, you'll see:
- **Success/Failure Status**: Overall operation result
- **Execution Time**: Total time taken
- **Objects Indexed**: Number of objects processed
- **Batches Processed**: Number of batches completed
- **Detailed Operations**: Breakdown of each operation performed

### Clear Index

The Clear Index operation removes all documents from your SOLR index.

⚠️ **Warning**: This action permanently deletes all indexed data and cannot be undone.

#### Using Clear Index
1. Click **Clear Index** button
2. Read the confirmation dialog carefully
3. Click **Clear Index** to confirm, or **Cancel** to abort
4. Wait for the operation to complete
5. The dashboard will refresh automatically

## User Interface Features

### Loading States
- **Spinners**: Displayed during operations
- **Disabled Controls**: Buttons are disabled during active operations
- **Progress Indicators**: Real-time feedback during warmup

### Error Handling
- **Clear Error Messages**: Specific information about what went wrong
- **Recovery Options**: Guidance on how to resolve issues
- **Debug Information**: Detailed logging for troubleshooting

### State Management
- **Modal Persistence**: Operations continue even if you navigate away
- **Clean Reset**: Modal states reset properly when closed
- **Proper Cleanup**: No lingering states between operations

## Best Practices

### When to Use Warmup
- After initial SOLR setup
- After adding large amounts of new data
- When search performance seems slow
- After SOLR server restarts

### Choosing Execution Mode
- **Use Serial Mode** when:
  - System resources are limited
  - Stability is more important than speed
  - Running during peak usage hours
  
- **Use Parallel Mode** when:
  - System has adequate resources
  - Speed is priority
  - Running during off-peak hours

### Batch Size Considerations
- **Smaller batches (100-500)**: Better for limited memory
- **Medium batches (1000)**: Good default for most systems
- **Larger batches (2000-5000)**: Better for high-performance systems

## Troubleshooting

### Dashboard Not Loading
1. Check SOLR connection settings
2. Verify SOLR service is running
3. Check network connectivity
4. Review error logs

### Warmup Fails
1. Check available system resources
2. Verify database connectivity
3. Try smaller batch sizes
4. Use Serial mode instead of Parallel

### Clear Index Issues
1. Ensure proper permissions
2. Check SOLR service status
3. Verify API endpoint availability

### Performance Issues
1. Monitor system resources during operations
2. Adjust batch sizes accordingly
3. Consider using Serial mode for stability
4. Schedule intensive operations during off-peak hours

## API Integration

The dashboard integrates with several API endpoints:

- **`/api/solr/dashboard/stats`**: Real-time SOLR statistics
- **`/api/settings/stats`**: Object count information  
- **`/api/settings/solr/warmup`**: Warmup operations
- **`/api/settings/solr/clear`**: Index clearing operations

These endpoints provide the data and functionality that powers the dashboard interface.
