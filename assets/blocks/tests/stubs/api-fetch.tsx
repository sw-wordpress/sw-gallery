import { vi } from 'vitest';

/** Replaces @wordpress/api-fetch; tests script its answers. */
const apiFetch = vi.fn();
export default apiFetch;
