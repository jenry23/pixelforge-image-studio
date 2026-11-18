<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Money;
use App\Reporting\BillingExport;
use Illuminate\Testing\TestResponse;

function csvOf(TestResponse $response): array
{
    $body = $response->streamedContent();
    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_filter(explode("\n", trim($body))));

    return $rows;
}

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->user = User::factory()->create(['name' => 'Sam Rivera']);

    Generation::factory()->count(3)->succeeded()->create([
        'user_id' => $this->user->id,
        'billed_on_utc' => now('UTC')->toDateString(),
    ]);
});

it('hides every export from a user who is not an admin', function (string $route) {
    // The admin middleware 404s rather than 403s, so the admin area stays unadvertised.
    $this->actingAs($this->user)->get(route($route))->assertNotFound();
})->with(['admin.report.export.daily', 'admin.report.export.users', 'admin.report.export.workbook']);

it('refuses every export to a guest', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['admin.report.export.daily', 'admin.report.export.users', 'admin.report.export.workbook']);

it('exports the daily breakdown as csv', function () {
    $response = $this->actingAs($this->admin)->get(route('admin.report.export.daily'));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload();

    $rows = csvOf($response);

    expect($rows[0])->toBe(BillingExport::DAILY_HEADER)
        // The default window is 14 days, and every one of them is a row.
        ->and($rows)->toHaveCount(15);
});

it('keeps nanodollar precision in the csv', function () {
    $response = $this->actingAs($this->admin)->get(route('admin.report.export.daily'));
    $rows = csvOf($response);

    $today = collect($rows)->firstWhere(0, now('UTC')->toDateString());
    $charged = (int) Generation::query()->sum('charged_nanodollars');

    // The exact integer survives, and the USD column is the full 9dp string.
    expect((int) $today[3])->toBe($charged)
        ->and($today[2])->toBe(Money::fromNanodollars($charged)->toUsdString())
        ->and($today[2])->toMatch('/^\d+\.\d{9}$/');
});

it('exports the same rows and totals whichever table page is open', function () {
    // Enough users that the By user table spans more than one page.
    foreach (range(1, 10) as $i) {
        $extra = User::factory()->create();
        Generation::factory()->succeeded()->create([
            'user_id' => $extra->id,
            'billed_on_utc' => now('UTC')->toDateString(),
        ]);
    }

    $fromPageOne = csvOf($this->actingAs($this->admin)->get(route('admin.report.export.users')));
    $fromPageTwo = csvOf($this->actingAs($this->admin)->get(
        route('admin.report.export.users', ['days_page' => 2, 'users_page' => 2]),
    ));

    expect($fromPageTwo)->toBe($fromPageOne)
        // 11 users plus the header -- the whole period, not one page of eight.
        ->and($fromPageOne)->toHaveCount(12);

    $dailyOne = csvOf($this->actingAs($this->admin)->get(route('admin.report.export.daily')));
    $dailyTwo = csvOf($this->actingAs($this->admin)->get(
        route('admin.report.export.daily', ['days_page' => 3]),
    ));

    expect($dailyTwo)->toBe($dailyOne);
});

it('exports a workbook with a sheet per view', function () {
    $response = $this->actingAs($this->admin)->get(route('admin.report.export.workbook'));

    $response->assertOk()->assertDownload();

    $path = tempnam(sys_get_temp_dir(), 'xlsx-test');
    file_put_contents($path, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $workbook = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    unlink($path);

    expect($workbook)->toContain('Summary')
        ->toContain('Daily breakdown')
        ->toContain('By user');
});

it('neutralises a name a spreadsheet would read as a formula', function () {
    User::factory()->create(['name' => '=1+1']);
    $sneaky = User::query()->where('name', '=1+1')->sole();

    Generation::factory()->succeeded()->create([
        'user_id' => $sneaky->id,
        'billed_on_utc' => now('UTC')->toDateString(),
    ]);

    $rows = csvOf($this->actingAs($this->admin)->get(route('admin.report.export.users')));

    expect(collect($rows)->pluck(0))->toContain("'=1+1")
        ->not->toContain('=1+1');
});
