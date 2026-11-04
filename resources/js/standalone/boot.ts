export type Boot = {
    props: Record<string, unknown>;
    csrfToken: string;
    urls: Record<string, string>;
};

/**
 * Read the data the server embedded in the page.
 */
export const boot: Boot = JSON.parse(
    document.getElementById('apidev-boot')?.textContent ?? '{}',
) as Boot;
