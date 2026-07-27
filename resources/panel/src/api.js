/**
 * @typedef {object} ProjectDto
 * @property {string} name Stable project name from its configuration.
 * @property {string|null} language Project programming language, when configured.
 * @property {string|null} framework Project framework, when configured.
 * @property {boolean} enabled Whether the project is enabled.
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

/**
 * @typedef {object} PanelStateDto
 * @property {ProjectDto[]} projects Project data used by the admin panel.
 * @property {SystemStatusDto} system System data used by the admin panel.
 */

/**
 * Load all data needed to refresh the admin panel.
 * @param {(path: string, options?: RequestInit) => Promise<unknown>} request
 * @returns {Promise<PanelStateDto>}
 */
export async function getPanelState(request) {
  const data = await request('/api/state');
  if (!data || typeof data !== 'object'
    || !('projects' in data) || !Array.isArray(data.projects)
    || !('system' in data) || !data.system || typeof data.system !== 'object'
    || !('status' in data.system) || !('services' in data.system) || !Array.isArray(data.system.services)) {
    throw new Error('Сервер вернул некорректное состояние панели.');
  }
  return /** @type {PanelStateDto} */ (data);
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
