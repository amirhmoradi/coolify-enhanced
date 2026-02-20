// ============================================================
// CoolifyClient — HTTP API client for Coolify
// ============================================================
// Single client handling both native Coolify API and
// coolify-enhanced API endpoints.
// ============================================================

import type {
  CoolifyClientConfig,
  Server,
  Domain,
  ValidationResult,
  CreateServerInput,
  UpdateServerInput,
  Project,
  CreateProjectInput,
  UpdateProjectInput,
  Environment,
  CreateEnvironmentInput,
  Application,
  CreatePublicAppInput,
  LogOptions,
  Database,
  DatabaseType,
  CreateDatabaseInput,
  DeleteOptions,
  Service,
  CreateServiceInput,
  EnvVar,
  CreateEnvVarInput,
  UpdateEnvVarInput,
  BulkEnvVarInput,
  Deployment,
  DeployInput,
  BackupConfig,
  BackupExecution,
  CreateBackupInput,
  UpdateBackupInput,
  PrivateKey,
  CreateKeyInput,
  Team,
  TeamMember,
  Resource,
  ProjectAccess,
  GrantAccessInput,
  UpdateAccessInput,
  PermissionCheck,
  ResourceBackup,
  CreateResourceBackupInput,
  TemplateSource,
  CreateTemplateSourceInput,
  UpdateTemplateSourceInput,
  ManagedNetwork,
  CreateNetworkInput,
  AttachNetworkInput,
} from "./types.js";

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function isRetryableStatus(status: number): boolean {
  return status === 429 || status >= 500;
}

export class CoolifyClient {
  private baseUrl: string;
  private accessToken: string;
  private timeout: number;
  private retries: number;

  constructor(config: CoolifyClientConfig) {
    this.baseUrl = config.baseUrl.replace(/\/+$/, "");
    this.accessToken = config.accessToken;
    this.timeout = config.timeout ?? 30_000;
    this.retries = config.retries ?? 3;
  }

  // ---- Generic Request ----

  private async request<T>(
    method: string,
    path: string,
    options?: {
      body?: unknown;
      params?: Record<string, string>;
    }
  ): Promise<T> {
    let url = `${this.baseUrl}/api/v1${path}`;

    if (options?.params) {
      const searchParams = new URLSearchParams(options.params);
      url += `?${searchParams.toString()}`;
    }

    const headers: Record<string, string> = {
      Authorization: `Bearer ${this.accessToken}`,
      Accept: "application/json",
    };

    if (options?.body) {
      headers["Content-Type"] = "application/json";
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.timeout);

    try {
      const response = await fetch(url, {
        method,
        headers,
        body: options?.body ? JSON.stringify(options.body) : undefined,
        signal: controller.signal,
      });

      if (!response.ok) {
        const errorBody = await response.text().catch(() => "");
        let errorMessage: string;
        try {
          const parsed = JSON.parse(errorBody);
          errorMessage = parsed.message || parsed.error || errorBody;
        } catch {
          errorMessage = errorBody || response.statusText;
        }
        const error = new Error(`Coolify API error (${response.status}): ${errorMessage}`);
        (error as Error & { statusCode: number }).statusCode = response.status;
        throw error;
      }

      // Handle 204 No Content
      if (response.status === 204) {
        return undefined as T;
      }

      const text = await response.text();
      if (!text) return undefined as T;
      return JSON.parse(text) as T;
    } finally {
      clearTimeout(timeoutId);
    }
  }

  private async requestWithRetry<T>(
    method: string,
    path: string,
    options?: { body?: unknown; params?: Record<string, string> }
  ): Promise<T> {
    let lastError: Error | undefined;
    for (let attempt = 0; attempt <= this.retries; attempt++) {
      try {
        return await this.request<T>(method, path, options);
      } catch (error) {
        lastError = error instanceof Error ? error : new Error(String(error));
        const statusCode = (lastError as Error & { statusCode?: number }).statusCode;
        if (attempt < this.retries && (!statusCode || isRetryableStatus(statusCode))) {
          await sleep(Math.pow(2, attempt) * 1000);
          continue;
        }
        throw lastError;
      }
    }
    throw lastError;
  }

