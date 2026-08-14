/**
 * @typedef {object} ProjectDto
 * @property {string} name Stable project name from its configuration.
 * @property {{code: string, name: string}|null} language Project programming language, when configured.
 * @property {string|null} languageVersion Project programming language version, resolved to the system default for legacy PHP projects.
 * @property {{code: string, name: string}|null} framework Project framework, when configured.
 * @property {boolean} enabled Whether the project is enabled.
 * @property {boolean} protected Whether destructive changes are forbidden.
 * @property {string|null} url HTTPS URL of the project's primary host.
 * @property {string[]} tags User-defined project tags.
 * @property {string} description Project notes.
 * @property {string} root Absolute project root path.
 */

/**
 * @typedef {object} ProjectListDto
 * @property {ProjectDto[]} projects Projects ordered by name.
 */

/**
 * Load the documented project-list DTO.
 * @param {(path: string, options?: RequestInit) => Promise<unknown>} request
 * @returns {Promise<ProjectListDto>}
 */
export async function getProjects(request) {
  const data = await request('/api/projects');
  if (!data || typeof data !== 'object' || !('projects' in data) || !Array.isArray(data.projects)) {
    throw new Error('Сервер вернул некорректный список проектов.');
  }
  return /** @type {ProjectListDto} */ (data);
}

/** @returns {Promise<ProjectListDto>} */
export async function runProjectAction(request, project, action) {
  return /** @type {Promise<ProjectListDto>} */ (request(`/api/projects/${encodeURIComponent(project)}/${action}`, { method: 'POST' }));
}

export async function getProjectOptions(request) {
  return request('/api/projects/options');
}

export async function getProjectBackups(request, project, parameters) {
  return request(`/api/projects/${encodeURIComponent(project)}/backups?${new URLSearchParams(parameters)}`);
}

export async function getProjectSchedule(request, project) {
  return request(`/api/projects/${encodeURIComponent(project)}/schedule`);
}

export async function addProjectSchedule(request, project, item) {
  return request(`/api/projects/${encodeURIComponent(project)}/schedule`, { method: 'POST', body: JSON.stringify(item) });
}

export async function updateProjectSchedule(request, project, index, item) {
  return request(`/api/projects/${encodeURIComponent(project)}/schedule/${index}`, { method: 'POST', body: JSON.stringify(item) });
}

export async function deleteProjectSchedule(request, project, index) {
  return request(`/api/projects/${encodeURIComponent(project)}/schedule/${index}`, { method: 'DELETE' });
}

export async function createProjectBackup(request, project, selection) {
  return request(`/api/projects/${encodeURIComponent(project)}/backups`, { method: 'POST', body: JSON.stringify(selection) });
}

export async function restoreProjectBackup(request, project, backup, options) {
  return request(`/api/projects/${encodeURIComponent(project)}/backups/${encodeURIComponent(backup)}/restore`, { method: 'POST', body: JSON.stringify(options) });
}

export async function deleteProjectBackup(request, project, backup, options) {
  return request(`/api/projects/${encodeURIComponent(project)}/backups/${encodeURIComponent(backup)}/delete`, { method: 'POST', body: JSON.stringify(options) });
}

export async function updateProjectBackupComment(request, project, backup, options) {
  return request(`/api/projects/${encodeURIComponent(project)}/backups/${encodeURIComponent(backup)}/comment`, { method: 'POST', body: JSON.stringify(options) });
}

export async function createProject(request, project) {
  return request('/api/projects', { method: 'POST', body: JSON.stringify(project) });
}

export async function cloneProject(request, project, parameters) {
  return request(`/api/projects/${encodeURIComponent(project)}/clone`, { method: 'POST', body: JSON.stringify(parameters) });
}

/** @returns {Promise<ProjectListDto>} */
export async function updateProject(request, project, changes) {
  return /** @type {Promise<ProjectListDto>} */ (request(`/api/projects/${encodeURIComponent(project)}/update`, {
    method: 'POST', body: JSON.stringify(changes),
  }));
}

/** @returns {Promise<ProjectListDto>} */
export async function saveProjectNotes(request, project, tags, description) {
  return /** @type {Promise<ProjectListDto>} */ (request(`/api/projects/${encodeURIComponent(project)}/notes`, {
    method: 'POST',
    body: JSON.stringify({ tags, description }),
  }));
}

