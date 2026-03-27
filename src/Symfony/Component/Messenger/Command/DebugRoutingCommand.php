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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Messenger\Handler\HandlersLocator;

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
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'The fully-qualified class name of the message')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command displays all messages routed to Messenger
                senders:

                  <info>php %command.full_name%</info>

                Or for a specific sender only:

                  <info>php %command.full_name% async</info>

                Or for a specific message only:

                  <info>php %command.full_name% --message=App\Message\MyMessage</info>

                Output is based on your configuration routing map.
                It does not take TransportNamesStamp into account.
                When using --message, the #[AsMessage] attribute on the given class is also considered.

                EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Messenger Routing');

        $message = $input->getOption('message');
        $senderArgument = $input->getArgument('sender');

        if ($message && $senderArgument) {
            throw new RuntimeException('Cannot combine the sender argument with the --message option.');
        }

        $this->renderRoutingContextNote($io, null !== $message);

        $transportNames = $this->getTransportNames();

        if (!$transportNames) {
            $io->warning('No Messenger sender is registered.');

            return 0;
        }

        if ($message) {
            return $this->displayMessageRouting($io, $message);
        }

        if ($transport = $senderArgument) {
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

    private function displayMessageRouting(SymfonyStyle $io, string $message): int
    {
        if (!class_exists($message) && !interface_exists($message)) {
            throw new RuntimeException(\sprintf('Message class "%s" does not exist.', $message));
        }

        $io->section($message);

        $transportNames = $this->getTransportNamesForMessage($message);
        $attributeSenders = $this->getTransportNamesFromAttributes($message);
        if ($attributeSenders) {
            $transportNames = array_merge($transportNames, $attributeSenders);
            $transportNames = array_values(array_unique($transportNames));
        }
        sort($transportNames);

        if (!$transportNames) {
            $io->warning(\sprintf('No sender is routed to message "%s".', $message));

            return 0;
        }

        $io->text('This message is routed to the following senders:');
        $io->newLine();
        foreach ($transportNames as $transportName) {
            $io->writeln(\sprintf('  <fg=cyan>%s</>', $transportName));
        }
        $io->newLine();

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
    private function getTransportNamesForMessage(string $message): array
    {
        $serviceToAlias = array_flip($this->senderAliases);
        $transportNames = [];

        foreach (HandlersLocator::listTypesForClass($message) as $type) {
            if (str_ends_with($type, '*') && $transportNames) {
                // the '*' acts as a fallback, if other senders already matched
                // with previous types, skip the senders bound to the fallback
                continue;
            }

            foreach ($this->sendersMap[$type] ?? [] as $senderAlias) {
                $transportName = $serviceToAlias[$senderAlias] ?? $senderAlias;
                if (!\in_array($transportName, $transportNames, true)) {
                    $transportNames[] = $transportName;
                }
            }
        }

        return $transportNames;
    }

    /**
     * @return list<string>
     */
    private function getTransportNamesFromAttributes(string $message): array
    {
        $transportNames = [];
        $serviceToAlias = array_flip($this->senderAliases);

        foreach ([$message] + class_parents($message) + class_implements($message) as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getAttributes(AsMessage::class, \ReflectionAttribute::IS_INSTANCEOF) as $refAttr) {
                $asMessage = $refAttr->newInstance();
                foreach ((array) $asMessage->transport as $transportName) {
                    $transportName = $serviceToAlias[$transportName] ?? $transportName;
                    if (!\in_array($transportName, $transportNames, true)) {
                        $transportNames[] = $transportName;
                    }
                }
            }
        }

        return $transportNames;
    }

    private function renderRoutingContextNote(SymfonyStyle $io, bool $includeAttributes): void
    {
        if ($includeAttributes) {
            $io->text('Note: output is based on the configuration routing map; the #[AsMessage] attribute on the given class is also considered. TransportNamesStamp is not.');
            $io->newLine();

            return;
        }

        $io->text('Note: output is based on the configuration routing map only. TransportNamesStamp and #[AsMessage] are not considered. Use --message to include attributes.');
        $io->newLine();
    }

    /**
     * @return list<string>
     */
    private function getTransportNames(): array
    {
        $serviceToAlias = array_flip($this->senderAliases);
        $transportNames = array_keys($this->senderAliases);

        foreach ($this->sendersMap as $senders) {
            foreach ($senders as $sender) {
                $transportNames[] = $serviceToAlias[$sender] ?? $sender;
            }
        }

        $transportNames = array_values(array_unique($transportNames));
        sort($transportNames);

        return $transportNames;
    }
}
