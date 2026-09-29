<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Command;

use Gplanchat\Bridge\Temporal\Store\TaskQueueKind;
use Gplanchat\Bridge\Temporal\Store\TemporalTaskQueueProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Does a worker poll each role's Temporal task queue. Without the activity worker an execution
 * stops at its first activity and nothing fails: this exit code is what to alert on.
 *
 * The roles are `durable:worker`'s, and the bundle names those that poll the cluster: workflow and
 * activity when Temporal holds the journal, nexus once a handler is declared.
 */
#[AsCommand(name: 'durable:health', description: 'Fails when a Durable worker role has stopped polling its Temporal task queue.')]
final class HealthCommand extends Command
{
    /** A live worker polls about once a minute, and the server keeps a stopped one listed for minutes. */
    public const SILENCE_SECONDS = 120;

    /**
     * @param list<string> $roles `durable:worker --role` values, which are {@see TaskQueueKind} values
     */
    public function __construct(
        private readonly TemporalTaskQueueProbe $probe,
        private readonly array $roles,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ([] === $this->roles) {
            $output->writeln('No worker role polls the Temporal cluster in this configuration: nothing to check.');

            return Command::SUCCESS;
        }

        // Failures go to stderr, where an alerting script looks besides the exit code.
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $since = new \DateTimeImmutable(\sprintf('-%d seconds', self::SILENCE_SECONDS));
        $healthy = true;
        foreach ($this->probe->describe(array_map(static fn(string $role): TaskQueueKind => TaskQueueKind::from($role), $this->roles)) as $queue) {
            $role = $queue->kind->value;
            if ($queue->polledSince($since)) {
                $output->writeln(\sprintf('%s: %d poller(s) on %s', $role, $queue->pollers, $queue->taskQueue));
                continue;
            }
            $healthy = false;
            $taskQueue = OutputFormatter::escape($queue->taskQueue);
            $errors->writeln(null !== $queue->error
                ? \sprintf('<error>%s: could not ask the cluster who polls %s: %s</error>', $role, $taskQueue, OutputFormatter::escape($queue->error))
                : \sprintf('<error>%s: no worker has polled %s in %ds. Start bin/console durable:worker --role=%1$s.</error>', $role, $taskQueue, self::SILENCE_SECONDS));
        }

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
