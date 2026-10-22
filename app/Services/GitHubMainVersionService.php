<?php

namespace App\Services;

use Composer\CaBundle\CaBundle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubMainVersionService
{
    /**
     * Get the latest commit on the configured GitHub branch.
     *
     * @return array{available: bool, sha?: string, date?: string}
     */
    public function latest(): array
    {
        $repository = trim((string) config('services.github_version.repository'));
        $branch = trim((string) config('services.github_version.branch', 'main'));

        if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository) || $branch === '') {
            return ['available' => false];
        }

        $cacheKey = 'github-main-version:'.hash('sha256', $repository.'/'.$branch);

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($repository, $branch): array {
            try {
                $response = Http::connectTimeout(3)
                    ->timeout(5)
                    ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                    ->acceptJson()
                    ->withHeaders([
                        'User-Agent' => 'Codespare-Technologies-App-Version-Check',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->get("https://api.github.com/repos/{$repository}/commits/".rawurlencode($branch));

                $sha = $response->json('sha');
                $date = $response->json('commit.committer.date');

                if (! $response->successful() || ! is_string($sha) || ! preg_match('/^[a-f0-9]{40}$/i', $sha) || ! is_string($date)) {
                    return ['available' => false];
                }

                return [
                    'available' => true,
                    'sha' => $sha,
                    'date' => $date,
                ];
            } catch (Throwable) {
                return ['available' => false];
            }
        });
    }
}
