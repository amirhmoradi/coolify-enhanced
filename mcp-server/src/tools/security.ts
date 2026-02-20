import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerSecurityTools(server: McpServer, client: CoolifyClient): void {
  server.tool(
    "list_private_keys",
    "List all SSH private keys stored in Coolify",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const keys = await client.listPrivateKeys();
        return { content: [{ type: "text", text: JSON.stringify(keys, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_private_key",
    "Get details of a specific private key",
    { uuid: z.string().describe("Private key UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const key = await client.getPrivateKey(uuid);
        return { content: [{ type: "text", text: JSON.stringify(key, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_private_key",
    "Store a new SSH private key in Coolify for server access",
    {
      name: z.string().describe("Key name"),
      private_key: z.string().describe("SSH private key content (PEM format)"),
      description: z.string().optional().describe("Key description"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async (params) => {
      try {
        const key = await client.createPrivateKey(params);
        return { content: [{ type: "text", text: JSON.stringify(key, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_private_key",
    "Delete a private key from Coolify",
    { uuid: z.string().describe("Private key UUID") },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid }) => {
      try {
        await client.deletePrivateKey(uuid);
        return { content: [{ type: "text", text: `Private key ${uuid} deleted.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "list_teams",
    "List all teams the current user has access to",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const teams = await client.listTeams();
        return { content: [{ type: "text", text: JSON.stringify(teams, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_current_team",
    "Get the currently active team for the API token",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const team = await client.getCurrentTeam();
        return { content: [{ type: "text", text: JSON.stringify(team, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_team_members",
    "Get members of the current team with their roles",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const members = await client.getTeamMembers();
        return { content: [{ type: "text", text: JSON.stringify(members, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
