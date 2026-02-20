# Coolify Enhanced MCP Server

A [Model Context Protocol (MCP)](https://modelcontextprotocol.io) server that enables AI assistants to manage [Coolify](https://coolify.io) infrastructure through natural language. Works with both standard Coolify and the [coolify-enhanced](https://github.com/amirhmoradi/coolify-enhanced) addon.

## Features

- **99+ MCP tools** covering all Coolify API endpoints
- **Enhanced tools** for permissions, resource backups, custom templates, and network management (when coolify-enhanced is installed)
- **Auto-detection** of enhanced features — falls back to core tools for standard Coolify
- **Tool annotations** — read-only, destructive, and idempotent hints for AI safety
- **Retry logic** with exponential backoff for transient API failures
- **Works with** Claude Desktop, Cursor, VS Code Copilot, Kiro IDE, and any MCP-compatible client

## Quick Start

### Prerequisites

- Node.js 18+
- A Coolify instance with API enabled
- An API token (create in Coolify: Settings > Keys & Tokens > API tokens)

### Configuration

#### Claude Desktop

Add to your `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "coolify": {
      "command": "npx",
      "args": ["-y", "@amirhmoradi/coolify-enhanced-mcp"],
      "env": {
        "COOLIFY_BASE_URL": "https://coolify.example.com",
        "COOLIFY_ACCESS_TOKEN": "your-api-token"
      }
    }
  }
}
```

#### Cursor / VS Code

Add to your MCP settings:

```json
{
  "mcpServers": {
    "coolify": {
      "command": "npx",
      "args": ["-y", "@amirhmoradi/coolify-enhanced-mcp"],
      "env": {
        "COOLIFY_BASE_URL": "https://coolify.example.com",
        "COOLIFY_ACCESS_TOKEN": "your-api-token"
      }
    }
  }
}
```

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `COOLIFY_BASE_URL` | Yes | — | Coolify instance URL |
| `COOLIFY_ACCESS_TOKEN` | Yes | — | API token with read/write/deploy scopes |
| `COOLIFY_ENHANCED` | No | `false` | Force enable enhanced features |
| `COOLIFY_MCP_TIMEOUT` | No | `30000` | API request timeout in milliseconds |
| `COOLIFY_MCP_RETRIES` | No | `3` | Number of retry attempts for failed requests |

## Available Tools

### Core Tools (Standard Coolify)

#### Servers
| Tool | Description |
|------|-------------|
| `list_servers` | List all servers |
| `get_server` | Get server details |
| `get_server_resources` | Get resources on a server |
| `get_server_domains` | Get domains for a server |
| `validate_server` | Validate server connectivity |
| `create_server` | Register a new server |
| `update_server` | Update server settings |
| `delete_server` | Delete a server |

#### Projects & Environments
| Tool | Description |
|------|-------------|
| `list_projects` | List all projects |
| `get_project` | Get project details |
| `create_project` | Create a project |
| `update_project` | Update project settings |
| `delete_project` | Delete a project |
| `list_environments` | List environments in a project |
| `get_environment` | Get environment details |
| `create_environment` | Create an environment |
| `delete_environment` | Delete an environment |

#### Applications
| Tool | Description |
|------|-------------|
| `list_applications` | List all applications |
| `get_application` | Get application details |
| `create_application` | Create from public git repo |
| `update_application` | Update application settings |
| `delete_application` | Delete an application |
| `start_application` | Start/deploy |
| `stop_application` | Stop |
| `restart_application` | Restart |
| `get_application_logs` | Get container logs |
| `deploy` | Deploy by UUID or tag |

#### Databases
| Tool | Description |
|------|-------------|
| `list_databases` | List all databases |
| `get_database` | Get database details |
| `create_database` | Create (postgres, mysql, mariadb, mongodb, redis, clickhouse, dragonfly, keydb) |
| `update_database` | Update settings |
| `delete_database` | Delete with cleanup options |
| `start_database` | Start |
| `stop_database` | Stop |
| `restart_database` | Restart |

#### Services
| Tool | Description |
|------|-------------|
| `list_services` | List all services |
| `get_service` | Get service details |
| `create_service` | Create one-click or compose service |
| `update_service` | Update settings |
| `delete_service` | Delete with cleanup options |
| `start_service` | Start/deploy |
| `stop_service` | Stop |
| `restart_service` | Restart |

#### Deployments
| Tool | Description |
|------|-------------|
| `list_deployments` | List running deployments |
| `get_deployment` | Get deployment details |
| `cancel_deployment` | Cancel a deployment |
| `list_app_deployments` | Deployment history for an app |

#### Environment Variables
| Tool | Description |
|------|-------------|
| `list_app_envs` / `list_service_envs` | List env vars |
| `create_app_env` / `create_service_env` | Create env var |
| `update_app_env` / `update_service_env` | Update env var |
| `bulk_update_app_envs` / `bulk_update_service_envs` | Bulk update |
| `delete_app_env` / `delete_service_env` | Delete env var |

#### Database Backups
| Tool | Description |
|------|-------------|
| `list_db_backups` | List backup configs |
| `create_db_backup` | Create scheduled backup |
| `update_db_backup` | Update backup config |
| `delete_db_backup` | Delete backup config |
| `list_db_backup_executions` | List execution history |

#### Security & Teams
| Tool | Description |
|------|-------------|
| `list_private_keys` | List SSH keys |
| `get_private_key` | Get key details |
| `create_private_key` | Store new SSH key |
| `delete_private_key` | Delete SSH key |
| `list_teams` | List teams |
| `get_current_team` | Get current team |
| `get_team_members` | Get team members |

#### System
| Tool | Description |
|------|-------------|
| `get_version` | Coolify version |
| `health_check` | Health check |
| `list_resources` | All resources |

### Enhanced Tools (coolify-enhanced addon)

These tools are automatically available when the coolify-enhanced addon is detected.

#### Granular Permissions
| Tool | Description |
|------|-------------|
| `list_project_access` | List users with project access |
| `grant_project_access` | Grant access with permission level |
| `update_project_access` | Change permission level |
| `revoke_project_access` | Revoke access |
| `check_user_permission` | Check specific permission |

#### Resource Backups
| Tool | Description |
|------|-------------|
| `list_resource_backups` | List backup schedules |
| `create_resource_backup` | Create volume/config/full backup |
| `get_resource_backup` | Get schedule + executions |
| `trigger_resource_backup` | Trigger immediate backup |
| `delete_resource_backup` | Delete backup schedule |

#### Custom Templates
| Tool | Description |
|------|-------------|
| `list_template_sources` | List GitHub template sources |
| `create_template_source` | Add template repository |
| `get_template_source` | Get source details |
| `update_template_source` | Update source settings |
| `delete_template_source` | Remove template source |
| `sync_template_source` | Sync from GitHub |
| `sync_all_templates` | Sync all sources |

#### Network Management
| Tool | Description |
|------|-------------|
| `list_server_networks` | List managed networks |
| `create_network` | Create shared network |
| `get_network` | Get network details |
| `delete_network` | Delete network |
| `sync_networks` | Sync from Docker |
| `migrate_proxy` | Proxy isolation migration |
| `cleanup_proxy` | Cleanup old proxy networks |
| `list_resource_networks` | Networks for a resource |
| `attach_resource_network` | Attach to network |
| `detach_resource_network` | Detach from network |

## Development

```bash
cd mcp-server
npm install
npm run build
npm start
```

## License

MIT
