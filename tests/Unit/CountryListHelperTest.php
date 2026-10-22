<?php

use App\Helpers\Helpers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

it('returns countries with iso codes from the local dataset', function (): void {
    $countries = Helpers::getCountriesWithCodes();

    expect($countries)
        ->not->toBeEmpty()
        ->toHaveKey('IN')
        ->toHaveKey('US')
        ->and($countries['IN'])->toBe('India')
        ->and($countries['US'])->toBe('United States');
});

it('loads currencies from the database when the package cache contains an invalid value', function (): void {
    $table = 'world_test_currencies';
    $cacheKey = 'currencies_code_name_en';
    $originalTable = config('world.migrations.currencies.table_name');
    $originalConnection = config('world.connection');

    config([
        'world.migrations.currencies.table_name' => $table,
        'world.connection' => config('database.default'),
    ]);

    Schema::connection((string) config('database.default'))->create($table, function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->string('code');
        $blueprint->string('name');
    });

    try {
        Schema::connection((string) config('database.default'))->getConnection()->table($table)->insert([
            ['code' => 'USD', 'name' => 'US Dollar'],
            ['code' => 'EUR', 'name' => 'Euro'],
        ]);
        Cache::put($cacheKey, new stdClass);

        expect(Helpers::getCurrencies())
            ->toHaveKey('EUR', 'Euro')
            ->toHaveKey('USD', 'US Dollar');
    } finally {
        Cache::forget($cacheKey);
        Schema::connection((string) config('database.default'))->dropIfExists($table);
        config([
            'world.migrations.currencies.table_name' => $originalTable,
            'world.connection' => $originalConnection,
        ]);
    }
});

it('offers currency options when the currency table has not been seeded', function (): void {
    $originalTable = config('world.migrations.currencies.table_name');
    $originalConnection = config('world.connection');

    config([
        'world.migrations.currencies.table_name' => 'unseeded_currencies',
        'world.connection' => config('database.default'),
    ]);

    try {
        expect(Helpers::getCurrencies())
            ->toHaveKey('INR', 'Indian Rupee')
            ->toHaveKey('USD', 'US Dollar');
    } finally {
        config([
            'world.migrations.currencies.table_name' => $originalTable,
            'world.connection' => $originalConnection,
        ]);
    }
});
