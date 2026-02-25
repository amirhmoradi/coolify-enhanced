import type { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import type { CoolifyClient } from "../lib/coolify-client.js";

export function registerClusterTools(server: McpServer, client: CoolifyClient): void {
  // ---- Cluster CRUD ----

  server.tool(
    "list_clusters",
    "[Enhanced] List all Docker Swarm clusters for the current team",
    {},
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async () => {
      try {
        const clusters = await client.listClusters();
        return { content: [{ type: "text", text: JSON.stringify(clusters, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_cluster",
    "[Enhanced] Get detailed information about a specific cluster including metadata and manager server",
    { uuid: z.string().describe("Cluster UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const cluster = await client.getCluster(uuid);
        return { content: [{ type: "text", text: JSON.stringify(cluster, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_cluster",
    "[Enhanced] Create a new cluster by registering a Swarm manager server",
    {
      name: z.string().describe("Cluster name"),
      manager_server_id: z.number().describe("ID of the Swarm manager server"),
      type: z.enum(["swarm", "kubernetes"]).optional().describe("Cluster type (default: swarm)"),
      description: z.string().optional().describe("Cluster description"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async (params) => {
      try {
        const cluster = await client.createCluster(params);
        return { content: [{ type: "text", text: JSON.stringify(cluster, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "delete_cluster",
    "[Enhanced] Delete a cluster record. Unlinks servers but does not destroy the actual Swarm",
    { uuid: z.string().describe("Cluster UUID") },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid }) => {
      try {
        await client.deleteCluster(uuid);
        return { content: [{ type: "text", text: `Cluster ${uuid} deleted.` }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "sync_cluster",
    "[Enhanced] Force a metadata sync from the cluster's manager server (refreshes node/service counts, status)",
    { uuid: z.string().describe("Cluster UUID") },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const cluster = await client.syncCluster(uuid);
        return { content: [{ type: "text", text: JSON.stringify(cluster, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  // ---- Nodes ----

  server.tool(
    "get_cluster_nodes",
    "[Enhanced] List all nodes in a cluster with hostname, role, status, availability, resources, and labels",
    { uuid: z.string().describe("Cluster UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const nodes = await client.getClusterNodes(uuid);
        return { content: [{ type: "text", text: JSON.stringify(nodes, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "node_action",
    "[Enhanced] Perform an action on a cluster node: drain, activate, pause, promote, demote, add-label, remove-label",
    {
      uuid: z.string().describe("Cluster UUID"),
      node_id: z.string().describe("Docker node ID"),
      action: z.enum(["drain", "activate", "pause", "promote", "demote", "add-label", "remove-label"])
        .describe("Action to perform on the node"),
      label_key: z.string().optional().describe("Label key (required for add-label/remove-label)"),
      label_value: z.string().optional().describe("Label value (for add-label)"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, node_id, action, label_key, label_value }) => {
      try {
        const result = await client.clusterNodeAction(uuid, node_id, { action, label_key, label_value });
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "remove_node",
    "[Enhanced] Remove a node from the cluster. Use force=true to remove a down/unreachable node",
    {
      uuid: z.string().describe("Cluster UUID"),
      node_id: z.string().describe("Docker node ID"),
      force: z.boolean().optional().describe("Force remove even if node is not drained"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid, node_id, force }) => {
      try {
        const result = await client.removeClusterNode(uuid, node_id, force);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  // ---- Services ----

  server.tool(
    "get_cluster_services",
    "[Enhanced] List all Swarm services in the cluster with image, replicas, and port info",
    { uuid: z.string().describe("Cluster UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const services = await client.getClusterServices(uuid);
        return { content: [{ type: "text", text: JSON.stringify(services, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_service_tasks",
    "[Enhanced] Get all tasks (containers) for a specific Swarm service with status and node assignment",
    {
      uuid: z.string().describe("Cluster UUID"),
      service_id: z.string().describe("Swarm service ID or name"),
    },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, service_id }) => {
      try {
        const tasks = await client.getClusterServiceTasks(uuid, service_id);
        return { content: [{ type: "text", text: JSON.stringify(tasks, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "scale_service",
    "[Enhanced] Scale a Swarm service to the specified number of replicas (triggers rolling update)",
    {
      uuid: z.string().describe("Cluster UUID"),
      service_id: z.string().describe("Swarm service ID or name"),
      replicas: z.number().int().min(0).max(100).describe("Desired number of replicas"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, service_id, replicas }) => {
      try {
        const result = await client.scaleClusterService(uuid, service_id, { replicas });
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "rollback_service",
    "[Enhanced] Rollback a Swarm service to its previous version",
    {
      uuid: z.string().describe("Cluster UUID"),
      service_id: z.string().describe("Swarm service ID or name"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ uuid, service_id }) => {
      try {
        const result = await client.rollbackClusterService(uuid, service_id);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  // ---- Events & Visualizer ----

  server.tool(
    "get_cluster_events",
    "[Enhanced] Get cluster events with optional filters for type, time range, and limit",
    {
      uuid: z.string().describe("Cluster UUID"),
      type: z.string().optional().describe("Filter by event type (e.g., 'node', 'service', 'container')"),
      since: z.number().optional().describe("Unix timestamp — only return events after this time"),
      until: z.number().optional().describe("Unix timestamp — only return events before this time"),
      limit: z.number().int().min(1).max(1000).optional().describe("Max events to return (default: 100, max: 1000)"),
    },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid, type, since, until, limit }) => {
      try {
        const events = await client.getClusterEvents(uuid, { type, since, until, limit });
        return { content: [{ type: "text", text: JSON.stringify(events, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "get_cluster_visualizer",
    "[Enhanced] Get visualizer data: all nodes and tasks in the cluster for topology/grid visualization",
    { uuid: z.string().describe("Cluster UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const data = await client.getClusterVisualizer(uuid);
        return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  // ---- Secrets ----

  server.tool(
    "list_secrets",
    "[Enhanced] List all Swarm secrets in the cluster (values are never returned)",
    { uuid: z.string().describe("Cluster UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const secrets = await client.listClusterSecrets(uuid);
        return { content: [{ type: "text", text: JSON.stringify(secrets, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_secret",
    "[Enhanced] Create a new Swarm secret. Secrets are immutable — to update, create a new one and rotate references",
    {
      uuid: z.string().describe("Cluster UUID"),
      name: z.string().describe("Secret name (alphanumeric, dots, hyphens, underscores)"),
      data: z.string().describe("Secret value/data"),
      labels: z.record(z.string()).optional().describe("Key-value labels for the secret"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ uuid, name, data, labels }) => {
      try {
        const result = await client.createClusterSecret(uuid, { name, data, labels });
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "remove_secret",
    "[Enhanced] Remove a Swarm secret. Fails if any running service references it",
    {
      uuid: z.string().describe("Cluster UUID"),
      secret_id: z.string().describe("Swarm secret ID or name"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid, secret_id }) => {
      try {
        const result = await client.removeClusterSecret(uuid, secret_id);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  // ---- Configs ----

  server.tool(
    "list_configs",
    "[Enhanced] List all Swarm configs in the cluster",
    { uuid: z.string().describe("Cluster UUID") },
    { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    async ({ uuid }) => {
      try {
        const configs = await client.listClusterConfigs(uuid);
        return { content: [{ type: "text", text: JSON.stringify(configs, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "create_config",
    "[Enhanced] Create a new Swarm config. Configs are immutable — to update, create a new one and rotate references",
    {
      uuid: z.string().describe("Cluster UUID"),
      name: z.string().describe("Config name (alphanumeric, dots, hyphens, underscores)"),
      data: z.string().describe("Config data/content"),
      labels: z.record(z.string()).optional().describe("Key-value labels for the config"),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
    async ({ uuid, name, data, labels }) => {
      try {
        const result = await client.createClusterConfig(uuid, { name, data, labels });
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );

  server.tool(
    "remove_config",
    "[Enhanced] Remove a Swarm config. Fails if any running service references it",
    {
      uuid: z.string().describe("Cluster UUID"),
      config_id: z.string().describe("Swarm config ID or name"),
    },
    { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
    async ({ uuid, config_id }) => {
      try {
        const result = await client.removeClusterConfig(uuid, config_id);
        return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
      } catch (error) {
        return { content: [{ type: "text", text: `Error: ${(error as Error).message}` }], isError: true };
      }
    }
  );
}
