<?php

declare(strict_types=1);

namespace LaravelHyperf\Scheduling\Console;

use Hyperf\Collection\Collection;
use Hyperf\Command\Command;
use Hyperf\Coroutine\Concurrent;
use Hyperf\Coroutine\Waiter;
use LaravelHyperf\Cache\Contracts\Factory as CacheFactory;
use LaravelHyperf\Container\Contracts\Container;
use LaravelHyperf\Context\ApplicationContext;
use LaravelHyperf\Foundation\Exceptions\Contracts\ExceptionHandler;
use LaravelHyperf\Scheduling\CallbackEvent;
use LaravelHyperf\Scheduling\Event;
use LaravelHyperf\Scheduling\Events\ScheduledTaskFailed;
use LaravelHyperf\Scheduling\Events\ScheduledTaskFinished;
use LaravelHyperf\Scheduling\Events\ScheduledTaskSkipped;
use LaravelHyperf\Scheduling\Events\ScheduledTaskStarting;
use LaravelHyperf\Scheduling\Schedule;
use LaravelHyperf\Support\Carbon;
use LaravelHyperf\Support\Facades\Date;
use LaravelHyperf\Support\Sleep;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

class ScheduleRunCommand extends Command
{
    protected Container $app;

    /**
     * The console command signature.
     */
    protected ?string $signature = 'schedule:run
        {--once : Run only once without looping}
        {--concurrency=60 : The number of background tasks to process at once}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'Run the scheduled commands';

    /**
     * Check if any events ran.
     */
    protected bool $eventsRan = false;

    /**
     * Check if scheduler should stop.
     */
    protected bool $shouldStop = false;

    /**
     * Last time the stopped state was checked.
     */
    protected ?Carbon $lastChecked = null;

    /**
     * The concurrent instance.
     */
    protected ?Concurrent $concurrent = null;

    /**
     * Create a new command instance.
     */
    public function __construct(
        protected Schedule $schedule,
        protected EventDispatcherInterface $dispatcher,
        protected CacheFactory $cache,
        protected ExceptionHandler $handler,
    ) {
        parent::__construct();

        $this->app = ApplicationContext::getContainer();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->concurrent = new Concurrent(
            (int) $this->option('concurrency')
        );

        $this->newLine();

        if ($this->option('once') ?: false) {
            $this->runOnce();
            return;
        }

        $this->clearShouldStop();

        $noEventsAlerted = false;
        while (! $this->shouldStop()) {
            $this->runEvents(
                $this->schedule->dueEvents($this->app),
                Date::now()
            );

            if (! $this->eventsRan && ! $noEventsAlerted) {
                $this->info('No scheduled commands are ready to run, waiting...');
                $noEventsAlerted = true;
            }

            Sleep::usleep(100000);
        }

        $this->stop();
    }

    protected function stop(): void
    {
        $this->info('Stopping the scheduling...');

        while (true) {
            if ($this->concurrent->isEmpty()) {
                $this->info('Done.');
                break;
            }

            Sleep::usleep(100000);
        }
    }

    protected function runOnce(): void
    {
        (new Waiter(-1))->wait(
            fn () => $this->runEvents(
                $this->schedule->dueEvents($this->app),
                Date::now()
            )
        );

        if (! $this->eventsRan) {
            $this->info('No scheduled commands are ready to run.');
        }
    }

    protected function runEvents(Collection $events, Carbon $startedAt): void
    {
        foreach ($events as $event) {
            if ($event->isRepeatable() && $event->lastChecked && ! $event->shouldRepeatNow()) {
                continue;
            }

            if (! $event->filtersPass($this->app)) {
                $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));

                continue;
            }

            $runEvent = fn () => $event->onOneServer
                ? $this->runSingleServerEvent($event, $startedAt)
                : $this->runEvent($event);

            if ($event->runInBackground) {
                $this->concurrent->create($runEvent);
                continue;
            }

            $runEvent();
        }
    }

    /**
     * Run the given single server event.
     */
    protected function runSingleServerEvent(Event $event, Carbon $startedAt): void
    {
        if ($this->schedule->serverShouldRun($event, $startedAt)) {
            $this->runEvent($event);
        } else {
            $this->info(sprintf(
                'Skipping [%s], as command already run on another server.',
                $event->getSummaryForDisplay()
            ));
        }
    }

    /**
     * Run the given event.
     */
    protected function runEvent(Event $event): void
    {
        $summary = $event->getSummaryForDisplay();

        $command = $event instanceof CallbackEvent
            ? $summary
            : $event->command;

        $description = sprintf(
            '<fg=gray>%s</> Running [%s]%s',
            Carbon::now()->format('Y-m-d H:i:s'),
            $command,
            $event->runInBackground ? ' in background (coroutine)' : '',
        );

        $this->eventsRan = true;

        $this->line($description);
        $this->dispatcher->dispatch(new ScheduledTaskStarting($event));

        $start = microtime(true);

        try {
            $event->run($this->app);

            $this->dispatcher->dispatch(new ScheduledTaskFinished(
                $event,
                round(microtime(true) - $start, 2)
            ));

            $this->eventsRan = true;
        } catch (Throwable $e) {
            $this->dispatcher->dispatch(new ScheduledTaskFailed($event, $e));
            $this->handler->report($e);
        }

        $finishDescription = sprintf(
            '<fg=gray>%s</> %s [%s] <fg=gray>%sms</>',
            Carbon::now()->format('Y-m-d H:i:s'),
            $event->exitCode == 0 ? '<info>Finished</info>' : '<error>Failed</error>',
            $command,
            round(microtime(true) - $start, 2),
        );

        $this->line($finishDescription);
    }

    /**
     * Determine if the schedule run should be interrupted.
     */
    protected function shouldStop(): bool
    {
        if (! $this->lastChecked) {
            $this->lastChecked = Date::now();
        }

        if ($this->shouldStop || $this->lastChecked->diffInSeconds() < 1) {
            return $this->shouldStop;
        }

        $this->lastChecked = Date::now();

        /* @phpstan-ignore-next-line */
        return $this->shouldStop = $this->cache->get('laravel-hyperf:schedule:stop', false);
    }

    /**
     * Clear the stop cache.
     */
    protected function clearShouldStop(): void
    {
        /* @phpstan-ignore-next-line */
        $this->cache->delete('laravel-hyperf:schedule:stop');

        $this->shouldStop = false;
    }
}
