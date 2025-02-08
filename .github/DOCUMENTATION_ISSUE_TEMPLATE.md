---
title: Documentation Build Failed
assignees: rubenvdlinde
labels: documentation, bug
---

The documentation build has failed in the documentation workflow.

### Details
- Triggered by: @{{ payload.sender.login }}
- Commit: {{ sha }}
- Workflow run: {{ env.GITHUB_SERVER_URL }}/{{ env.GITHUB_REPOSITORY }}/actions/runs/{{ env.GITHUB_RUN_ID }}

### Error Information
Please check the workflow logs for detailed error information.

### Next Steps
1. Review the workflow logs
2. Check the Docusaurus build output
3. Verify documentation changes
4. Fix any identified issues
5. Push changes to trigger a new build

[View Workflow Logs]({{ env.GITHUB_SERVER_URL }}/{{ env.GITHUB_REPOSITORY }}/actions/runs/{{ env.GITHUB_RUN_ID }}) 