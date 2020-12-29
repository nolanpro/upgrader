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
    private $commandTester;

    public function setUp()
    {

        $link = __DIR__ . '/Fixtures/pm-instance';
        unlink($link);
        symlink(__DIR__ . '/Fixtures/pm-instance-old', $link);

        $mock = new MockHandler([
            // new Response(200, [], json_encode(['tag_name' => '4.0.16'])),
            // new Response(200, [], file_get_contents(__DIR__ . '/Fixtures/4.0.16.zip')),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $runCmd = new MockRunCmd([
            // '/composer install/' => 'Composer install done',
            // '/composer update/' => 'Composer update done',
            // '/composer require/' => 'Composer require done',
            // '/mysqldump/' => 'Mysql backup done',
            // '/:install$/' => 'package installed',
        ]);

        $application = new Application();
        $command = $application->add(new Upgrade($client, $runCmd));
        $this->commandTester = new CommandTester($command);

        $newInstallFixtureDir = __DIR__ . '/Fixtures/pm-instance-4.0.16';
        if (!file_exists($newInstallFixtureDir)) {
            mkdir($newInstallFixtureDir);
        }

        $this->zipArchivePath = __DIR__ . '/Fixtures/processmaker-4.0.16.zip';
        $this->tarArchivePath = __DIR__ . '/Fixtures/PM4v4.0.16.tar.gz';

        if (!file_exists($this->zipArchivePath) || !file_exists($this->tarArchivePath)) {
            $this->fail("Test archives need to exist at $this->tarArchivePath and $this->zipArchivePath");
        }

    }

    public function tearDown() {
        $output = explode("\n", $this->commandTester->getDisplay(true));
        print_r($output);
    }

    public function testWithZip()
    {
        $this->commandTester->setInputs([
            $this->zipArchivePath,
            'y',
            'y',
        ]);

        $this->commandTester->execute([
            'folder' => "tests/Fixtures/pm-instance"
        ]);
    }
    
    public function testWithTar()
    {
        $this->commandTester->setInputs([
            $this->tarArchivePath,
            'y',
            'y',
        ]);

        $this->commandTester->execute([
            'folder' => "tests/Fixtures/pm-instance"
        ]);
    }

    public function testWithFolder()
    {
        $this->commandTester->setInputs([
            __DIR__ . '/Fixtures/pm-instance-4.0.16'
        ]);

        $this->commandTester->execute([
            'folder' => "tests/Fixtures/pm-instance"
        ]);
    }
}