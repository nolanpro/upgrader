<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use GuzzleHttp\Client;

class Upgrade extends Command {
    use Console;

    const BASE_PACKAGE_INSTALL = [
        'docker-executor-php:install',
        'docker-executor-lua:install',
        'docker-executor-node:install',
    ];

    protected static $defaultName = 'run';
    private $input, $output, $helper, $oldPath, $client, $runCmd;

    public function __construct(Client $client, $runCmd)
    {
        parent::__construct();
        $this->client = $client;
        $this->runCmd = $runCmd;
    }

    protected function configure()
    {
        $this->addArgument('folder', InputArgument::REQUIRED, 'Folder of pm4 instance to upgrade');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        $this->question = $this->getHelper('question');

        $dir = $this->input->getArgument('folder');
        $path = getcwd() . '/' . $dir;
        if (!file_exists($path)) {
            $this->error("Folder not found: $path");
        }

        if (!is_link($path)) {
            $this->error("Folder not a symlink: $path");
        }

        $latest = $this->getVersion(); 
        if (!$this->confirm("Upgrade $dir to $latest?")) {
            return Command::SUCCESS;
        }

        $this->version = $latest;
        $this->oldPath = $path;
        $this->info("Upgrading $dir to $latest");

        $this->maintenanceMode();
        // $this->backupMysql();

        $this->extractRepo();
        $this->copyEnv();
        $this->addPrivatePackagist();
        $this->composerInstall();
        $this->updateI18Next();
        
        // $this->runMigrations();
        // $this->copyAndSymlinkStorage();

        $this->installBasePackages();
        $this->installPrivatePackages();

        return Command::SUCCESS;
    }

    private function installBasePackages()
    {
        foreach (self::BASE_PACKAGE_INSTALL as $cmd) {
            $this->artisan($cmd);
        }
    }

    private function installPrivatePackages()
    {

    }

    private function artisan($cmd)
    {
        $this->repoCmd('php artisan ' . $cmd);
    }

    private function composerInstall()
    {
        $this->info("Running composer install");
        $this->repoCmd('composer install --no-dev');
    }

    private function updateI18Next()
    {
        // Only 4.0.X
        if (preg_match('/^v?4\.0\./', $this->version)) {
            $this->repoCmd('composer update processmaker/laravel-i18next');
        }
    }
    
    private function copyEnv()
    {
        $this->info("Copy .env file");
        copy($this->oldPath . '/.env', $this->newPath . '/.env');
    }

    private function addPrivatePackagist()
    {
        $path = $this->newPath . '/composer.json';
        $composer = file_get_contents($path);
        $composer = json_decode($composer, true);
        if (!isset($composer['repositories'])) {
            $composer['repositories'] = [];
        }
        $composer['repositories'][] = [
            'type' => 'composer',
            'url' => 'https://processmaker.repo.packagist.com/example-customer',
        ];
        file_put_contents($path, json_encode($composer));
    }

    private function extractRepo()
    {
        $this->info("Getting and extracting the repo");
        $version = $this->version;
        $zipPath = sys_get_temp_dir() . "/pm4_${version}.zip";
        if (!file_exists($zipPath)) {
            $url = "https://github.com/ProcessMaker/processmaker/archive/${version}.zip";
            $this->info("Saving $url to $zipPath");
            $this->client->request('GET', $url, [
                'sink' => $zipPath,
                'progress' => function ($downloadTotalSize, $downloaded, $uploadTotalSize, $uploaded) {
                }
            ]);
        } else {
            $this->info("Zip already exists. Not downloading again.");
        }

        $extractPath = sys_get_temp_dir() . "/pm4_${version}";
        $this->cmd("rm -rf $extractPath");
        $zip = new \ZipArchive;
        $this->info("Unzipping $zipPath to $extractPath");
        $res = $zip->open($zipPath);
        if ($res === true) {
            $zip->extractTo($extractPath);
            $zip->close();
        } else {
            $this->error("Error unzipping $extractPath");
        }
        
        $newDir = basename($this->oldPath) . '-' . $version;
        $this->newPath = realpath($this->oldPath . '/../') . '/' . $newDir;
        if (file_exists($this->newPath) && !$this->confirm($this->newPath . " already exists. It will be deleted. Continue?")) {
            return Command::SUCCESS; 
        }
        $dirInExtractPath = $extractPath . '/processmaker-' . $version;
        $this->info("Copying $dirInExtractPath to $this->newPath");
        $this->cmd("rm -rf $this->newPath && cp -r $dirInExtractPath $this->newPath");
    }

    private function maintenanceMode()
    {
        $cmd = 'cd ' . $this->oldPath . ' && ' . 'php artisan down --message="Upgrade in process"';
        $this->cmd($cmd);
    }

    private function repoCmd($cmd)
    {
        return $this->cmd("cd $this->newPath && $cmd");
    }

    private function cmd($cmd)
    {
        $this->info("Running shell command: $cmd");
        try {
            $allOutput = $this->runCmd->call($cmd, function($output) {
                $output = str_replace("\n", "\n--> ", $output);
                $this->info("--> " . $output);
            });
        } catch(ExitCodeException $e) {
            $this->error($e->getMessage());
        }
        return $allOutput;
    }

    private function packages()
    {
        $packagesFile = __DIR__ . '/PACKAGES';
        $packages = [];
        if (file_exists($packagesFile)) {
            $packages = file_get_contents($packagesFile);
        }
        $packages = array_map(function($item) {
            $def = explode(':', $item);
            return [
                'name' => $def[0],
                'version' => isset($def[1]) ? $def[1] : null,
            ];
        }, $packages);

        return $packages;
    }

    // private function cmd($cmd)
    // {
    //     $lastLine = system($cmd, $returnValue);
    // }

    private function error($message)
    {
        throw new \Exception($message);
    }

    private function getVersion()
    {
        $this->info("Getting latest version from github.");
        return $this->getFromGithub('/repos/processmaker/ProcessMaker/releases/latest')->tag_name;
    }

    private function getFromGithub($path)
    {
        $res = $this->client->request('GET', 'https://api.github.com'. $path);
        return json_decode($res->getBody());
    }
}