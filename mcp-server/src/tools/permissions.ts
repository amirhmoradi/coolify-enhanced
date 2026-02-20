import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

const permissionLevelSchema = z
  .enum(["view_only", "deploy", "full_access"])
  .describe("Permission level: view_only (read), deploy (read + deploy), full_access (everything)");

export function registerPermissionTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_project_access",
    "[Enhanced] List all users with access to a project and their permission levels",
    { project_uuid: z.string().describe("Project UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ project_uuid }) => {
      try {
        const access = await client.listProjectAccess(project_uuid);
        return { content: [{ type: "text", text: JSON.stringify(access, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "grant_project_access",
    "[Enhanced] Grant a team member access to a project with a specific permission level",
    {
      project_uuid: z.string().describe("Project UUID"),
      user_id: z.number().describe("User ID of the team member"),
      permission_level: permissionLevelSchema,
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ project_uuid, user_id, permission_level }) => {
      try {
        const access = await client.grantProjectAccess(project_uuid, { user_id, permission_level });
        return { content: [{ type: "text", text: JSON.stringify(access, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_project_access",
    "[Enhanced] Update a user's permission level on a project",
    {
      project_uuid: z.string().describe("Project UUID"),
      user_id: z.number().describe("User ID"),
      permission_level: permissionLevelSchema,
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ project_uuid, user_id, permission_level }) => {
      try {
        const access = await client.updateProjectAccess(project_uuid, user_id, { permission_level });
        return { content: [{ type: "text", text: JSON.stringify(access, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "revoke_project_access",
    "[Enhanced] Revoke a user's access to a project",
    {
      project_uuid: z.string().describe("Project UUID"),
      user_id: z.number().describe("User ID"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ project_uuid, user_id }) => {
      try {
        await client.revokeProjectAccess(project_uuid, user_id);
        return { content: [{ type: "text", text: `Access revoked for user ${user_id} on project ${project_uuid}.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "check_user_permission",
    "[Enhanced] Check if a user has a specific permission on a project",
    {
      project_uuid: z.string().describe("Project UUID"),
      user_id: z.number().describe("User ID"),
      permission: z.enum(["view", "deploy", "manage", "delete"]).describe("Permission to check"),
    },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ project_uuid, user_id, permission }) => {
      try {
        const result = await client.checkUserPermission(project_uuid, user_id, permission);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
