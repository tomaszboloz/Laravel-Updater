<x-filament-panels::page>
    @php
        $badge = match ($status['state']) {
            'succeeded' => 'success',
            'failed' => 'danger',
            'queued', 'running' => 'warning',
            default => 'gray',
        };
    @endphp

    <div @if ($busy) wire:poll.5s @endif style="display: grid; gap: 1.5rem;">
        <x-filament::section :heading="__('updater::updater.versions')">
            <dl style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));">
                <div>
                    <dt>{{ __('updater::updater.current') }}</dt>
                    <dd style="font-size: 1.25rem; font-weight: 600;">{{ $current }}</dd>
                </div>
                <div>
                    <dt>{{ __('updater::updater.latest') }}</dt>
                    <dd style="font-size: 1.25rem; font-weight: 600;">{{ $release?->version() ?? __('updater::updater.up_to_date') }}</dd>
                </div>
                <div>
                    <dt>{{ __('updater::updater.state') }}</dt>
                    <dd style="display: flex; gap: .5rem; align-items: center;">
                        <x-filament::badge :color="$badge">{{ __('updater::updater.states.'.$status['state']) }}</x-filament::badge>
                        @if ($status['version'])
                            <span>{{ $status['version'] }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($error)
                <p role="alert" style="margin-top: 1rem; color: rgb(var(--danger-600, 220 38 38));">{{ $error }}</p>
            @endif

            @if ($status['state'] === 'failed' && $status['message'])
                <p role="alert" style="margin-top: 1rem; white-space: pre-line;">{{ $status['message'] }}</p>
            @endif
        </x-filament::section>

        @if ($release)
            <x-filament::section :heading="__('updater::updater.release_notes', ['version' => $release->version()])">
                @if ($release->publishedAt)
                    <p><time datetime="{{ $release->publishedAt->toIso8601String() }}">{{ $release->publishedAt->toFormattedDayDateString() }}</time></p>
                @endif

                <div class="fi-prose">{!! $notes !!}</div>

                @if ($release->url)
                    <p style="margin-top: 1rem;">
                        <x-filament::link :href="$release->url" target="_blank" rel="noopener noreferrer">{{ __('updater::updater.open_release') }}</x-filament::link>
                    </p>
                @endif
            </x-filament::section>
        @endif

        @if ($status['log'] !== [])
            <x-filament::section :heading="__('updater::updater.log')" collapsible>
                <pre aria-live="polite" tabindex="0" style="max-height: 28rem; overflow: auto; font-size: .75rem; line-height: 1.5; white-space: pre-wrap;">{{ implode("\n", $status['log']) }}</pre>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
