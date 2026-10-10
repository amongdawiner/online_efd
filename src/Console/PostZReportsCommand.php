<?php

namespace OnlineEfd\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use OnlineEfd\Facades\Efd;
use OnlineEfd\Models\EfdProfile;
use Throwable;

class PostZReportsCommand extends Command
{
    protected $signature = 'efd:z-report
                            {--date= : Day to report (Y-m-d). Defaults to today in the EFD timezone}
                            {--profile= : Only this efd_profiles.id}';

    protected $description = 'Post the Z-report to TRA for every active, registered EFD profile';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), config('efd.timezone'))
            : now(config('efd.timezone'));

        $failed = 0;

        EfdProfile::query()
            ->where('is_active', true)
            ->whereNotNull('registered_at')
            ->when($this->option('profile'), fn ($q, $id) => $q->whereKey($id))
            ->each(function (EfdProfile $profile) use ($date, &$failed) {
                try {
                    $report = Efd::forProfile($profile)->postZReport($date);
                    $this->info("Profile #{$profile->id}: Z-report {$report->znum} {$report->status}");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Profile #{$profile->id}: {$e->getMessage()}");
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
