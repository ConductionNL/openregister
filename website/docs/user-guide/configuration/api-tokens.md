# API Tokens for Configuration Discovery

API tokens allow OpenRegister to discover and import configurations from GitHub and GitLab repositories. This guide explains how to obtain and configure these tokens.

## Overview

The Configuration Discovery feature enables you to search for and import OpenRegister configurations shared by the community. To use this feature, you need to configure API tokens for:

- **GitHub** - Access GitHub's Code Search API
- **GitLab** - Access GitLab's Global Search API

:::info
API tokens are optional. You can still manually import configurations using direct URLs without configuring tokens.
:::

## GitHub Personal Access Token

### Creating a GitHub Token

1. **Log in to GitHub**
   - Navigate to [github.com](https://github.com) and sign in

2. **Access Token Settings**
   - Click your profile picture in the top-right corner
   - Select 'Settings' from the dropdown menu
   - Scroll down the left sidebar and click 'Developer settings'
   - Click 'Personal access tokens' → 'Tokens (classic)'

3. **Generate New Token**
   - Click 'Generate new token' → 'Generate new token (classic)'
   - Give your token a descriptive name (e.g., 'OpenRegister Configuration Discovery')
   - Set an expiration date (recommended: 90 days or custom)

4. **Select Scopes**
   - Check the `repo` scope
   - This grants access to the Code Search API which is required

5. **Generate and Copy**
   - Click 'Generate token' at the bottom
   - **Important:** Copy the token immediately - you won't be able to see it again!
   - The token will look like: `ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`

### Token Format

GitHub Personal Access Tokens start with:
- `ghp_` for personal access tokens
- `github_pat_` for fine-grained personal access tokens

### Required Permissions

| Scope | Why Needed |
|-------|------------|
| `repo` | Access the Code Search API to find OpenRegister configuration files |

:::warning Security Note
Never share your GitHub token. Treat it like a password. If compromised, revoke it immediately and generate a new one.
:::

## GitLab Personal Access Token

### Creating a GitLab Token

1. **Log in to GitLab**
   - Navigate to [gitlab.com](https://gitlab.com) and sign in
   - For self-hosted instances, use your GitLab URL

2. **Access Token Settings**
   - Click your profile picture in the top-right corner
   - Select 'Edit profile' or 'Preferences'
   - In the left sidebar, click 'Access Tokens'

3. **Create New Token**
   - Enter a token name (e.g., 'OpenRegister Configuration Discovery')
   - Set an expiration date (optional but recommended)
   - Select the `read_api` scope

4. **Generate Token**
   - Click 'Create personal access token'
   - **Important:** Copy the token immediately - you won't be able to see it again!
   - The token will look like: `glpat-xxxxxxxxxxxxxxxxxxxx`

### Token Format

GitLab Personal Access Tokens start with:
- `glpat-` for personal access tokens

### Required Permissions

| Scope | Why Needed |
|-------|------------|
| `read_api` | Access GitLab's Global Search API to find OpenRegister configuration files |

### Self-Hosted GitLab Instances

If you're using a self-hosted GitLab instance:

1. Follow the same token creation process on your GitLab instance
2. In OpenRegister settings, configure the 'GitLab Instance URL' field
3. Enter your GitLab API base URL (e.g., `https://gitlab.yourcompany.com/api/v4`)
4. Leave this field empty if using GitLab.com

## Configuring Tokens in OpenRegister

### Step 1: Navigate to Settings

1. Open your Nextcloud instance
2. Go to **Settings** → **Administration** → **OpenRegister**
3. Scroll to the **API Token Configuration** section

### Step 2: Enter Your Tokens

#### GitHub Token

1. Paste your GitHub Personal Access Token in the 'GitHub Token' field
2. Click 'Save GitHub Token'
3. You should see a success message

#### GitLab Token

1. Paste your GitLab Personal Access Token in the 'GitLab Token' field
2. (Optional) If using self-hosted GitLab, enter your GitLab API URL
3. Click 'Save GitLab Token'
4. You should see a success message

### Step 3: Test the Configuration

1. Navigate to **Configurations** in OpenRegister
2. Click '+ Import Configuration'
3. Go to the 'Discover' tab
4. Try searching for configurations:
   - Click 'Search GitHub' to test GitHub integration
   - Click 'Search GitLab' to test GitLab integration

If configured correctly, you should see search results from the respective platforms.

## Security Best Practices

### Token Management

- **Rotate Regularly**: Change your tokens every 90 days
- **Use Minimal Permissions**: Only grant the required scopes
- **Revoke Unused Tokens**: Delete tokens you're no longer using
- **Monitor Usage**: Check token activity in GitHub/GitLab settings

### Storage

- Tokens are stored encrypted in your Nextcloud database
- Only administrators can view and modify API tokens
- Tokens are masked in the UI (only first/last 4 characters visible)

### Revoking Tokens

If a token is compromised:

1. **GitHub**: Settings → Developer settings → Personal access tokens → Revoke
2. **GitLab**: Preferences → Access Tokens → Revoke
3. **OpenRegister**: Click 'Clear Token' to remove from OpenRegister
4. Generate a new token and reconfigure

## Troubleshooting

### "401 Unauthorized" Errors

If you see unauthorized errors when discovering configurations:

1. **Verify Token is Correct**
   - Re-enter the token in settings
   - Ensure you copied the complete token

2. **Check Token Permissions**
   - GitHub: Verify `repo` scope is enabled
   - GitLab: Verify `read_api` scope is enabled

3. **Token Expiration**
   - Check if your token has expired
   - Generate a new token if necessary

4. **Rate Limiting**
   - GitHub allows 30 code searches per minute
   - Wait a few minutes and try again

### No Search Results

If searches return no results:

1. **Try Different Search Terms**
   - Leave empty to browse all OpenRegister configurations
   - Use specific keywords to narrow results

2. **Check Token Configuration**
   - Ensure token is properly saved
   - Test with the opposite platform (GitHub vs GitLab)

3. **Verify Platform Access**
   - Confirm you can access the platform directly
   - Check for service outages

### Self-Hosted GitLab Issues

If using self-hosted GitLab:

1. **Verify URL Format**
   - Must include `/api/v4` (e.g., `https://gitlab.company.com/api/v4`)
   - Use HTTPS if possible

2. **Network Access**
   - Ensure your Nextcloud server can reach your GitLab instance
   - Check firewall rules

3. **Certificate Issues**
   - For self-signed certificates, you may need to configure certificate trust

## API Rate Limits

Be aware of rate limits for API calls:

### GitHub

- **Authenticated**: 5,000 requests per hour
- **Code Search**: 30 requests per minute
- **Unauthenticated**: 60 requests per hour (not usable for code search)

### GitLab

- **Authenticated**: 2,000 requests per minute (GitLab.com)
- **Self-Hosted**: Configurable by administrator

Rate limit headers are returned with each API response. OpenRegister respects these limits automatically.

## Further Reading

- [GitHub Personal Access Tokens Documentation](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token)
- [GitLab Personal Access Tokens Documentation](https://docs.gitlab.com/ee/user/profile/personal_access_tokens.html)
- [OpenRegister Configuration Guide](../configuration/)
- [GitHub Code Search API](https://docs.github.com/en/rest/search#search-code)
- [GitLab Search API](https://docs.gitlab.com/ee/api/search.html)

## Support

If you encounter issues:

1. Check this documentation for troubleshooting steps
2. Review the [OpenRegister FAQ](../../faq)
3. Contact your system administrator
4. Report bugs on [GitHub Issues](https://github.com/ConductionNL/openregister/issues)

