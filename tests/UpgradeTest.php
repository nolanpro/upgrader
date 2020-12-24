<?php
namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;
use App\Command\Upgrade;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;

class UpgradeTest extends TestCase
{
    public function testExecute()
    {

        $mock = new MockHandler([
            new Response(200, [], json_encode(['tag_name' => '4.0.16'])),
            new Response(200, [], file_get_contents(__DIR__ . '/Fixtures/4.0.16.zip')),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $runCmd = new MockRunCmd([
            '/composer install/' => 'Composer install done',
            '/composer update/' => 'Composer update done',
            '/rm -rf.*pm-instance-4.0.16/' => 'Removed and copied new path'
        ]);

        $application = new Application();
        $command = $application->add(new Upgrade($client, $runCmd));
        $commandTester = new CommandTester($command);

        $newInstallFixtureDir = __DIR__ . '/Fixtures/pm-instance-4.0.16';
        if (!file_exists($newInstallFixtureDir)) {
            mkdir($newInstallFixtureDir);
        }

        $commandTester->setInputs([
            'y',
            'y',
        ]);

        $commandTester->execute([
            'folder' => "tests/Fixtures/pm-instance"
        ]);

        $output = explode("\n", $commandTester->getDisplay(true));
        print_r($output);
    }
}