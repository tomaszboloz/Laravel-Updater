<x-filament-panels::page>
    @php
        $badge = match ($status['state']) {
            'succeeded' => 'success',
            'failed' => 'danger',
            'queued', 'running' => 'warning',
            default => 'gray',
        };
        $updates = array_filter($packages, static fn ($package) => $package->hasUpdate());
        $cell = 'padding: .625rem .75rem; text-align: start; vertical-align: middle;';
    @endphp

    {{--
        Progress is polled with plain fetch() from a small JSON endpoint, not with Livewire: while Composer swaps
        vendor/ or the site is in maintenance mode a request may fail, and a failed poll is simply retried.
        The page reloads once the background task has finished.
    --}}
    <div
        x-data="{
            busy: @js($busy),
            state: @js($status['state']),
            step: @js($status['step']),
            log: @js($status['log']),
            async poll() {
                try {
                    const response = await fetch(@js(route('updater.status')), { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });

                    if (response.ok) {
                        const status = await response.json();
                        this.step = status.step;
                        this.log = status.log;
                        this.state = status.state;

                        if (! status.busy) {
                            window.location.reload();

                            return;
                        }
                    }
                } catch (error) {}

                setTimeout(() => this.poll(), 3000);
            },
        }"
        x-init="if (busy) setTimeout(() => poll(), 3000)"
        {{-- A new key when "busy" changes makes Livewire replace the element, so Alpine starts polling after an action. --}}
        wire:key="updater-progress-{{ $busy ? 'busy' : 'idle' }}"
        style="display: grid; gap: 1.5rem;"
    >
        <div x-show="busy" x-cloak role="status" aria-live="polite" style="display: flex; gap: .75rem; align-items: center;">
            <x-filament::loading-indicator style="width: 1.25rem; height: 1.25rem;" />
            <span>{{ __('updater::updater.running_in_background') }}</span>
            <span x-text="step" style="font-family: monospace; font-size: .8125rem;"></span>
        </div>

        <x-filament::section :heading="__('updater::updater.application')">
            @if ($this->updateApplicationAction->isVisible())
                <x-slot name="afterHeader">{{ $this->updateApplicationAction }}</x-slot>
            @endif

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
                    <dd style="display: flex; gap: .5rem; align-items: center; flex-wrap: wrap;">
                        <x-filament::badge :color="$badge">{{ __('updater::updater.states.'.$status['state']) }}</x-filament::badge>
                        @if ($status['version'])
                            <span>{{ $status['version'] }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($error)
                <p role="alert" style="margin-top: 1rem;">{{ $error }}</p>
            @endif

            @if ($status['state'] === 'failed' && $status['message'])
                <p role="alert" style="margin-top: 1rem; white-space: pre-line;">{{ $status['message'] }}</p>
            @endif

            @if ($release)
                <div style="margin-top: 1.5rem;">
                    <h3 style="font-weight: 600;">{{ __('updater::updater.release_notes', ['version' => $release->version()]) }}</h3>
                    <div class="fi-prose">{!! $notes !!}</div>
                    @if ($release->url)
                        <x-filament::link :href="$release->url" target="_blank" rel="noopener noreferrer">{{ __('updater::updater.open_release') }}</x-filament::link>
                    @endif
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('updater::updater.packages.title')" :description="trans_choice('updater::updater.packages.summary', count($updates), ['count' => count($updates), 'total' => count($packages)])">
            <p style="margin-bottom: 1rem;">
                {{ $check['checked_at'] ? __('updater::updater.packages.checked_at', ['date' => \Illuminate\Support\Carbon::parse($check['checked_at'])->diffForHumans()]) : __('updater::updater.packages.never_checked') }}
            </p>

            @if ($check['error'])
                <p role="alert" style="margin-bottom: 1rem; white-space: pre-line;">{{ $check['error'] }}</p>
            @endif

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                    <caption class="sr-only">{{ __('updater::updater.packages.title') }}</caption>
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(127, 127, 127, .3);">
                            <th scope="col" style="{{ $cell }}">{{ __('updater::updater.packages.name') }}</th>
                            <th scope="col" style="{{ $cell }}">{{ __('updater::updater.current') }}</th>
                            <th scope="col" style="{{ $cell }}">{{ __('updater::updater.latest') }}</th>
                            <th scope="col" style="{{ $cell }}"><span class="sr-only">{{ __('updater::updater.packages.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($packages as $package)
                            <tr wire:key="package-{{ $package->name }}" style="border-bottom: 1px solid rgba(127, 127, 127, .15);">
                                <th scope="row" style="{{ $cell }} font-weight: 500;">
                                    {{ $package->name }}
                                    @if ($package->private)
                                        <x-filament::badge color="info" size="sm" style="display: inline-flex; margin-inline-start: .375rem;">{{ __('updater::updater.packages.private') }}</x-filament::badge>
                                    @endif
                                    @if ($package->dev)
                                        <x-filament::badge color="gray" size="sm" style="display: inline-flex; margin-inline-start: .375rem;">dev</x-filament::badge>
                                    @endif
                                </th>
                                <td style="{{ $cell }}">{{ $package->version }}</td>
                                <td style="{{ $cell }}">
                                    @if ($package->hasUpdate())
                                        <x-filament::badge :color="$package->status === \TomaszBoloz\LaravelUpdater\Packages\Package::MAJOR ? 'danger' : 'warning'" size="sm" style="display: inline-flex;">{{ $package->latest }}</x-filament::badge>
                                        @if ($package->status === \TomaszBoloz\LaravelUpdater\Packages\Package::MAJOR)
                                            <span style="font-size: .75rem;">{{ __('updater::updater.packages.major') }}</span>
                                        @endif
                                    @else
                                        <span>—</span>
                                    @endif
                                </td>
                                <td style="{{ $cell }} text-align: end;">
                                    @php($rowAction = ($this->updatePackageAction)(['package' => $package->name]))
                                    @if ($package->canUpdate() && $rowAction->isVisible())
                                        {{ $rowAction }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <div x-show="log.length > 0" @if ($status['log'] === []) x-cloak @endif>
            <x-filament::section :heading="__('updater::updater.log')" collapsible>
                <pre tabindex="0" x-text="log.join('\n')" style="max-height: 28rem; overflow: auto; font-size: .75rem; line-height: 1.5; white-space: pre-wrap;">{{ implode("\n", $status['log']) }}</pre>
            </x-filament::section>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
