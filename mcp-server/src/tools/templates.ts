import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerTemplateTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_template_sources",
    "[Enhanced] List all custom template sources (GitHub repositories providing service templates)",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const sources = await client.listTemplateSources();
        return { content: [{ type: "text", text: JSON.stringify(sources, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_template_source",
    "[Enhanced] Add a GitHub repository as a custom template source for Coolify service templates",
    {
      name: z.string().describe("Source name"),
      repository_url: z.string().describe("GitHub repository URL (e.g., 'https://github.com/org/repo')"),
      branch: z.string().optional().describe("Git branch (default: main)"),
      folder_path: z.string().optional().describe("Subfolder containing templates (default: root)"),
      github_token: z.string().optional().describe("GitHub PAT for private repositories"),
      is_enabled: z.boolean().optional().describe("Enable the source (default: true)"),
      sync_frequency: z.string().optional().describe("Cron expression for auto-sync (default: every 6 hours)"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async (params) => {
      try {
        const source = await client.createTemplateSource(params);
        return { content: [{ type: "text", text: JSON.stringify(source, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_template_source",
    "[Enhanced] Get details of a custom template source including template count and last sync time",
    { uuid: z.string().describe("Template source UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const source = await client.getTemplateSource(uuid);
        return { content: [{ type: "text", text: JSON.stringify(source, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_template_source",
    "[Enhanced] Update a custom template source's settings",
    {
      uuid: z.string().describe("Template source UUID"),
      name: z.string().optional().describe("New name"),
      repository_url: z.string().optional().describe("New repository URL"),
      branch: z.string().optional().describe("New branch"),
      folder_path: z.string().optional().describe("New folder path"),
      github_token: z.string().optional().describe("New GitHub PAT"),
      is_enabled: z.boolean().optional().describe("Enable/disable"),
      sync_frequency: z.string().optional().describe("New sync frequency"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, ...data }) => {
      try {
        const source = await client.updateTemplateSource(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(source, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_template_source",
    "[Enhanced] Delete a custom template source. Running services from this source are not affected",
    { uuid: z.string().describe("Template source UUID") },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid }) => {
      try {
        await client.deleteTemplateSource(uuid);
        return { content: [{ type: "text", text: `Template source ${uuid} deleted. Running services are not affected.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "sync_template_source",
    "[Enhanced] Trigger a sync to fetch latest templates from a source's GitHub repository",
    { uuid: z.string().describe("Template source UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.syncTemplateSource(uuid);
        return { content: [{ type: "text", text: result ? JSON.stringify(result, null, 2) : `Sync triggered for template source ${uuid}.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "sync_all_templates",
    "[Enhanced] Trigger a sync for all enabled custom template sources",
    {},
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const result = await client.syncAllTemplateSources();
        return { content: [{ type: "text", text: result ? JSON.stringify(result, null, 2) : "Sync triggered for all template sources." }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
