/**
 * @typedef {object} ProjectDto
 * @property {string} name Stable project name from its configuration.
 * @property {string|null} language Project programming language, when configured.
 * @property {string|null} framework Project framework, when configured.
 * @property {boolean} enabled Whether the project is enabled.
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
