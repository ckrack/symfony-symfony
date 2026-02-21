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

    public function testOutput()
    {
        $command = new DebugRoutingCommand(
            [
                'App\Message\FirstMessage' => ['messenger.transport.async'],
                'App\Message\SecondMessage' => ['messenger.transport.sync'],
            ],
            [
                'failed' => 'messenger.transport.failed',
                'async' => 'messenger.transport.async',
                'sync' => 'messenger.transport.sync',
            ],
        );

        $tester = new CommandTester($command);
        $tester->execute([], ['decorated' => false]);

        $this->assertSame(<<<'TXT'

            Messenger Routing
            =================

            async
            -----

             The following messages are routed to this sender:

              App\Message\FirstMessage

            failed
            ------

             [WARNING] No message is routed to transport "failed".                                                                  

            sync
            ----

             The following messages are routed to this sender:

              App\Message\SecondMessage


            TXT, $tester->getDisplay(true));

        $tester->execute(['sender' => 'sync'], ['decorated' => false]);

        $this->assertSame(<<<'TXT'

            Messenger Routing
            =================

            sync
            ----

             The following messages are routed to this sender:

              App\Message\SecondMessage


            TXT, $tester->getDisplay(true));
    }

    public function testExceptionOnUnknownTransportArgument()
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

    public function testOutputWithoutTransport()
    {
        $command = new DebugRoutingCommand([], []);

        $tester = new CommandTester($command);
        $tester->execute([], ['decorated' => false]);

        $this->assertSame(<<<'TXT'

            Messenger Routing
            =================

             [WARNING] No Messenger transport is registered.                                                                        


            TXT, $tester->getDisplay(true));
    }

    #[DataProvider('provideCompletionSuggestions')]
    public function testComplete(array $input, array $expectedSuggestions)
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
}
