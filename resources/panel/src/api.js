/**
 * @typedef {object} ProjectDto
 * @property {string} name Stable project name from its configuration.
 * @property {string|null} language Project programming language, when configured.
 * @property {string|null} framework Project framework, when configured.
 * @property {boolean} enabled Whether the project is enabled.
 * @property {boolean} protected Whether destructive changes are forbidden.
 * @property {string|null} url HTTPS URL of the project's primary host.
 * @property {string[]} tags User-defined project tags.
 * @property {string} description Project notes.
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

export async function getLogs(request, parameters) {
  return request(`/api/logs?${new URLSearchParams(parameters)}`);
}
