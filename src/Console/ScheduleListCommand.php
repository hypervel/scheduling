<?php

declare(strict_types=1);

namespace Hypervel\Scheduling\Console;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Exception;
use Hyperf\Collection\Collection;
use Hyperf\Command\Command;
use Hypervel\Scheduling\CallbackEvent;
use Hypervel\Scheduling\Event;
use Hypervel\Scheduling\Schedule;
use Hypervel\Support\Carbon;
use Hypervel\Support\Traits\HasLaravelStyleCommand;
use ReflectionClass;
use ReflectionFunction;
use Symfony\Component\Console\Terminal;

class ScheduleListCommand extends Command
{
    use HasLaravelStyleCommand;

    /**
     * The console command signature.
     */
    protected ?string $signature = 'schedule:list
        {--timezone= : The timezone that times should be displayed in}
        {--next : Sort the listed tasks by their next due date}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'List all scheduled tasks';

    /**
     * The terminal width resolver callback.
     */
    protected static ?Closure $terminalWidthResolver = null;

    public function __construct(
        protected Schedule $schedule
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @throws Exception
     */
    public function handle()
    {
        $events = new Collection($this->schedule->events());

        if ($events->isEmpty()) {
            $this->info('No scheduled tasks have been defined.');

            return;
        }

        $terminalWidth = self::getTerminalWidth();

        $expressionSpacing = $this->getCronExpressionSpacing($events);

        $repeatExpressionSpacing = $this->getRepeatExpressionSpacing($events);

        $timezone = new DateTimeZone($this->option('timezone') ?? config('app.timezone'));

        $events = $this->sortEvents($events, $timezone);

        $events = $events->map(function ($event) use ($terminalWidth, $expressionSpacing, $repeatExpressionSpacing, $timezone) {
            return $this->listEvent($event, $terminalWidth, $expressionSpacing, $repeatExpressionSpacing, $timezone);
        });

        $this->line(
            $events->flatten()->filter()->prepend('')->push('')->toArray()
        );
    }

    /**
     * Get the spacing to be used on each event row.
     *
     * @return array<int, int>
     */
    private function getCronExpressionSpacing(Collection $events): array
    {
        $rows = $events->map(fn ($event) => array_map(mb_strlen(...), preg_split('/\s+/', $event->expression)));

        return (new Collection($rows[0] ?? []))->keys()->map(fn ($key) => $rows->max($key))->all();
    }

    /**
     * Get the spacing to be used on each event row.
     */
    private function getRepeatExpressionSpacing(Collection $events): int
    {
        return $events->map(fn ($event) => mb_strlen($this->getRepeatExpression($event)))->max();
    }

    /**
     * List the given even in the console.
     */
    private function listEvent(Event $event, int $terminalWidth, array $expressionSpacing, int $repeatExpressionSpacing, DateTimeZone $timezone): array
    {
        $expression = $this->formatCronExpression($event->expression, $expressionSpacing);

        $repeatExpression = str_pad($this->getRepeatExpression($event), $repeatExpressionSpacing);

        $command = $event->command ?? '';

        $description = $event->description ?? '';

        if ($event instanceof CallbackEvent) {
            $command = $event->getSummaryForDisplay();

            if (in_array($command, ['Closure', 'Callback'])) {
                $command = 'Closure at: ' . $this->getClosureLocation($event);
            }
        }

        $command = mb_strlen($command) > 1 ? "{$command} " : '';

        $nextDueDateLabel = 'Next Due:';

        $nextDueDate = $this->getNextDueDateForEvent($event, $timezone);

        $nextDueDate = $this->output->isVerbose()
            ? $nextDueDate->format('Y-m-d H:i:s P')
            : $nextDueDate->diffForHumans();

        $hasMutex = $event->mutex->exists($event) ? 'Has Mutex › ' : '';

        $dots = str_repeat('.', max(
            $terminalWidth - mb_strlen($expression . $repeatExpression . $command . $nextDueDateLabel . $nextDueDate . $hasMutex) - 8,
            0
        ));

        // Highlight the parameters...
        $command = preg_replace('#(php artisan [\w\-:]+) (.+)#', '$1 <fg=yellow;options=bold>$2</>', $command);

        return [sprintf(
            '  <fg=yellow>%s</> <fg=#6C7280>%s</> %s<fg=#6C7280>%s %s%s %s</>',
            $expression,
            $repeatExpression,
            $command,
            $dots,
            $hasMutex,
            $nextDueDateLabel,
            $nextDueDate
        ), $this->output->isVerbose() && mb_strlen($description) > 1 ? sprintf(
            '  <fg=#6C7280>%s%s %s</>',
            str_repeat(' ', mb_strlen($expression) + 2),
            '⇁',
            $description
        ) : ''];
    }

    /**
     * Get the repeat expression for an event.
     */
    private function getRepeatExpression(Event $event): string
    {
        return $event->isRepeatable() ? "{$event->repeatSeconds}s " : '';
    }

    /**
     * Sort the events by due date if option set.
     */
    private function sortEvents(Collection $events, DateTimeZone $timezone): Collection
    {
        return $this->option('next')
            ? $events->sortBy(fn ($event) => $this->getNextDueDateForEvent($event, $timezone))
            : $events;
    }

    /**
     * Get the next due date for an event.
     */
    private function getNextDueDateForEvent(Event $event, DateTimeZone $timezone): Carbon
    {
        $nextDueDate = Carbon::instance(
            (new CronExpression($event->expression))
                ->getNextRunDate(Carbon::now()->setTimezone($event->timezone))
                ->setTimezone($timezone)
        );

        if (! $event->isRepeatable()) {
            return $nextDueDate;
        }

        $previousDueDate = Carbon::instance(
            (new CronExpression($event->expression))
                ->getPreviousRunDate(Carbon::now()->setTimezone($event->timezone), allowCurrentDate: true)
                ->setTimezone($timezone)
        );

        $now = Carbon::now()->setTimezone($event->timezone);

        if (! $now->copy()->startOfMinute()->eq($previousDueDate)) {
            return $nextDueDate;
        }

        return $now
            ->endOfSecond()
            ->ceilSeconds($event->repeatSeconds);
    }

    /**
     * Format the cron expression based on the spacing provided.
     *
     * @param array<int, int> $spacing
     */
    private function formatCronExpression(string $expression, array $spacing): string
    {
        $expressions = preg_split('/\s+/', $expression);

        return (new Collection($spacing))
            ->map(fn ($length, $index) => str_pad($expressions[$index], $length))
            ->implode(' ');
    }

    /**
     * Get the file and line number for the event closure.
     */
    private function getClosureLocation(CallbackEvent $event): string
    {
        $callback = (new ReflectionClass($event))->getProperty('callback')->getValue($event);

        if ($callback instanceof Closure) {
            $function = new ReflectionFunction($callback);

            return sprintf(
                '%s:%s',
                str_replace($this->app->basePath() . DIRECTORY_SEPARATOR, '', $function->getFileName() ?: ''), /* @phpstan-ignore-line */
                $function->getStartLine()
            );
        }

        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback)) {
            $className = is_string($callback[0]) ? $callback[0] : $callback[0]::class;

            return sprintf('%s::%s', $className, $callback[1]);
        }

        return sprintf('%s::__invoke', $callback::class);
    }

    /**
     * Get the terminal width.
     */
    public static function getTerminalWidth(): int
    {
        return is_null(static::$terminalWidthResolver)
            ? (new Terminal())->getWidth()
            : call_user_func(static::$terminalWidthResolver);
    }

    /**
     * Set a callback that should be used when resolving the terminal width.
     */
    public static function resolveTerminalWidthUsing(?Closure $resolver)
    {
        static::$terminalWidthResolver = $resolver;
    }
}
