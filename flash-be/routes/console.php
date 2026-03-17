<?php

use App\Models\Flash;
use App\Models\FlashExample;
use App\Models\FlashGroup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('flash:clear {--force : Skip the confirmation prompt}', function (): int {
    if (! $this->option('force') && ! $this->confirm('This will permanently delete all flashes, sentences, and groups. Continue?')) {
        $this->line('Aborted.');

        return Command::SUCCESS;
    }

    $counts = DB::transaction(static function (): array {
        $exampleCount = FlashExample::query()->count();
        $flashCount = Flash::query()->count();
        $groupCount = FlashGroup::query()->count();

        FlashExample::query()->delete();
        Flash::query()->delete();
        FlashGroup::query()->delete();

        return [
            'examples' => $exampleCount,
            'flashes' => $flashCount,
            'groups' => $groupCount,
        ];
    });

    $this->line(sprintf(
        'Cleared %d flashes, %d sentences, %d groups.',
        $counts['flashes'],
        $counts['examples'],
        $counts['groups'],
    ));

    return Command::SUCCESS;
})->purpose('Delete all flashes, example sentences, and flash groups.');
