import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerApplicationTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_applications",
    "List all applications across all projects and environments",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const apps = await client.listApplications();
        return { content: [{ type: "text", text: JSON.stringify(apps, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_application",
    "Get detailed information about a specific application including git, build, and deployment settings",
    { uuid: z.string().describe("Application UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const app = await client.getApplication(uuid);
        return { content: [{ type: "text", text: JSON.stringify(app, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_application",
    "Create a new application from a public git repository",
    {
      name: z.string().describe("Application name"),
      project_uuid: z.string().describe("Project UUID to deploy into"),
      server_uuid: z.string().describe("Server UUID to deploy on"),
      git_repository: z.string().describe("Public git repository URL"),
      git_branch: z.string().optional().describe("Git branch (default: main)"),
      build_pack: z
        .enum(["nixpacks", "dockerfile", "dockercompose", "dockerimage", "static"])
        .optional()
        .describe("Build pack to use"),
      environment_name: z.string().optional().describe("Environment name (default: production)"),
      description: z.string().optional().describe("Application description"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async (params) => {
      try {
        const app = await client.createPublicApplication(params);
        return { content: [{ type: "text", text: JSON.stringify(app, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_application",
    "Update application settings (name, git config, build settings, domains, etc.)",
    {
      uuid: z.string().describe("Application UUID"),
      data: z.record(z.unknown()).describe("Key-value pairs to update (e.g., name, fqdn, git_branch, build_pack)"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, data }) => {
      try {
        const app = await client.updateApplication(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(app, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_application",
    "Delete an application and optionally clean up its Docker resources",
    { uuid: z.string().describe("Application UUID") },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid }) => {
      try {
        await client.deleteApplication(uuid);
        return { content: [{ type: "text", text: `Application ${uuid} deleted successfully.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "start_application",
    "Start (deploy) an application",
    { uuid: z.string().describe("Application UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.startApplication(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "stop_application",
    "Stop a running application",
    { uuid: z.string().describe("Application UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.stopApplication(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "restart_application",
    "Restart an application (stop then start)",
    { uuid: z.string().describe("Application UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.restartApplication(uuid);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_application_logs",
    "Get logs from an application container",
    {
      uuid: z.string().describe("Application UUID"),
      since: z.string().optional().describe("Show logs since timestamp (e.g., '2024-01-01T00:00:00Z')"),
      until: z.string().optional().describe("Show logs until timestamp"),
      lines: z.number().optional().describe("Number of log lines to return"),
    },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, since, until, lines }) => {
      try {
        const logs = await client.getApplicationLogs(uuid, { since, until, lines });
        return { content: [{ type: "text", text: JSON.stringify(logs, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "deploy",
    "Deploy resources by UUID or tag. Supports deploying individual resources or all resources matching a tag",
    {
      uuid: z.string().optional().describe("Resource UUID to deploy"),
      tag: z.string().optional().describe("Deploy all resources with this tag"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async (params) => {
      try {
        const result = await client.deploy(params);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
