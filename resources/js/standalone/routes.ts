import { boot } from './boot';

/**
 * Stand-ins for the Wayfinder route helpers, using URLs from the server.
 */
function route(name: string) {
    return {
        url(options?: { query?: Record<string, string> }): string {
            const query = options?.query
                ? `?${new URLSearchParams(options.query).toString()}`
                : '';

            return `${boot.urls[name]}${query}`;
        },
    };
}

export const generate = route('generate');
export const importMethod = route('import');
export const inspect = route('inspect');
export const preview = route('preview');
export const testAuth = route('test-auth');
export const testEndpoint = route('test-endpoint');
