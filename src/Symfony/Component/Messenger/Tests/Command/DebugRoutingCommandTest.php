<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Command\DebugRoutingCommand;
use Symfony\Component\Messenger\Tests\Fixtures\DummyMessage;
use Symfony\Component\Messenger\Tests\Fixtures\DummyMessageWithAttribute;

class DebugRoutingCommandTest extends TestCase
{
    private string|false $colSize;

    protected function setUp(): void
    {
        $this->colSize = getenv('COLUMNS');
        putenv('COLUMNS='.(119 + \strlen(\PHP_EOL)));
    }

    protected function tearDown(): void
    {
        putenv($this->colSize ? 'COLUMNS='.$this->colSize : 'COLUMNS');
    }

    /**
     * Example config:
     * messenger:
     *     transports:
     *         async: '%env(MESSENGER_TRANSPORT_DSN)%'
     *         sync: 'sync://'
     *     routing:
     *         'App\\Message\\FirstMessage': ['async']
     *         'App\\Message\\SecondMessage': ['sync']
     *         # custom_sender refers to a sender service id registered in the container,
     *         # not a transport alias.
     *         'App\\Message\\ThirdMessage': ['custom_sender']
     *
     * services:
     *     custom_sender:
     *         class: App\\Messenger\\CustomSender
     *         # CustomSender implements Symfony\\Component\\Messenger\\Transport\\Sender\\SenderInterface
     */
    public function testOutputListsSendersAndCustomSenders(): void
    {
        $command = new DebugRoutingCommand(
            [
                'App\Message\FirstMessage' => ['messenger.transport.async'],
                'App\Message\SecondMessage' => ['messenger.transport.sync'],
                'App\Message\ThirdMessage' => ['custom_sender'],
            ],
            [
                'failed' => 'messenger.transport.failed',
                'async' => 'messenger.transport.async',
                'sync' => 'messenger.transport.sync',
            ],
        );
        $tester = new CommandTester($command);

        $tester->execute([], ['decorated' => false]);

        $this->assertSame(sprintf(<<<'TXT'

            Messenger Routing
            =================

             Note: output is based on the configuration routing map only. TransportNamesStamp and #[AsMessage] are not considered. Use --message to include attributes.

            async
            -----

             The following messages are routed to this sender:

              App\Message\FirstMessage

            custom_sender
            -------------

             The following messages are routed to this sender:

              App\Message\ThirdMessage

            failed
            ------

            %s

            sync
            ----

             The following messages are routed to this sender:

              App\Message\SecondMessage


            TXT, $this->padWarning('No message is routed to sender "failed".')), $tester->getDisplay(true));
    }

    public function testOutputFiltersBySenderArgument(): void
    {
        $command = new DebugRoutingCommand(
            [
                'App\Message\FirstMessage' => ['messenger.transport.async'],
                'App\Message\SecondMessage' => ['messenger.transport.sync'],
                'App\Message\ThirdMessage' => ['custom_sender'],
            ],
            [
                'failed' => 'messenger.transport.failed',
                'async' => 'messenger.transport.async',
                'sync' => 'messenger.transport.sync',
            ],
        );
        $tester = new CommandTester($command);

        $tester->execute(['sender' => 'sync'], ['decorated' => false]);

        $this->assertSame(<<<'TXT'

            Messenger Routing
            =================

             Note: output is based on the configuration routing map only. TransportNamesStamp and #[AsMessage] are not considered. Use --message to include attributes.

            sync
            ----

             The following messages are routed to this sender:

              App\Message\SecondMessage


            TXT, $tester->getDisplay(true));
    }

    public function testThrowsOnUnknownSenderArgument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sender "invalid" does not exist. Known senders are "async", "sync".');

        $command = new DebugRoutingCommand(
            [],
            [
                'sync' => 'messenger.transport.sync',
                'async' => 'messenger.transport.async',
            ],
        );
        $tester = new CommandTester($command);
        $tester->execute(['sender' => 'invalid'], ['decorated' => false]);
    }

    public function testOutputWhenNoTransportIsRegistered(): void
    {
        $command = new DebugRoutingCommand([], []);

        $tester = new CommandTester($command);
        $tester->execute([], ['decorated' => false]);

        $this->assertSame(sprintf(<<<'TXT'

            Messenger Routing
            =================

             Note: output is based on the configuration routing map only. TransportNamesStamp and #[AsMessage] are not considered. Use --message to include attributes.

            %s


            TXT, $this->padWarning('No Messenger sender is registered.')), $tester->getDisplay(true));
    }

    public function testOutputFiltersByMessageOptionWithWildcardNamespace(): void
    {
        $command = new DebugRoutingCommand(
            [
                'Symfony\Component\Messenger\Tests\Fixtures\*' => ['async'],
                '*' => ['fallback'],
            ],
            [
                'async' => 'messenger.transport.async',
                'fallback' => 'messenger.transport.fallback',
            ],
        );
        $tester = new CommandTester($command);

        $tester->execute(['--message' => DummyMessage::class], ['decorated' => false]);

        $message = DummyMessage::class;
        $this->assertSame(sprintf(<<<'TXT'

            Messenger Routing
            =================

             Note: output is based on the configuration routing map; the #[AsMessage] attribute on the given class is also considered. TransportNamesStamp is not.

            %s
            %s

             This message is routed to the following senders:

              async


            TXT, $message, str_repeat('-', strlen($message))), $tester->getDisplay(true));
    }

    public function testOutputFiltersByMessageOptionIncludesAttributeRouting(): void
    {
        $command = new DebugRoutingCommand(
            [],
            [
                'first_sender' => 'messenger.transport.first_sender',
                'second_sender' => 'messenger.transport.second_sender',
            ],
        );
        $tester = new CommandTester($command);

        $tester->execute(['--message' => DummyMessageWithAttribute::class], ['decorated' => false]);

        $message = DummyMessageWithAttribute::class;
        $this->assertSame(sprintf(<<<'TXT'

            Messenger Routing
            =================

             Note: output is based on the configuration routing map; the #[AsMessage] attribute on the given class is also considered. TransportNamesStamp is not.

            %s
            %s

             This message is routed to the following senders:

              first_sender
              second_sender


            TXT, $message, str_repeat('-', strlen($message))), $tester->getDisplay(true));
    }

    #[DataProvider('provideCompletionSuggestions')]
    public function testComplete(array $input, array $expectedSuggestions): void
    {
        $command = new DebugRoutingCommand([], [
            'async' => 'messenger.transport.async',
            'sync' => 'messenger.transport.sync',
        ]);
        $application = new Application();
        if (method_exists($application, 'addCommand')) {
            $application->addCommand($command);
        } else {
            $application->add($command);
        }

        $tester = new CommandCompletionTester($application->get('debug:messenger:routing'));
        $this->assertSame($expectedSuggestions, $tester->complete($input));
    }

    public static function provideCompletionSuggestions(): iterable
    {
        yield 'sender' => [
            [''],
            ['async', 'sync'],
        ];
    }

    private function padWarning(string $message): string
    {
        $width = (int) getenv('COLUMNS') ?: 120;

        return str_pad(sprintf(' [WARNING] %s', $message), $width);
    }
}
