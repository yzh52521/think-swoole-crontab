<?php

namespace ThinkCrontab\Process;

use Swoole\Process;
use Swoole\Server;
use Swoole\Timer;
use think\App;
use think\Log;
use ThinkCrontab\CrontabRegister;
use ThinkCrontab\Scheduler;
use ThinkCrontab\Strategy\CoroutineStrategy;
use ThinkCrontab\Strategy\StrategyInterface;

class CrontabDispatcherProcess
{
    /**
     * @var Server
     */
    private Server $server;

    /**
     * @var CrontabRegister
     */
    private CrontabRegister $crontabRegister;

    /**
     * @var Scheduler
     */
    private Scheduler $scheduler;

    /**
     * @var StrategyInterface
     */
    private StrategyInterface $strategy;

    /**
     * @var Log
     */
    private Log $logger;

    public function __construct(App $app)
    {
        $this->server          = $app->get( Server::class );
        $this->crontabRegister = $app->make( CrontabRegister::class );
        $this->scheduler       = $app->make( Scheduler::class );
        $this->strategy        = $app->make( CoroutineStrategy::class );
        $this->logger          = $app->make( Log::class );
    }

    public function handle(): void
    {
        $process = new Process( function (Process $process) {
            try {
                $this->crontabRegister->handle();

                while ( true ) {
                    $this->sleep();
                    $crontabs = $this->scheduler->schedule();
                    while ( !$crontabs->isEmpty() ) {
                        $crontab = $crontabs->dequeue();
                        $this->strategy->dispatch( $crontab );
                    }
                }
            } catch ( \Throwable $throwable ) {
                $this->logger->error( $throwable->getMessage() );
            } finally {
                Timer::clearAll();
                sleep( 5 );
            }
        },false,0,true );

        $this->server->addProcess( $process );
    }

    private function sleep()
    {
        $current = date( 's',time() );
        $sleep   = 60 - $current;
        $this->logger->debug( 'Crontab dispatcher sleep '.$sleep.'s.' );
        $sleep > 0 && \Swoole\Coroutine::sleep( $sleep );
    }
}