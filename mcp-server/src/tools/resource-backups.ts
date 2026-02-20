import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

const backupTypeSchema = z
  .enum(["volume", "configuration", "full", "coolify_instance"])
  .describe("Backup type: volume (Docker volumes), configuration (settings/envvars), full (both), coolify_instance (full Coolify installation)");

export function registerResourceBackupTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_resource_backups",
    "[Enhanced] List all resource backup schedules for the current team",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const backups = await client.listResourceBackups();
        return { content: [{ type: "text", text: JSON.stringify(backups, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_resource_backup",
    "[Enhanced] Create a backup schedule for a resource (application, service, or database volumes/config)",
    {
      resource_type: z.string().describe("Resource type (e.g., 'application', 'service', 'standalone-postgresql')"),
      resource_id: z.number().describe("Resource ID"),
      backup_type: backupTypeSchema,
      frequency: z.string().describe("Cron expression (e.g., '0 2 * * *' for daily at 2am)"),
      enabled: z.boolean().optional().describe("Enable the schedule (default: true)"),
      save_s3: z.boolean().optional().describe("Upload to S3"),
      s3_storage_id: z.number().optional().describe("S3 storage destination ID"),
      number_of_backups_locally: z.number().optional().describe("Local retention count"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async (params) => {
      try {
        const backup = await client.createResourceBackup(params);
        return { content: [{ type: "text", text: JSON.stringify(backup, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_resource_backup",
    "[Enhanced] Get details of a resource backup schedule including execution history",
    { uuid: z.string().describe("Backup schedule UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const backup = await client.getResourceBackup(uuid);
        return { content: [{ type: "text", text: JSON.stringify(backup, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "trigger_resource_backup",
    "[Enhanced] Trigger an immediate backup for a resource backup schedule",
    { uuid: z.string().describe("Backup schedule UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const result = await client.triggerResourceBackup(uuid);
        return { content: [{ type: "text", text: result ? JSON.stringify(result, null, 2) : `Backup triggered for schedule ${uuid}.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_resource_backup",
    "[Enhanced] Delete a resource backup schedule",
    { uuid: z.string().describe("Backup schedule UUID") },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid }) => {
      try {
        await client.deleteResourceBackup(uuid);
        return { content: [{ type: "text", text: `Resource backup schedule ${uuid} deleted.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
