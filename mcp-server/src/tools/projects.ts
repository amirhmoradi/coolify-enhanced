import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerProjectTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_projects",
    "List all Coolify projects with their environments",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const projects = await client.listProjects();
        return { content: [{ type: "text", text: JSON.stringify(projects, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_project",
    "Get detailed information about a specific project including its environments",
    { uuid: z.string().describe("Project UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const project = await client.getProject(uuid);
        return { content: [{ type: "text", text: JSON.stringify(project, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_project",
    "Create a new project in Coolify",
    {
      name: z.string().describe("Project name"),
      description: z.string().optional().describe("Project description"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async (params) => {
      try {
        const project = await client.createProject(params);
        return { content: [{ type: "text", text: JSON.stringify(project, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "update_project",
    "Update an existing project's name or description",
    {
      uuid: z.string().describe("Project UUID"),
      name: z.string().optional().describe("New project name"),
      description: z.string().optional().describe("New description"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, ...data }) => {
      try {
        const project = await client.updateProject(uuid, data);
        return { content: [{ type: "text", text: JSON.stringify(project, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_project",
    "Delete a project and all its environments and resources",
    { uuid: z.string().describe("Project UUID") },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid }) => {
      try {
        await client.deleteProject(uuid);
        return { content: [{ type: "text", text: `Project ${uuid} deleted successfully.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "list_environments",
    "List all environments in a project",
    { project_uuid: z.string().describe("Project UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ project_uuid }) => {
      try {
        const envs = await client.listEnvironments(project_uuid);
        return { content: [{ type: "text", text: JSON.stringify(envs, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_environment",
    "Get details of a specific environment within a project",
    {
      project_uuid: z.string().describe("Project UUID"),
      environment_name: z.string().describe("Environment name or UUID"),
    },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ project_uuid, environment_name }) => {
      try {
        const env = await client.getEnvironment(project_uuid, environment_name);
        return { content: [{ type: "text", text: JSON.stringify(env, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_environment",
    "Create a new environment in a project",
    {
      project_uuid: z.string().describe("Project UUID"),
      name: z.string().describe("Environment name"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ project_uuid, name }) => {
      try {
        const env = await client.createEnvironment(project_uuid, { name });
        return { content: [{ type: "text", text: JSON.stringify(env, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_environment",
    "Delete an environment from a project. The environment must be empty (no resources)",
    {
      project_uuid: z.string().describe("Project UUID"),
      environment_name: z.string().describe("Environment name or UUID"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ project_uuid, environment_name }) => {
      try {
        await client.deleteEnvironment(project_uuid, environment_name);
        return { content: [{ type: "text", text: `Environment '${environment_name}' deleted successfully.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
