@php
    /** @var \App\Enums\HealthCheckStatus $status */
    $refreshSeconds = 10;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="robots" content="noindex">
        <title>{{ __('health.page.status.'.$status->value) }} · {{ __('health.page.title') }} · {{ config('app.name') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <noscript><meta http-equiv="refresh" content="{{ $refreshSeconds }}"></noscript>
        <style>
            :root {
                --bg: #fafafa;
                --surface: #ffffff;
                --border: #e4e4e7;
                --text: #18181b;
                --muted: #71717a;
                --ok: #059669;
                --ok-soft: #ecfdf5;
                --warn: #d97706;
                --warn-soft: #fffbeb;
                --fail: #e11d48;
                --fail-soft: #fff1f2;
                --focus: #2563eb;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --bg: #09090b;
                    --surface: #18181b;
                    --border: #27272a;
                    --text: #f4f4f5;
                    --muted: #a1a1aa;
                    --ok: #34d399;
                    --ok-soft: rgba(16, 185, 129, 0.12);
                    --warn: #fbbf24;
                    --warn-soft: rgba(245, 158, 11, 0.12);
                    --fail: #fb7185;
                    --fail-soft: rgba(244, 63, 94, 0.12);
                    --focus: #60a5fa;
                }
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                background: var(--bg);
                color: var(--text);
                font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
                -webkit-font-smoothing: antialiased;
            }

            main { max-width: 960px; margin: 0 auto; padding: 40px 16px 56px; }

            a { color: inherit; }
            a:focus-visible, button:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }

            .eyebrow { margin: 0 0 12px; color: var(--muted); font-size: 13px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; }

            .overall {
                display: flex; gap: 16px; align-items: center;
                padding: 20px 24px; border: 1px solid var(--border); border-radius: 16px;
                background: var(--surface);
            }
            .overall[data-status="ok"] { background: var(--ok-soft); border-color: color-mix(in srgb, var(--ok) 30%, transparent); }
            .overall[data-status="warn"] { background: var(--warn-soft); border-color: color-mix(in srgb, var(--warn) 35%, transparent); }
            .overall[data-status="fail"] { background: var(--fail-soft); border-color: color-mix(in srgb, var(--fail) 35%, transparent); }
            .overall h1 { margin: 0; font-size: 22px; line-height: 1.3; letter-spacing: -0.01em; }
            .overall p { margin: 2px 0 0; color: var(--muted); }

            .dot { position: relative; flex: none; width: 14px; height: 14px; border-radius: 50%; background: var(--muted); }
            .dot[data-status="ok"] { background: var(--ok); }
            .dot[data-status="warn"] { background: var(--warn); }
            .dot[data-status="fail"] { background: var(--fail); }
            .dot.pulse::after {
                content: ''; position: absolute; inset: 0; border-radius: 50%; background: inherit;
                animation: pulse 2s ease-out infinite;
            }
            @keyframes pulse { from { transform: scale(1); opacity: 0.6; } to { transform: scale(2.6); opacity: 0; } }

            .meta { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; margin: 14px 2px 28px; color: var(--muted); font-size: 13px; }
            .meta .spacer { flex: 1; }
            .meta button {
                padding: 6px 12px; border: 1px solid var(--border); border-radius: 8px;
                background: var(--surface); color: var(--text); font: inherit; cursor: pointer;
            }
            .meta button:hover { border-color: var(--muted); }
            .meta button[disabled] { opacity: 0.6; cursor: progress; }
            .refresh-error { color: var(--fail); }
            [hidden] { display: none !important; }

            .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; margin: 0 0 32px; padding: 0; list-style: none; }

            .card {
                display: flex; flex-direction: column; gap: 6px;
                padding: 16px 18px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface);
            }
            .card[data-status="warn"] { border-color: color-mix(in srgb, var(--warn) 45%, var(--border)); }
            .card[data-status="fail"] { border-color: color-mix(in srgb, var(--fail) 45%, var(--border)); }
            .card header { display: flex; gap: 10px; align-items: center; justify-content: space-between; }
            .card h2 { margin: 0; font-size: 15px; }
            .card p { margin: 0; color: var(--muted); font-size: 13px; }
            .card .message { color: var(--text); }

            .badge {
                display: inline-flex; gap: 6px; align-items: center; flex: none;
                padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600;
            }
            .badge[data-status="ok"] { background: var(--ok-soft); color: var(--ok); }
            .badge[data-status="warn"] { background: var(--warn-soft); color: var(--warn); }
            .badge[data-status="fail"] { background: var(--fail-soft); color: var(--fail); }
            .badge .dot { width: 7px; height: 7px; }

            .links { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; padding: 0; list-style: none; }
            .links a {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 8px 14px; border: 1px solid var(--border); border-radius: 10px;
                background: var(--surface); font-size: 14px; text-decoration: none;
            }
            .links a:hover { border-color: var(--muted); }
            .links a span { color: var(--muted); }

            @media (prefers-reduced-motion: reduce) { .dot.pulse::after { animation: none; } }
        </style>
    </head>
    <body>
        <main id="health" data-status="{{ $status->value }}" data-refresh-seconds="{{ $refreshSeconds }}">
            <p class="eyebrow">{{ config('app.name') }} · {{ __('health.page.title') }}</p>

            <section class="overall" data-status="{{ $status->value }}" aria-live="polite">
                <span class="dot pulse" data-status="{{ $status->value }}" aria-hidden="true"></span>
                <div>
                    <h1>{{ __('health.page.overall.'.$status->value) }}</h1>
                    <p>{{ __('health.page.overall_hint.'.$status->value) }}</p>
                </div>
            </section>

            <div class="meta">
                <time datetime="{{ $checkedAt }}" data-checked-at>{{ __('health.page.checked_at', ['time' => $checkedAt]) }}</time>
                <span>{{ __('health.page.refresh', ['seconds' => $refreshSeconds]) }}</span>
                <span class="refresh-error" data-refresh-error hidden>{{ __('health.page.refresh_failed') }}</span>
                <span class="spacer"></span>
                <button type="button" data-refresh-now>{{ __('health.page.refresh_now') }}</button>
            </div>

            <ul class="grid">
                @foreach ($checks as $name => $check)
                    <li class="card" data-check="{{ $name }}" data-status="{{ $check['status']->value }}">
                        <header>
                            <h2>{{ trans()->has("health.page.checks.{$name}.label") ? __("health.page.checks.{$name}.label") : \Illuminate\Support\Str::headline($name) }}</h2>
                            <span class="badge" data-status="{{ $check['status']->value }}">
                                <span class="dot" data-status="{{ $check['status']->value }}" aria-hidden="true"></span>
                                {{ __('health.page.status.'.$check['status']->value) }}
                            </span>
                        </header>
                        @if ($check['message'])
                            <p class="message">{{ $check['message'] }}</p>
                        @elseif (trans()->has("health.page.checks.{$name}.description"))
                            <p>{{ __("health.page.checks.{$name}.description") }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>

            <p class="eyebrow">{{ __('health.page.links_title') }}</p>
            <ul class="links">
                @foreach ($links as $name => $url)
                    <li><a href="{{ $url }}" target="_blank" rel="noopener">{{ __("health.page.links.{$name}") }} <span aria-hidden="true">↗</span></a></li>
                @endforeach
            </ul>
        </main>

        <script>
            (() => {
                const seconds = Number(document.getElementById('health').dataset.refreshSeconds) * 1000;
                let timer;

                const showCheckedAt = () => {
                    const element = document.querySelector('[data-checked-at]');
                    const checkedAt = new Date(element.getAttribute('datetime'));

                    if (! Number.isNaN(checkedAt.getTime())) {
                        element.textContent = @json(__('health.page.checked_at', ['time' => ':time']))
                            .replace(':time', checkedAt.toLocaleTimeString());
                    }
                };

                const refresh = async () => {
                    clearTimeout(timer);
                    const button = document.querySelector('[data-refresh-now]');
                    button.disabled = true;

                    try {
                        const response = await fetch(location.pathname, { headers: { Accept: 'text/html' }, cache: 'no-store' });
                        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                        const fresh = page.getElementById('health');

                        if (! fresh) {
                            throw new Error('Unexpected response');
                        }

                        document.getElementById('health').replaceWith(fresh);
                        document.title = page.title;
                        showCheckedAt();
                        bind();
                    } catch {
                        document.querySelector('[data-refresh-error]').hidden = false;
                        button.disabled = false;
                    }

                    schedule();
                };

                const schedule = () => {
                    clearTimeout(timer);

                    if (! document.hidden) {
                        timer = setTimeout(refresh, seconds);
                    }
                };

                const bind = () => document.querySelector('[data-refresh-now]').addEventListener('click', refresh);

                document.addEventListener('visibilitychange', () => document.hidden ? clearTimeout(timer) : refresh());
                showCheckedAt();
                bind();
                schedule();
            })();
        </script>
    </body>
</html>