/** @returns {Promise<ProjectListDto>} */
export async function saveProjectSecurity(request, project, protectedProject) {
  return /** @type {Promise<ProjectListDto>} */ (request(`/api/projects/${encodeURIComponent(project)}/security`, {
    method: 'POST',
    body: JSON.stringify({ protected: protectedProject }),
  }));
}

/**
 * @typedef {object} SystemServiceDto
 * @property {string} name Docker Compose service name.
 * @property {string} image Configured image reference.
 * @property {boolean} running Whether the service container is running.
 */

/**
 * @typedef {object} SystemStatusDto
 * @property {'running'|'partial'|'stopped'} status Aggregated system state.
 * @property {SystemServiceDto[]} services Services ordered by name.
 */

/** @returns {Promise<SystemStatusDto>} */
export async function getSystemStatus(request) {
  return /** @type {Promise<SystemStatusDto>} */ (request('/api/system'));
}

/** @returns {Promise<SystemStatusDto>} */
export async function runSystemAction(request, action, service = '') {
  const target = service ? `/api/system/services/${encodeURIComponent(service)}/${action}` : `/api/system/${action}`;
  return /** @type {Promise<SystemStatusDto>} */ (request(target, { method: 'POST' }));
}

export async function queueSystemSelfUpdate(request) {
  return request('/api/system/self-update', { method: 'POST' });
}

export async function getLogs(request, parameters) {
  return request(`/api/logs?${new URLSearchParams(parameters)}`);
}

export async function getSecuritySettings(request) {
  return request('/api/settings/security');
}

export async function saveSecuritySettings(request, maximumSessionHours, httpAuthLogin, httpAuthPassword) {
  return request('/api/settings/security', {
    method: 'POST',
    body: JSON.stringify({ maximumSessionHours, httpAuthLogin, httpAuthPassword }),
  });
}

export async function getProjectsSettings(request) {
  return request('/api/settings/projects');
}

export async function saveProjectsSettings(request, locations, databaseLocations) {
  return request('/api/settings/projects', {
    method: 'POST',
    body: JSON.stringify({ locations, databaseLocations }),
  });
}

export async function getBackupsSettings(request) {
  return request('/api/settings/backups');
}

export async function saveBackupsSettings(request, locations, fileStrategies) {
  return request('/api/settings/backups', {
    method: 'POST',
    body: JSON.stringify({ locations, fileStrategies }),
  });
}

export async function getHooksSettings(request) {
  return request('/api/settings/hooks');
}

export async function createHookSettings(request, hook) {
  return request('/api/settings/hooks', { method: 'POST', body: JSON.stringify(hook) });
}

export async function getHookContent(request, id) {
  return request(`/api/settings/hooks/${encodeURIComponent(id)}/content`);
}

export async function saveHookContent(request, id, parameters) {
  return request(`/api/settings/hooks/${encodeURIComponent(id)}/content`, { method: 'POST', body: JSON.stringify(parameters) });
}

export async function runHookSettings(request, id, profile, workingDirectory) {
  return request(`/api/settings/hooks/${encodeURIComponent(id)}/run`, { method: 'POST', body: JSON.stringify({ profile, workingDirectory }) });
}

export async function toggleHookSettings(request, id) {
  return request(`/api/settings/hooks/${encodeURIComponent(id)}/toggle`, { method: 'POST', body: JSON.stringify({}) });
}

export async function deleteHookSettings(request, id) {
  return request(`/api/settings/hooks/${encodeURIComponent(id)}`, { method: 'DELETE' });
}

export async function getUsersSettings(request, page, pageSize) {
  return request(`/api/settings/users?${new URLSearchParams({ page: String(page), pageSize: String(pageSize) })}`);
}

export async function createPanelUser(request, login, comments) {
  return request('/api/settings/users', { method: 'POST', body: JSON.stringify({ login, comments }) });
}

export async function updatePanelUser(request, login, comments) {
  return request(`/api/settings/users/${encodeURIComponent(login)}`, { method: 'POST', body: JSON.stringify({ comments }) });
}

export async function rotatePanelUserPassword(request, login) {
  return request(`/api/settings/users/${encodeURIComponent(login)}/password`, { method: 'POST', body: JSON.stringify({}) });
}

export async function deletePanelUser(request, login) {
  return request(`/api/settings/users/${encodeURIComponent(login)}`, { method: 'DELETE' });
}
