<footer class="border-t border-gray-200 px-4 py-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <span>© {{ now()->year }} <a href="https://codespare.in/">Codespare Technologies</a>. All rights reserved.</span>

        @if ($githubVersion['available'] ?? false)
            <span>
                {{ __('app.brand.latest_version') }}:
                <span class="font-medium">{{ substr($githubVersion['sha'], 0, 12) }}</span>
                <span>({{ \Illuminate\Support\Carbon::parse($githubVersion['date'])->timezone(config('app.timezone'))->format('M j, Y') }})</span>
            </span>
        @else
            <span>{{ __('app.brand.version_unavailable') }}</span>
        @endif
    </div>
</footer>
