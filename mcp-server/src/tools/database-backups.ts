import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerDatabaseBackupTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_db_backups",
    "List backup configurations for a database",
    { database_uuid: z.string().describe("Database UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ database_uuid }) => {
      try {
        const backups = await client.listDbBackups(database_uuid);
        return { content: [{ type: "text", text: JSON.stringify(backups, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_db_backup",
    "Create a scheduled backup configuration for a database",
    {
      database_uuid: z.string().describe("Database UUID"),
      frequency: z.string().describe("Cron expression for backup schedule (e.g., '0 2 * * *' for daily at 2am)"),
      save_s3: z.boolean().optional().describe("Upload backups to S3"),
      s3_storage_id: z.number().optional().describe("S3 storage destination ID"),
      number_of_backups_locally: z.number().optional().describe("Number of backups to keep locally"),
      enabled: z.boolean().optional().describe("Enable the backup schedule"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ database_uuid, ...data }) => {
      try {
        const backup = await client.createDbBackup(database_uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(backup, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_db_backup",
    "Update a database backup configuration",
    {
      database_uuid: z.string().describe("Database UUID"),
      backup_uuid: z.string().describe("Backup configuration UUID"),
      frequency: z.string().optional().describe("New cron expression"),
      save_s3: z.boolean().optional().describe("Upload to S3"),
      s3_storage_id: z.number().optional().describe("S3 storage ID"),
      number_of_backups_locally: z.number().optional().describe("Local backup retention count"),
      enabled: z.boolean().optional().describe("Enable/disable schedule"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ database_uuid, backup_uuid, ...data }) => {
      try {
        const backup = await client.updateDbBackup(database_uuid, backup_uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(backup, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_db_backup",
    "Delete a database backup configuration and all its executions",
    {
      database_uuid: z.string().describe("Database UUID"),
      backup_uuid: z.string().describe("Backup configuration UUID"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ database_uuid, backup_uuid }) => {
      try {
        await client.deleteDbBackup(database_uuid, backup_uuid);
        return { content: [{ type: "text", text: `Backup configuration ${backup_uuid} deleted.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "list_db_backup_executions",
    "List all execution history for a database backup configuration",
    {
      database_uuid: z.string().describe("Database UUID"),
      backup_uuid: z.string().describe("Backup configuration UUID"),
    },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ database_uuid, backup_uuid }) => {
      try {
        const executions = await client.listDbBackupExecutions(database_uuid, backup_uuid);
        return { content: [{ type: "text", text: JSON.stringify(executions, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
