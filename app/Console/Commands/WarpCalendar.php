<?php

namespace App\Console\Commands;

use App\Models\SavedEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\GoogleCalendar\Event;

class WarpCalendar extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:warp
        {--max-warp=30 : Maximum amount to shift events, in minutes.}
        {--warp-days=7 : Number of days ahead to fetch events to warp.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Modifies your Google Calendar event times by a random amount, within a given threshold.';

    /**
     * Minimum amount an event time can warp, in minutes.
     *
     * @var int
     */
    protected $minWarp = 5;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting calendar warp...');
        $this->line(implode(' | ', [
            'Max warp: '. $this->option('max-warp').' min',
            'Min warp: '. $this->minWarp.' min',
            'Events to warp: '. $this->option('warp-days').' days',
        ]));

        $this->info('Fetching events from your calendar...');
        $events = $this->fetchEvents();

        $this->info('Backing up events...');
        $this->backupEvents($events);

        $this->info('Warping events now...');
        $this->warp($events);

        return 0;
    }

    private function fetchEvents(): Collection
    {
        return $this->filterWarpedEvents(
            Event::get(null, Carbon::now()->addDays($this->option('warp-days')))
        );
    }

    private function backupEvents(Collection $events) {
        SavedEvent::insert($events->map(function(Event $event) {
            return [
                'name' => $event->name,
                'remote_id' => $event->id,
                'original_start' => $event->startDateTime,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all());
    }

    private function filterWarpedEvents(Collection $events): Collection {
        $existing_event_ids = SavedEvent::whereIn('remote_id', $events->map(fn(Event $event) => $event->id))->get();

        return $events->filter(fn(Event $event) => ! $existing_event_ids->contains('remote_id', $event->id));
    }

    private function warp(Collection $events) {
        if($events->count() === 0) {
            $this->info('No events found.');
            return;
        }
        $this->info('Found ' . $events->count(). ' events to warp.');

        $bar = $this->output->createProgressBar($events->count());
        $bar->start();

        $events->map(function (Event $event) use ($bar) {
            $this->line('');
            $this->info($event->name);
            $this->line('Before: '. $event->startDateTime);

            $event->update(['startDateTime' => $event->startDateTime->subMinutes($this->getRandomWarpAmount())]);
            $this->line('After: '. $event->startDateTime);

            $bar->advance();
        });
        $bar->finish();
        $this->line('');
    }

    private function getRandomWarpAmount(): int {
        return $this->ceilTo(
            rand( $this->minWarp, $this->option('max-warp')),
            $this->minWarp
        );
    }

    /**
     * Rounds up to an integer, then to the nearest multiple of 5
     *
     * @link https://stackoverflow.com/questions/4133859/round-up-to-nearest-multiple-of-five-in-php
     */
    private function ceilTo(int|float $n, int $ceil = 5): int {
        return (ceil($n) % $ceil === 0)
            ? ceil($n)
            : round(($n + $ceil / 2) / $ceil) * $ceil;
    }
}
