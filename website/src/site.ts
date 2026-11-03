// The values that the pages and the Markdown plugins share.
export const repository = 'https://github.com/phemphp/phem';
export const base = '/phem';

export const url = (path: string): string => `${base}/${path}`.replace(/\/+/g, '/');
