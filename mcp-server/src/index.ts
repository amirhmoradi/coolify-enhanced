// ============================================================
// Coolify Enhanced MCP Server — Entry Point
// ============================================================
// Connects to a Coolify instance via its REST API and exposes
// all management operations as MCP tools for AI assistants.
// ============================================================

import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { CoolifyClient } from "./lib/coolify-client.js";
import { createMcpServer } from "./lib/mcp-server.js";

async function main(): Promise<void> {
  const baseUrl = process.env.COOLIFY_BASE_URL;
  const accessToken = process.env.COOLIFY_ACCESS_TOKEN;

  if (!baseUrl) {
    console.error("Error: COOLIFY_BASE_URL environment variable is required.");
    console.error("Set it to your Coolify instance URL (e.g., https://coolify.example.com)");
    process.exit(1);
  }

  if (!accessToken) {
    console.error("Error: COOLIFY_ACCESS_TOKEN environment variable is required.");
    console.error("Create an API token in Coolify: Settings → Keys & Tokens → API tokens");
    process.exit(1);
  }

  const client = new CoolifyClient({
    baseUrl: baseUrl.replace(/\/+$/, ""),
    accessToken,
    timeout: Number(process.env.COOLIFY_MCP_TIMEOUT) || 30_000,
    retries: Number(process.env.COOLIFY_MCP_RETRIES) || 3,
  });

  // Determine if coolify-enhanced features are available
  let enhanced = process.env.COOLIFY_ENHANCED === "true";

  if (!enhanced) {
    try {
      enhanced = await client.isEnhanced();
      if (enhanced) {
        console.error("Auto-detected coolify-enhanced addon. Enhanced tools enabled.");
      }
    } catch {
      // Assume standard Coolify
    }
  } else {
    console.error("Enhanced mode enabled via COOLIFY_ENHANCED=true.");
  }

  const server = createMcpServer({ client, enhanced });
  const transport = new StdioServerTransport();
  await server.connect(transport);

  console.error(
    `Coolify ${enhanced ? "Enhanced " : ""}MCP Server running. ` +
    `Connected to ${baseUrl}. ` +
    `${enhanced ? "Enhanced tools available." : "Core tools only."}`
  );
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