  // ============================================================
  // Native Coolify API
  // ============================================================

  // ---- Servers ----

  async listServers(): Promise<Server[]> {
    return this.requestWithRetry<Server[]>("GET", "/servers");
  }

  async getServer(uuid: string): Promise<Server> {
    return this.requestWithRetry<Server>("GET", `/servers/${uuid}`);
  }

  async getServerResources(uuid: string): Promise<Resource[]> {
    return this.requestWithRetry<Resource[]>("GET", `/servers/${uuid}/resources`);
  }

  async getServerDomains(uuid: string): Promise<Domain[]> {
    return this.requestWithRetry<Domain[]>("GET", `/servers/${uuid}/domains`);
  }

  async validateServer(uuid: string): Promise<ValidationResult> {
    return this.requestWithRetry<ValidationResult>("GET", `/servers/${uuid}/validate`);
  }

  async createServer(data: CreateServerInput): Promise<Server> {
    return this.requestWithRetry<Server>("POST", "/servers", { body: data });
  }

  async updateServer(uuid: string, data: UpdateServerInput): Promise<Server> {
    return this.requestWithRetry<Server>("PATCH", `/servers/${uuid}`, { body: data });
  }

  async deleteServer(uuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/servers/${uuid}`);
  }

  // ---- Projects ----

  async listProjects(): Promise<Project[]> {
    return this.requestWithRetry<Project[]>("GET", "/projects");
  }

  async getProject(uuid: string): Promise<Project> {
    return this.requestWithRetry<Project>("GET", `/projects/${uuid}`);
  }

  async createProject(data: CreateProjectInput): Promise<Project> {
    return this.requestWithRetry<Project>("POST", "/projects", { body: data });
  }

  async updateProject(uuid: string, data: UpdateProjectInput): Promise<Project> {
    return this.requestWithRetry<Project>("PATCH", `/projects/${uuid}`, { body: data });
  }

  async deleteProject(uuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/projects/${uuid}`);
  }

  // ---- Environments ----

  async listEnvironments(projectUuid: string): Promise<Environment[]> {
    return this.requestWithRetry<Environment[]>("GET", `/projects/${projectUuid}/environments`);
  }

  async getEnvironment(projectUuid: string, envName: string): Promise<Environment> {
    return this.requestWithRetry<Environment>("GET", `/projects/${projectUuid}/${envName}`);
  }

  async createEnvironment(projectUuid: string, data: CreateEnvironmentInput): Promise<Environment> {
    return this.requestWithRetry<Environment>("POST", `/projects/${projectUuid}/environments`, { body: data });
  }

  async deleteEnvironment(projectUuid: string, envName: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/projects/${projectUuid}/environments/${envName}`);
  }

  // ---- Applications ----

  async listApplications(): Promise<Application[]> {
    return this.requestWithRetry<Application[]>("GET", "/applications");
  }

  async getApplication(uuid: string): Promise<Application> {
    return this.requestWithRetry<Application>("GET", `/applications/${uuid}`);
  }

  async createPublicApplication(data: CreatePublicAppInput): Promise<Application> {
    return this.requestWithRetry<Application>("POST", "/applications/public", { body: data });
  }

  async updateApplication(uuid: string, data: Record<string, unknown>): Promise<Application> {
    return this.requestWithRetry<Application>("PATCH", `/applications/${uuid}`, { body: data });
  }

  async deleteApplication(uuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/applications/${uuid}`);
  }

  async startApplication(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/applications/${uuid}/start`);
  }

  async stopApplication(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/applications/${uuid}/stop`);
  }

  async restartApplication(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/applications/${uuid}/restart`);
  }

  async getApplicationLogs(uuid: string, opts?: LogOptions): Promise<unknown> {
    const params: Record<string, string> = {};
    if (opts?.since) params.since = opts.since;
    if (opts?.until) params.until = opts.until;
    if (opts?.lines) params.lines = String(opts.lines);
    return this.requestWithRetry<unknown>("GET", `/applications/${uuid}/logs`, { params });
  }

  // ---- Databases ----

  async listDatabases(): Promise<Database[]> {
    return this.requestWithRetry<Database[]>("GET", "/databases");
  }

  async getDatabase(uuid: string): Promise<Database> {
    return this.requestWithRetry<Database>("GET", `/databases/${uuid}`);
  }

  async createDatabase(type: DatabaseType, data: CreateDatabaseInput): Promise<Database> {
    return this.requestWithRetry<Database>("POST", `/databases/${type}`, { body: data });
  }

  async updateDatabase(uuid: string, data: Record<string, unknown>): Promise<Database> {
    return this.requestWithRetry<Database>("PATCH", `/databases/${uuid}`, { body: data });
  }

  async deleteDatabase(uuid: string, opts?: DeleteOptions): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/databases/${uuid}`, { body: opts });
  }

  async startDatabase(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/databases/${uuid}/start`);
  }

  async stopDatabase(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/databases/${uuid}/stop`);
  }

  async restartDatabase(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/databases/${uuid}/restart`);
  }

  // ---- Database Backups ----

  async listDbBackups(dbUuid: string): Promise<BackupConfig[]> {
    return this.requestWithRetry<BackupConfig[]>("GET", `/databases/${dbUuid}/backups`);
  }

  async createDbBackup(dbUuid: string, data: CreateBackupInput): Promise<BackupConfig> {
    return this.requestWithRetry<BackupConfig>("POST", `/databases/${dbUuid}/backups`, { body: data });
  }

  async updateDbBackup(dbUuid: string, backupUuid: string, data: UpdateBackupInput): Promise<BackupConfig> {
    return this.requestWithRetry<BackupConfig>("PATCH", `/databases/${dbUuid}/backups/${backupUuid}`, { body: data });
  }

  async deleteDbBackup(dbUuid: string, backupUuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/databases/${dbUuid}/backups/${backupUuid}`);
  }

  async listDbBackupExecutions(dbUuid: string, backupUuid: string): Promise<BackupExecution[]> {
    return this.requestWithRetry<BackupExecution[]>("GET", `/databases/${dbUuid}/backups/${backupUuid}/executions`);
  }

  // ---- Services ----

  async listServices(): Promise<Service[]> {
    return this.requestWithRetry<Service[]>("GET", "/services");
  }

  async getService(uuid: string): Promise<Service> {
    return this.requestWithRetry<Service>("GET", `/services/${uuid}`);
  }

  async createService(data: CreateServiceInput): Promise<Service> {
    return this.requestWithRetry<Service>("POST", "/services", { body: data });
  }

  async updateService(uuid: string, data: Record<string, unknown>): Promise<Service> {
    return this.requestWithRetry<Service>("PATCH", `/services/${uuid}`, { body: data });
  }

  async deleteService(uuid: string, opts?: DeleteOptions): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/services/${uuid}`, { body: opts });
  }

  async startService(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/services/${uuid}/start`);
  }

  async stopService(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/services/${uuid}/stop`);
  }

  async restartService(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/services/${uuid}/restart`);
  }

  // ---- Environment Variables ----

  async listAppEnvs(uuid: string): Promise<EnvVar[]> {
    return this.requestWithRetry<EnvVar[]>("GET", `/applications/${uuid}/envs`);
  }

  async createAppEnv(uuid: string, data: CreateEnvVarInput): Promise<EnvVar> {
    return this.requestWithRetry<EnvVar>("POST", `/applications/${uuid}/envs`, { body: data });
  }

  async updateAppEnv(uuid: string, data: UpdateEnvVarInput): Promise<EnvVar> {
    return this.requestWithRetry<EnvVar>("PATCH", `/applications/${uuid}/envs`, { body: data });
  }

  async bulkUpdateAppEnvs(uuid: string, data: BulkEnvVarInput): Promise<unknown> {
    return this.requestWithRetry<unknown>("PATCH", `/applications/${uuid}/envs/bulk`, { body: data });
  }

  async deleteAppEnv(appUuid: string, envUuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/applications/${appUuid}/envs/${envUuid}`);
  }

  async listServiceEnvs(uuid: string): Promise<EnvVar[]> {
    return this.requestWithRetry<EnvVar[]>("GET", `/services/${uuid}/envs`);
  }

  async createServiceEnv(uuid: string, data: CreateEnvVarInput): Promise<EnvVar> {
    return this.requestWithRetry<EnvVar>("POST", `/services/${uuid}/envs`, { body: data });
  }

  async updateServiceEnv(uuid: string, data: UpdateEnvVarInput): Promise<EnvVar> {
    return this.requestWithRetry<EnvVar>("PATCH", `/services/${uuid}/envs`, { body: data });
  }

  async bulkUpdateServiceEnvs(uuid: string, data: BulkEnvVarInput): Promise<unknown> {
    return this.requestWithRetry<unknown>("PATCH", `/services/${uuid}/envs/bulk`, { body: data });
  }

  async deleteServiceEnv(svcUuid: string, envUuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/services/${svcUuid}/envs/${envUuid}`);
  }

  // ---- Deployments ----

  async deploy(opts: DeployInput): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", "/deploy", { body: opts });
  }

  async listDeployments(): Promise<Deployment[]> {
    return this.requestWithRetry<Deployment[]>("GET", "/deployments");
  }

  async getDeployment(uuid: string): Promise<Deployment> {
    return this.requestWithRetry<Deployment>("GET", `/deployments/${uuid}`);
  }

  async cancelDeployment(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/deployments/${uuid}/cancel`);
  }

  async listAppDeployments(appUuid: string): Promise<Deployment[]> {
    return this.requestWithRetry<Deployment[]>("GET", `/deployments/applications/${appUuid}`);
  }

  // ---- Security ----

  async listPrivateKeys(): Promise<PrivateKey[]> {
    return this.requestWithRetry<PrivateKey[]>("GET", "/security/keys");
  }

  async createPrivateKey(data: CreateKeyInput): Promise<PrivateKey> {
    return this.requestWithRetry<PrivateKey>("POST", "/security/keys", { body: data });
  }

  async getPrivateKey(uuid: string): Promise<PrivateKey> {
    return this.requestWithRetry<PrivateKey>("GET", `/security/keys/${uuid}`);
  }

  async deletePrivateKey(uuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/security/keys/${uuid}`);
  }

  // ---- System ----

  async getVersion(): Promise<string> {
    const result = await this.requestWithRetry<{ version: string } | string>("GET", "/version");
    return typeof result === "string" ? result : result.version;
  }

  async healthCheck(): Promise<boolean> {
    try {
      await this.request<unknown>("GET", "/../health");
      return true;
    } catch {
      return false;
    }
  }

  async listResources(): Promise<Resource[]> {
    return this.requestWithRetry<Resource[]>("GET", "/resources");
  }

  async listTeams(): Promise<Team[]> {
    return this.requestWithRetry<Team[]>("GET", "/teams");
  }

  async getCurrentTeam(): Promise<Team> {
    return this.requestWithRetry<Team>("GET", "/teams/current");
  }

  async getTeamMembers(): Promise<TeamMember[]> {
    return this.requestWithRetry<TeamMember[]>("GET", "/teams/current/members");
  }

  // ============================================================
  // Coolify Enhanced API
  // ============================================================

  // ---- Permissions ----

  async listProjectAccess(projectUuid: string): Promise<ProjectAccess[]> {
    return this.requestWithRetry<ProjectAccess[]>("GET", `/projects/${projectUuid}/access`);
  }

  async grantProjectAccess(projectUuid: string, data: GrantAccessInput): Promise<ProjectAccess> {
    return this.requestWithRetry<ProjectAccess>("POST", `/projects/${projectUuid}/access`, { body: data });
  }

  async updateProjectAccess(projectUuid: string, userId: number, data: UpdateAccessInput): Promise<ProjectAccess> {
    return this.requestWithRetry<ProjectAccess>("PATCH", `/projects/${projectUuid}/access/${userId}`, { body: data });
  }

  async revokeProjectAccess(projectUuid: string, userId: number): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/projects/${projectUuid}/access/${userId}`);
  }

  async checkUserPermission(projectUuid: string, userId: number, permission: string): Promise<PermissionCheck> {
    return this.requestWithRetry<PermissionCheck>(
      "GET",
      `/projects/${projectUuid}/access/${userId}/check`,
      { params: { permission } }
    );
  }

  // ---- Resource Backups ----

  async listResourceBackups(): Promise<ResourceBackup[]> {
    return this.requestWithRetry<ResourceBackup[]>("GET", "/resource-backups");
  }

  async createResourceBackup(data: CreateResourceBackupInput): Promise<ResourceBackup> {
    return this.requestWithRetry<ResourceBackup>("POST", "/resource-backups", { body: data });
  }

  async getResourceBackup(uuid: string): Promise<ResourceBackup> {
    return this.requestWithRetry<ResourceBackup>("GET", `/resource-backups/${uuid}`);
  }

  async triggerResourceBackup(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/resource-backups/${uuid}/trigger`);
  }

  async deleteResourceBackup(uuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/resource-backups/${uuid}`);
  }

  // ---- Custom Templates ----

  async listTemplateSources(): Promise<TemplateSource[]> {
    return this.requestWithRetry<TemplateSource[]>("GET", "/template-sources");
  }

  async createTemplateSource(data: CreateTemplateSourceInput): Promise<TemplateSource> {
    return this.requestWithRetry<TemplateSource>("POST", "/template-sources", { body: data });
  }

  async getTemplateSource(uuid: string): Promise<TemplateSource> {
    return this.requestWithRetry<TemplateSource>("GET", `/template-sources/${uuid}`);
  }

  async updateTemplateSource(uuid: string, data: UpdateTemplateSourceInput): Promise<TemplateSource> {
    return this.requestWithRetry<TemplateSource>("PATCH", `/template-sources/${uuid}`, { body: data });
  }

  async deleteTemplateSource(uuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/template-sources/${uuid}`);
  }

  async syncTemplateSource(uuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/template-sources/${uuid}/sync`);
  }

  async syncAllTemplateSources(): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", "/template-sources/sync-all");
  }

  // ---- Networks ----

  async listServerNetworks(serverUuid: string): Promise<ManagedNetwork[]> {
    return this.requestWithRetry<ManagedNetwork[]>("GET", `/servers/${serverUuid}/networks`);
  }

  async createNetwork(serverUuid: string, data: CreateNetworkInput): Promise<ManagedNetwork> {
    return this.requestWithRetry<ManagedNetwork>("POST", `/servers/${serverUuid}/networks`, { body: data });
  }

  async getNetwork(serverUuid: string, networkUuid: string): Promise<ManagedNetwork> {
    return this.requestWithRetry<ManagedNetwork>("GET", `/servers/${serverUuid}/networks/${networkUuid}`);
  }

  async deleteNetwork(serverUuid: string, networkUuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/servers/${serverUuid}/networks/${networkUuid}`);
  }

  async syncNetworks(serverUuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/servers/${serverUuid}/networks/sync`);
  }

  async migrateProxy(serverUuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/servers/${serverUuid}/networks/migrate-proxy`);
  }

  async cleanupProxy(serverUuid: string): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/servers/${serverUuid}/networks/cleanup-proxy`);
  }

  async listResourceNetworks(type: string, uuid: string): Promise<ManagedNetwork[]> {
    return this.requestWithRetry<ManagedNetwork[]>("GET", `/resources/${type}/${uuid}/networks`);
  }

  async attachResourceNetwork(type: string, uuid: string, data: AttachNetworkInput): Promise<unknown> {
    return this.requestWithRetry<unknown>("POST", `/resources/${type}/${uuid}/networks`, { body: data });
  }

  async detachResourceNetwork(type: string, uuid: string, networkUuid: string): Promise<void> {
    return this.requestWithRetry<void>("DELETE", `/resources/${type}/${uuid}/networks/${networkUuid}`);
  }

  // ---- Feature Detection ----

  async isEnhanced(): Promise<boolean> {
    try {
      await this.request<unknown>("GET", "/resource-backups");
      return true;
    } catch (error) {
      const statusCode = (error as Error & { statusCode?: number }).statusCode;
      // 401/403 means the endpoint exists but auth failed — still enhanced
      if (statusCode === 401 || statusCode === 403) return true;
      return false;
    }
  }
}
