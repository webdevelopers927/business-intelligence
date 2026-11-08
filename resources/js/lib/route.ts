import { usePage } from '@inertiajs/vue3';
import { route as ziggyRoute, type Config } from 'ziggy-js';

export type RouteParamValue = string | number | boolean;
export type RouteParams = Record<string, RouteParamValue | RouteParamValue[] | undefined> | RouteParamValue;

/**
 * Ziggy config is shared per page (it carries URL defaults such as the
 * current organization slug), so it is read from the current page on every
 * call instead of being frozen at app boot.
 */
function config(): Config {
    const { location, ...rest } = usePage().props.ziggy;
    // The server shares location as a URL string; Ziggy's matcher reads
    // location.host / .pathname / .search, so normalize it to that shape.
    const url = new URL(location);
    return { ...rest, location: { host: url.host, pathname: url.pathname, search: url.search } } as Config;
}

export function route(name: string, params?: RouteParams, absolute = false): string {
    return String(ziggyRoute(name as never, params as never, absolute, config()));
}

/**
 * Router instance for inspection. `absolute` must be passed explicitly as
 * false: Ziggy defaults an undefined value to true, which makes current()
 * compare host-prefixed URLs and never match.
 */
function router() {
    return ziggyRoute(undefined, undefined, false, config());
}

export function isCurrentRoute(pattern: string): boolean {
    return Boolean(router().current(pattern as never));
}

export function hasRoute(name: string): boolean {
    return router().has(name as never);
}
