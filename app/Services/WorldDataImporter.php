<?php

namespace App\Services;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class WorldDataImporter
{
    private const REQUEST_BATCH_SIZE = 20;

    /**
     * Import countries, states, and cities from the configured public API.
     */
    public function import(): void
    {
        $connection = (string) config('world.connection');
        $countryTable = (string) config('world.migrations.countries.table_name', 'countries');
        $stateTable = (string) config('world.migrations.states.table_name', 'states');
        $cityTable = (string) config('world.migrations.cities.table_name', 'cities');

        foreach ([$countryTable, $stateTable, $cityTable] as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                throw new RuntimeException("World data table [{$table}] is missing. Run migrations first.");
            }
        }

        $countries = $this->requestData('countries/iso');
        $countryCodes = $this->requestData('countries/codes');
        $stateGroups = $this->requestData('countries/states');

        $dialCodes = collect($countryCodes)
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['code'] ?? null))
            ->mapWithKeys(fn (array $row): array => [strtoupper((string) $row['code']) => ltrim((string) ($row['dial_code'] ?? ''), '+')])
            ->all();

        $this->importCountries($connection, $countryTable, $countries, $dialCodes);
        $countryIds = DB::connection($connection)
            ->table($countryTable)
            ->pluck('id', 'iso2')
            ->mapWithKeys(fn (mixed $id, mixed $iso2): array => [strtoupper((string) $iso2) => (int) $id])
            ->all();

        $this->importStates($connection, $stateTable, $stateGroups, $countryIds);
        $stateRows = DB::connection($connection)
            ->table($stateTable)
            ->select(['id', 'country_id', 'name'])
            ->get();

        $statesByCountryAndName = [];
        foreach ($stateRows as $state) {
            $statesByCountryAndName[$state->country_id][mb_strtolower($state->name)] = [
                'id' => (int) $state->id,
                'name' => (string) $state->name,
            ];
        }

        $this->importCities($connection, $cityTable, $stateGroups, $countryIds, $statesByCountryAndName);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requestData(string $path): array
    {
        $response = $this->request($path);
        $data = $response->json('data');

        if ($response->failed() || $response->json('error') !== false || ! is_array($data)) {
            throw new RuntimeException("World data API request [{$path}] returned an invalid response.");
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function request(string $path): Response
    {
        $baseUrl = rtrim((string) config('world.api_url'), '/');

        return Http::acceptJson()
            ->connectTimeout(10)
            ->timeout(45)
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->get("{$baseUrl}/{$path}");
    }

    /**
     * @param  array<int, array<string, mixed>>  $countries
     * @param  array<string, string>  $dialCodes
     */
    private function importCountries(string $connection, string $table, array $countries, array $dialCodes): void
    {
        $existing = DB::connection($connection)->table($table)->pluck('id', 'iso2');
        $existingCodes = $existing->keys()->map(fn (mixed $iso2): string => strtoupper((string) $iso2))->flip()->all();
        $rows = [];

        foreach ($countries as $country) {
            $iso2 = strtoupper(trim((string) ($country['Iso2'] ?? '')));
            $name = trim((string) ($country['name'] ?? ''));
            $iso3 = strtoupper(trim((string) ($country['Iso3'] ?? '')));

            if ($iso2 === '' || $name === '' || isset($existingCodes[$iso2])) {
                continue;
            }

            $rows[] = [
                'iso2' => $iso2,
                'name' => $name,
                'status' => 1,
                'phone_code' => substr($dialCodes[$iso2] ?? '', 0, 5),
                'iso3' => substr($iso3, 0, 3),
                'region' => '',
                'subregion' => '',
            ];
        }

        $this->insertRows($connection, $table, $rows, 250);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stateGroups
     * @param  array<string, int>  $countryIds
     */
    private function importStates(string $connection, string $table, array $stateGroups, array $countryIds): void
    {
        $existingRows = DB::connection($connection)->table($table)->select(['country_id', 'name'])->get();
        $existing = [];
        foreach ($existingRows as $row) {
            $existing[$row->country_id][mb_strtolower($row->name)] = true;
        }

        $rows = [];
        foreach ($stateGroups as $group) {
            $countryIso2 = strtoupper((string) ($group['iso2'] ?? ''));
            $countryId = $countryIds[$countryIso2] ?? null;
            if (! $countryId || ! is_array($group['states'] ?? null)) {
                continue;
            }

            foreach ($group['states'] as $state) {
                if (! is_array($state)) {
                    continue;
                }

                $name = trim((string) ($state['name'] ?? ''));
                if ($name === '' || isset($existing[$countryId][mb_strtolower($name)])) {
                    continue;
                }

                $existing[$countryId][mb_strtolower($name)] = true;
                $rows[] = [
                    'country_id' => $countryId,
                    'name' => $name,
                    'country_code' => substr((string) ($group['iso3'] ?? ''), 0, 3),
                    'state_code' => isset($state['state_code']) ? substr((string) $state['state_code'], 0, 5) : null,
                ];
            }
        }

        $this->insertRows($connection, $table, $rows, 500);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stateGroups
     * @param  array<string, int>  $countryIds
     * @param  array<int, array<string, array{id: int, name: string}>>  $statesByCountryAndName
     */
    private function importCities(string $connection, string $table, array $stateGroups, array $countryIds, array $statesByCountryAndName): void
    {
        $requests = [];
        foreach ($stateGroups as $group) {
            $country = trim((string) ($group['name'] ?? ''));
            $countryIso2 = strtoupper((string) ($group['iso2'] ?? ''));
            $countryId = $countryIds[$countryIso2] ?? null;
            if (! $countryId || ! is_array($group['states'] ?? null)) {
                continue;
            }

            foreach ($group['states'] as $state) {
                $stateName = is_array($state) ? trim((string) ($state['name'] ?? '')) : '';
                $stateKey = mb_strtolower($stateName);
                $stateRecord = $statesByCountryAndName[$countryId][$stateKey] ?? null;
                if ($country !== '' && $stateRecord !== null) {
                    $requests[] = [
                        'country' => $country,
                        'state' => $stateRecord,
                        'country_id' => $countryId,
                        'country_code' => substr((string) ($group['iso3'] ?? ''), 0, 3),
                    ];
                }
            }
        }

        $failedRequests = 0;
        foreach (array_chunk($requests, self::REQUEST_BATCH_SIZE) as $requestBatch) {
            $responses = Http::pool(function (Pool $pool) use ($requestBatch): array {
                $poolRequests = [];
                foreach ($requestBatch as $index => $request) {
                    $poolRequests[$index] = $pool
                        ->as((string) $index)
                        ->acceptJson()
                        ->connectTimeout(10)
                        ->timeout(45)
                        ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                        ->post(rtrim((string) config('world.api_url'), '/').'/countries/state/cities', [
                            'country' => $request['country'],
                            'state' => $request['state']['name'],
                        ]);
                }

                return $poolRequests;
            });

            $rows = [];
            foreach ($requestBatch as $index => $request) {
                $response = $responses[$index] ?? null;
                $cities = $response instanceof Response ? $response->json('data') : null;
                if (! $response instanceof Response || $response->failed() || $response->json('error') !== false || ! is_array($cities)) {
                    $failedRequests++;

                    continue;
                }

                $knownCities = DB::connection($connection)
                    ->table($table)
                    ->where('state_id', $request['state']['id'])
                    ->pluck('name')
                    ->map(fn (mixed $name): string => mb_strtolower((string) $name))
                    ->flip()
                    ->all();

                foreach ($cities as $city) {
                    $name = trim((string) $city);
                    $key = mb_strtolower($name);
                    if ($name === '' || isset($knownCities[$key])) {
                        continue;
                    }

                    $knownCities[$key] = true;
                    $rows[] = [
                        'country_id' => $request['country_id'],
                        'state_id' => $request['state']['id'],
                        'name' => $name,
                        'country_code' => $request['country_code'],
                    ];
                }
            }

            $this->insertRows($connection, $table, $rows, 500);
        }

        if ($failedRequests > 0) {
            $this->report("Cities could not be loaded for {$failedRequests} states; rerun the seeder to retry them.");
        }
    }

    private function report(string $message): void
    {
        logger()->warning($message);
    }

    /**
     * Insert batches using only columns supported by the installed table schema.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertRows(string $connection, string $table, array $rows, int $batchSize): void
    {
        $availableColumns = array_flip(Schema::connection($connection)->getColumnListing($table));

        foreach (array_chunk($rows, $batchSize) as $chunk) {
            $compatibleRows = array_map(
                fn (array $row): array => array_intersect_key($row, $availableColumns),
                $chunk,
            );

            DB::connection($connection)->table($table)->insertOrIgnore($compatibleRows);
        }
    }
}
