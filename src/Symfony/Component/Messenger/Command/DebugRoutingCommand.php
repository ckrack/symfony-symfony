<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A console command to debug Messenger routing information.
 *
 * @author Clemens Krack <info@clemenskrack.com>
 */
#[AsCommand(name: 'debug:messenger:routing', description: 'List messages routed by Messenger senders')]
class DebugRoutingCommand extends Command
{
    /**
     * @param array<string, list<string>>   $sendersMap
     * @param array<string, string>         $senderAliases
     */
    public function __construct(
        private readonly array $sendersMap,
        private readonly array $senderAliases = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $transportNames = $this->getTransportNames();

        $this
            ->addArgument('sender', InputArgument::OPTIONAL, \sprintf('The sender name (one of "%s")', implode('", "', $transportNames)))
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command displays all messages routed to Messenger
                senders:

                  <info>php %command.full_name%</info>

                Or for a specific sender only:

                  <info>php %command.full_name% async</info>

                EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Messenger Routing');

        $transportNames = $this->getTransportNames();

        if (!$transportNames) {
            $io->warning('No Messenger sender is registered.');

            return 0;
        }

        if ($transport = $input->getArgument('sender')) {
            if (!\in_array($transport, $transportNames, true)) {
                throw new RuntimeException(\sprintf('Sender "%s" does not exist. Known senders are "%s".', $transport, implode('", "', $transportNames)));
            }

            $transportNames = [$transport];
        }

        $messagesPerTransport = $this->getMessagesPerTransport();

        foreach ($transportNames as $transportName) {
            $io->section($transportName);

            $messages = $messagesPerTransport[$transportName] ?? [];
            sort($messages);

            if (!$messages) {
                $io->warning(\sprintf('No message is routed to sender "%s".', $transportName));
                continue;
            }

            $io->text('The following messages are routed to this sender:');
            $io->newLine();
            foreach ($messages as $message) {
                $io->writeln(\sprintf('  <fg=cyan>%s</>', $message));
            }
            $io->newLine();
        }

        return 0;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('sender')) {
            $suggestions->suggestValues($this->getTransportNames());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function getMessagesPerTransport(): array
    {
        $serviceToAlias = array_flip($this->senderAliases);
        $result = [];

        foreach ($this->sendersMap as $message => $senders) {
            foreach ($senders as $sender) {
                $alias = $serviceToAlias[$sender] ?? $sender;
                if (!isset($result[$alias]) || !\in_array($message, $result[$alias], true)) {
                    $result[$alias][] = $message;
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function getTransportNames(): array
    {
        $transportNames = array_keys($this->senderAliases);
        sort($transportNames);

        return $transportNames;
    }
}
