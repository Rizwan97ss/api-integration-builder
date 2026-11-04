import { defineComponent, reactive, watchEffect } from 'vue';
import { boot } from './boot';

/**
 * Stand-ins for the parts of @inertiajs/vue3 the builder uses, so the same
 * pages run in a project without Inertia.
 */
export const Head = defineComponent({
    props: { title: { type: String, default: '' } },
    setup(props) {
        watchEffect(() => {
            if (props.title) {
                document.title = props.title;
            }
        });

        return () => null;
    },
});

type Options<TResponse> = {
    onSuccess?: (response: TResponse) => void;
    onError?: (errors: Record<string, string>) => void;
    onHttpException?: (response: { status: number; data?: unknown }) => void;
    onFinish?: () => void;
};

function parse(text: string): unknown {
    try {
        return text === '' ? null : JSON.parse(text);
    } catch {
        return null;
    }
}

export function useHttp<TData, TResponse>(data: TData) {
    let transformer: () => unknown = () => data;

    async function send(
        method: 'GET' | 'POST',
        url: string,
        options: Options<TResponse>,
    ): Promise<void> {
        state.processing = true;
        state.errors = {};

        try {
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': boot.csrfToken,
                },
                body:
                    method === 'GET'
                        ? undefined
                        : JSON.stringify(transformer()),
            });
            const body = parse(await response.text());

            if (response.ok) {
                options.onSuccess?.(body as TResponse);
            } else if (response.status === 422) {
                const errors = (body as { errors?: Record<string, string[]> })
                    ?.errors;

                state.errors = Object.fromEntries(
                    Object.entries(errors ?? {}).map(([key, messages]) => [
                        key,
                        messages[0],
                    ]),
                );
                options.onError?.(state.errors);
            } else {
                options.onHttpException?.({
                    status: response.status,
                    data: body,
                });
            }
        } catch {
            options.onHttpException?.({
                status: 0,
                data: { message: 'Could not reach the server.' },
            });
        } finally {
            state.processing = false;
            options.onFinish?.();
        }
    }

    const state = reactive({
        processing: false,
        errors: {} as Record<string, string>,
        transform(callback: () => unknown) {
            transformer = callback;

            return state;
        },
        get: (url: string, options: Options<TResponse> = {}) =>
            send('GET', url, options),
        post: (url: string, options: Options<TResponse> = {}) =>
            send('POST', url, options),
    });

    return state;
}
