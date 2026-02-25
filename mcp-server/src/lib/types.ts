// ============================================================
// Coolify API Type Definitions
// ============================================================

// ---- Configuration ----

export interface CoolifyClientConfig {
  baseUrl: string;
  accessToken: string;
  timeout?: number;
  retries?: number;
}

// ---- API Response Wrapper ----

export interface ApiError {
  message: string;
  statusCode: number;
}

// ---- Servers ----

export interface Server {
  id: number;
  uuid: string;
  name: string;
  description?: string;
  ip: string;
  port: number;
  user: string;
  is_reachable: boolean;
  is_usable: boolean;
  is_swarm_manager?: boolean;
  is_swarm_worker?: boolean;
  settings?: ServerSettings;
  proxy?: ProxyConfig;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

export interface ServerSettings {
  id: number;
  server_id: number;
  is_cloudflare_tunnel?: boolean;
  is_reachable: boolean;
  is_usable: boolean;
  [key: string]: unknown;
}

export interface ProxyConfig {
  type: string;
  status: string;
  [key: string]: unknown;
}

export interface Domain {
  domain: string;
  ip: string;
  [key: string]: unknown;
}

export interface ValidationResult {
  message: string;
  [key: string]: unknown;
}

// ---- Projects ----

export interface Project {
  id: number;
  uuid: string;
  name: string;
  description?: string;
  environments?: Environment[];
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

// ---- Environments ----

export interface Environment {
  id: number;
  uuid: string;
  name: string;
  project_id: number;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

// ---- Applications ----

export interface Application {
  id: number;
  uuid: string;
  name: string;
  description?: string;
  fqdn?: string;
  git_repository?: string;
  git_branch?: string;
  build_pack?: string;
  status?: string;
  environment_id?: number;
  destination_id?: number;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

// ---- Databases ----

export interface Database {
  id: number;
  uuid: string;
  name: string;
  description?: string;
  type?: string;
  status?: string;
  image?: string;
  is_public?: boolean;
  public_port?: number;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

export type DatabaseType =
  | "postgresql"
  | "mysql"
  | "mariadb"
  | "mongodb"
  | "redis"
  | "clickhouse"
  | "dragonfly"
  | "keydb";

// ---- Services ----

export interface Service {
  id: number;
  uuid: string;
  name: string;
  description?: string;
  type?: string;
  status?: string;
  fqdn?: string;
  docker_compose_raw?: string;
  environment_id?: number;
  destination_id?: number;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

// ---- Deployments ----

export interface Deployment {
  id: number;
  uuid: string;
  application_id?: number;
  application_uuid?: string;
  deployment_uuid?: string;
  status: string;
  pull_request_id?: number;
  commit?: string;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

// ---- Environment Variables ----

export interface EnvVar {
  id: number;
  uuid: string;
  key: string;
  value: string;
  is_build_time?: boolean;
  is_preview?: boolean;
  is_literal?: boolean;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

// ---- Database Backups ----

export interface BackupConfig {
  id: number;
  uuid: string;
  database_id: number;
  database_type: string;
  frequency: string;
  enabled: boolean;
  s3_storage_id?: number;
  save_s3?: boolean;
  number_of_backups_locally?: number;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

export interface BackupExecution {
  id: number;
  uuid: string;
  status: string;
  message?: string;
  filename?: string;
  size?: number;
  is_encrypted?: boolean;
  created_at: string;
  [key: string]: unknown;
}

// ---- Security ----

export interface PrivateKey {
  id: number;
  uuid: string;
  name: string;
  description?: string;
  private_key?: string;
  is_git_related?: boolean;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

// ---- Teams ----

export interface Team {
  id: number;
  name: string;
  description?: string;
  personal_team?: boolean;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

export interface TeamMember {
  id: number;
  name: string;
  email: string;
  role?: string;
  [key: string]: unknown;
}

// ---- Resources ----

export interface Resource {
  id: number;
  uuid: string;
  name: string;
  type: string;
  status?: string;
  [key: string]: unknown;
}

// ---- Input Types ----

export interface CreateProjectInput {
  name: string;
  description?: string;
}

export interface UpdateProjectInput {
  name?: string;
  description?: string;
}

export interface CreateEnvironmentInput {
  name: string;
}

export interface CreatePublicAppInput {
  name: string;
  project_uuid: string;
  server_uuid: string;
  environment_name?: string;
  git_repository: string;
  git_branch?: string;
  build_pack?: string;
  description?: string;
  [key: string]: unknown;
}

export interface CreateServiceInput {
  type: string;
  name?: string;
  description?: string;
  project_uuid: string;
  server_uuid: string;
  environment_name?: string;
  instant_deploy?: boolean;
  [key: string]: unknown;
}

export interface CreateDatabaseInput {
  name?: string;
  description?: string;
  project_uuid: string;
  server_uuid: string;
  environment_name?: string;
  image?: string;
  is_public?: boolean;
  public_port?: number;
  [key: string]: unknown;
}

export interface DeleteOptions {
  delete_configurations?: boolean;
  delete_volumes?: boolean;
  docker_cleanup?: boolean;
  delete_connected_networks?: boolean;
}

export interface LogOptions {
  since?: string;
  until?: string;
  lines?: number;
}

export interface DeployInput {
  uuid?: string;
  tag?: string;
}

export interface CreateEnvVarInput {
  key: string;
  value: string;
  is_build_time?: boolean;
  is_preview?: boolean;
  is_literal?: boolean;
}

export interface UpdateEnvVarInput {
  key: string;
  value: string;
  is_build_time?: boolean;
  is_preview?: boolean;
  is_literal?: boolean;
}

export interface BulkEnvVarInput {
  variables: Array<{
    key: string;
    value: string;
    is_build_time?: boolean;
    is_preview?: boolean;
    is_literal?: boolean;
  }>;
}

export interface CreateBackupInput {
  frequency: string;
  save_s3?: boolean;
  s3_storage_id?: number;
  database_type?: string;
  number_of_backups_locally?: number;
  enabled?: boolean;
}

export interface UpdateBackupInput {
  frequency?: string;
  save_s3?: boolean;
  s3_storage_id?: number;
  number_of_backups_locally?: number;
  enabled?: boolean;
}

export interface CreateKeyInput {
  name: string;
  description?: string;
  private_key: string;
}

export interface CreateServerInput {
  name: string;
  description?: string;
  ip: string;
  port?: number;
  user?: string;
  private_key_uuid: string;
  is_build_server?: boolean;
  instant_validate?: boolean;
  [key: string]: unknown;
}

export interface UpdateServerInput {
  name?: string;
  description?: string;
  ip?: string;
  port?: number;
  user?: string;
  [key: string]: unknown;
}

// ============================================================
// Coolify Enhanced Types
// ============================================================

// ---- Permissions ----

export type PermissionLevel = "view_only" | "deploy" | "full_access";

export interface ProjectAccess {
  user_id: number;
  user_name?: string;
  user_email?: string;
  permission_level: PermissionLevel;
  created_at?: string;
  updated_at?: string;
  [key: string]: unknown;
}

export interface GrantAccessInput {
  user_id: number;
  permission_level: PermissionLevel;
}

export interface UpdateAccessInput {
  permission_level: PermissionLevel;
}

export interface PermissionCheck {
  has_permission: boolean;
  permission: string;
  level?: PermissionLevel;
  [key: string]: unknown;
}

// ---- Resource Backups ----

export type ResourceBackupType = "volume" | "configuration" | "full" | "coolify_instance";

export interface ResourceBackup {
  id: number;
  uuid: string;
  resource_type?: string;
  resource_id?: number;
  backup_type: ResourceBackupType;
  frequency: string;
  enabled: boolean;
  save_s3?: boolean;
  s3_storage_id?: number;
  number_of_backups_locally?: number;
  executions?: ResourceBackupExecution[];
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

export interface ResourceBackupExecution {
  id: number;
  uuid: string;
  status: string;
  message?: string;
  filename?: string;
  size?: number;
  is_encrypted?: boolean;
  created_at: string;
  [key: string]: unknown;
}

export interface CreateResourceBackupInput {
  resource_type: string;
  resource_id: number;
  backup_type: ResourceBackupType;
  frequency: string;
  enabled?: boolean;
  save_s3?: boolean;
  s3_storage_id?: number;
  number_of_backups_locally?: number;
  [key: string]: unknown;
}

// ---- Custom Templates ----

export interface TemplateSource {
  id: number;
  uuid: string;
  name: string;
  repository_url: string;
  branch?: string;
  folder_path?: string;
  is_enabled: boolean;
  last_synced_at?: string;
  template_count?: number;
  sync_frequency?: string;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

export interface CreateTemplateSourceInput {
  name: string;
  repository_url: string;
  branch?: string;
  folder_path?: string;
  github_token?: string;
  is_enabled?: boolean;
  sync_frequency?: string;
}

export interface UpdateTemplateSourceInput {
  name?: string;
  repository_url?: string;
  branch?: string;
  folder_path?: string;
  github_token?: string;
  is_enabled?: boolean;
  sync_frequency?: string;
}

// ---- Networks ----

export interface ManagedNetwork {
  id: number;
  uuid: string;
  name: string;
  docker_network_name: string;
  server_id: number;
  type?: string;
  is_internal?: boolean;
  subnet?: string;
  gateway?: string;
  driver?: string;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

export interface CreateNetworkInput {
  name: string;
  is_internal?: boolean;
  subnet?: string;
  gateway?: string;
}

export interface AttachNetworkInput {
  network_uuid: string;
}

// ---- Clusters ----

export type ClusterType = "swarm" | "kubernetes";

export type ClusterStatus = "active" | "degraded" | "unreachable" | "unknown";

export interface Cluster {
  id: number;
  uuid: string;
  name: string;
  description?: string;
  type: ClusterType;
  status: ClusterStatus;
  settings?: Record<string, unknown>;
  metadata?: Record<string, unknown>;
  manager_server_id: number;
  team_id: number;
  manager_server?: Server;
  created_at: string;
  updated_at: string;
  [key: string]: unknown;
}

export interface ClusterNode {
  id: string;
  hostname: string;
  role: string;
  status: string;
  availability: string;
  ip?: string;
  engine_version?: string;
  cpu_cores?: number;
  memory_bytes?: number;
  labels?: Record<string, string>;
  is_leader?: boolean;
  [key: string]: unknown;
}

export interface ClusterService {
  id: string;
  name: string;
  image: string;
  mode?: string;
  replicas_running?: number;
  replicas_desired?: number;
  ports?: string[];
  [key: string]: unknown;
}

export interface ClusterTask {
  id: string;
  name?: string;
  node_id?: string;
  service_id?: string;
  status: string;
  desired_state?: string;
  error?: string;
  image?: string;
  [key: string]: unknown;
}

export interface ClusterEvent {
  id: number;
  cluster_id: number;
  event_type?: string;
  action?: string;
  actor_id?: string;
  actor_name?: string;
  attributes?: Record<string, unknown>;
  event_time?: string;
  created_at: string;
  [key: string]: unknown;
}

export interface SwarmSecret {
  id: string;
  name: string;
  labels?: Record<string, string>;
  created_at?: string;
  [key: string]: unknown;
}

export interface SwarmConfig {
  id: string;
  name: string;
  data?: string;
  labels?: Record<string, string>;
  created_at?: string;
  [key: string]: unknown;
}

export interface CreateClusterInput {
  name: string;
  type?: ClusterType;
  manager_server_id: number;
  description?: string;
}

export interface UpdateClusterInput {
  name?: string;
  description?: string;
  manager_server_id?: number;
}

export interface NodeActionInput {
  action: "drain" | "activate" | "pause" | "promote" | "demote" | "add-label" | "remove-label";
  label_key?: string;
  label_value?: string;
}

export interface ScaleServiceInput {
  replicas: number;
}

export interface CreateSecretInput {
  name: string;
  data: string;
  labels?: Record<string, string>;
}

export interface CreateConfigInput {
  name: string;
  data: string;
  labels?: Record<string, string>;
}

export interface ClusterEventFilter {
  type?: string;
  since?: number;
  until?: number;
  limit?: number;
}

export interface VisualizerData {
  nodes: ClusterNode[];
  tasks: ClusterTask[];
}
