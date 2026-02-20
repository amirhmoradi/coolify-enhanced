import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerEnvVarTools(server: McpServer, client: CoolifyClient): void {
  // ---- Application Environment Variables ----

  server.tool(
    "list_app_envs",
    "List all environment variables for an application",
    { uuid: z.string().describe("Application UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const envs = await client.listAppEnvs(uuid);
        return { content: [{ type: "text", text: JSON.stringify(envs, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_app_env",
    "Create a new environment variable for an application",
    {
      uuid: z.string().describe("Application UUID"),
      key: z.string().describe("Variable name"),
      value: z.string().describe("Variable value"),
      is_build_time: z.boolean().optional().describe("Available during build"),
      is_preview: z.boolean().optional().describe("Available in preview deployments"),
      is_literal: z.boolean().optional().describe("Treat as literal (no variable interpolation)"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ uuid, ...data }) => {
      try {
        const env = await client.createAppEnv(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(env, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_app_env",
    "Update an existing environment variable for an application",
    {
      uuid: z.string().describe("Application UUID"),
      key: z.string().describe("Variable name"),
      value: z.string().describe("New value"),
      is_build_time: z.boolean().optional().describe("Available during build"),
      is_preview: z.boolean().optional().describe("Available in preview deployments"),
      is_literal: z.boolean().optional().describe("Treat as literal"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, ...data }) => {
      try {
        const env = await client.updateAppEnv(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(env, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "bulk_update_app_envs",
    "Bulk update multiple environment variables for an application at once",
    {
      uuid: z.string().describe("Application UUID"),
      variables: z.array(
        z.object({
          key: z.string(),
          value: z.string(),
          is_build_time: z.boolean().optional(),
          is_preview: z.boolean().optional(),
          is_literal: z.boolean().optional(),
        })
      ).describe("Array of variable objects with key, value, and optional flags"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, variables }) => {
      try {
        const result = await client.bulkUpdateAppEnvs(uuid, { variables });
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_app_env",
    "Delete an environment variable from an application",
    {
      app_uuid: z.string().describe("Application UUID"),
      env_uuid: z.string().describe("Environment variable UUID"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ app_uuid, env_uuid }) => {
      try {
        await client.deleteAppEnv(app_uuid, env_uuid);
        return { content: [{ type: "text", text: `Environment variable ${env_uuid} deleted.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  // ---- Service Environment Variables ----

  server.tool(
    "list_service_envs",
    "List all environment variables for a service",
    { uuid: z.string().describe("Service UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const envs = await client.listServiceEnvs(uuid);
        return { content: [{ type: "text", text: JSON.stringify(envs, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_service_env",
    "Create a new environment variable for a service",
    {
      uuid: z.string().describe("Service UUID"),
      key: z.string().describe("Variable name"),
      value: z.string().describe("Variable value"),
      is_build_time: z.boolean().optional().describe("Available during build"),
      is_preview: z.boolean().optional().describe("Available in preview deployments"),
      is_literal: z.boolean().optional().describe("Treat as literal"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ uuid, ...data }) => {
      try {
        const env = await client.createServiceEnv(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(env, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_service_env",
    "Update an existing environment variable for a service",
    {
      uuid: z.string().describe("Service UUID"),
      key: z.string().describe("Variable name"),
      value: z.string().describe("New value"),
      is_build_time: z.boolean().optional().describe("Available during build"),
      is_preview: z.boolean().optional().describe("Available in preview deployments"),
      is_literal: z.boolean().optional().describe("Treat as literal"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, ...data }) => {
      try {
        const env = await client.updateServiceEnv(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(env, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "bulk_update_service_envs",
    "Bulk update multiple environment variables for a service at once",
    {
      uuid: z.string().describe("Service UUID"),
      variables: z.array(
        z.object({
          key: z.string(),
          value: z.string(),
          is_build_time: z.boolean().optional(),
          is_preview: z.boolean().optional(),
          is_literal: z.boolean().optional(),
        })
      ).describe("Array of variable objects"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, variables }) => {
      try {
        const result = await client.bulkUpdateServiceEnvs(uuid, { variables });
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_service_env",
    "Delete an environment variable from a service",
    {
      service_uuid: z.string().describe("Service UUID"),
      env_uuid: z.string().describe("Environment variable UUID"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ service_uuid, env_uuid }) => {
      try {
        await client.deleteServiceEnv(service_uuid, env_uuid);
        return { content: [{ type: "text", text: `Environment variable ${env_uuid} deleted.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
