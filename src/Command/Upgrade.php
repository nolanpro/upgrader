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
        'passport:install',
    ];

    const VERSIONS_FOR_4_0_16 = [
        'connector-send-email' => '1.2.4',
        'package-savedsearch' => '1.9.5',
        'package-collections' => '1.6.4',
        'package-webentry' => '1.2.5',
        'package-dynamic-ui' => '1.0.1',
        'package-files' => '1.1.5',
    ];

    protected static $defaultName = 'run';
    private $input, $output, $helper, $oldPath, $client, $runCmd, $dotenv;

    public function __construct(Client $client, $runCmd)
    {
        parent::__construct();
        $this->client = $client;
        $this->runCmd = $runCmd;
    }

    protected function configure()
    {
        $this->addArgument('symlink', InputArgument::REQUIRED, 'Symlink to a folder of the existing pm4 instance to upgrade');
        $this->addArgument('archive', InputArgument::REQUIRED, 'Location of the zip or tar.gz build file to upgrade the instance to');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        $this->question = $this->getHelper('question');

        try {
            $this->call();
        } catch (ExitException $e) {
            return Command::SUCCESS;
        }
    }

    protected function call()
    {
        $dir = $this->input->getArgument('symlink');
        $path = getcwd() . '/' . $dir;
        if (!file_exists($path)) {
            $this->error("Folder not found: $path");
        }
        if (!is_link($path)) {
            $this->error("Folder not a symlink: $path");
        }
        $this->oldPath = $path;
        $this->maintenanceMode();
        $this->backupMysql();

        $this->extractRepo();
        $this->copyEnv();
        $this->addPrivatePackagist();
        $this->composerInstall();
        $this->updateI18Next();
        $this->copyStorage();
        
        $this->installBasePackages();
        $this->installPrivatePackages();
        
        $this->runMigrations();

        $this->installJavascriptAssets();
        $this->clearCache();

        $this->artisan('down'); // maintenance mode in new install
        $this->chown();
        $this->symlink();
        $this->restartServices();
        $this->artisan('up');

        return $this->exit();
    }

    private function chown()
    {
        // $this->cmd("chown -R nginx:nginx $this->newPath");
    }

    private function restartServices()
    {
        // $this->cmd('systemctl restart php-fpm');
        $this->artisan('horizon:terminate');
    }

    private function symlink()
    {
        unlink($this->oldPath);
        $this->info("Symlinking source $this->newPath to destination $this->oldPath");
        symlink($this->newPath, $this->oldPath);
    }

    private function clearCache()
    {
        $this->artisan('optimize:clear');
    }

    private function installJavascriptAssets()
    {
        if (file_exists($this->newPath . '/public/mix-manifest.json')) {
            $this->info("Javascript assets already installed. Not running npm.");
            return;
        }

        $this->repoCmd('npm install');
        $this->repoCmd('npm run dev'); // should this be yarn?
    }

    private function runMigrations()
    {
        $this->artisan('migrate --force');
    }

    private function copyStorage()
    {
        $old = $this->oldPath . '/storage/*';
        $new = $this->newPath . '/storage/'; 
        $this->cmd("rsync -a $old $new");
        $this->artisan('storage:link');
    }
    
    private function backupMysql()
    {
        $this->mysqlDump();
        $this->mysqlDump('DATA_');
    }

    private function mysqlDump($envPrefix = '')
    {
        $username = $this->env($envPrefix . 'DB_USERNAME');
        $password = $this->env($envPrefix . 'DB_PASSWORD');
        $host = $this->env($envPrefix . 'DB_HOSTNAME');
        $port = $this->env($envPrefix . 'DB_PORT');
        $database = $this->env($envPrefix . 'DB_DATABASE');
        $time = time();

        $basename = basename($this->oldPath);
        $output = realpath($this->oldPath . '/../');
        $output = "${output}/${basename}-backup-${envPrefix}${database}-${time}.sql";
        $cmd = "mysqldump -u $username -p'$password' -h $host -P $port $database > $output";
        $this->cmd($cmd);
    }

    private function env($key)
    {
        if (!$this->dotenv) {
            $this->dotenv = \Dotenv\Dotenv::createImmutable($this->oldPath);
            $this->dotenv->load();
        }
        if ($key === 'DATA_DB_HOSTNAME') {
            $key = 'DATA_DB_HOST';
        }
        return $_ENV[$key];
    }


    private function installBasePackages()
    {
        foreach (self::BASE_PACKAGE_INSTALL as $cmd) {
            $this->artisan($cmd);
        }

        if ($this->is4016()) {
            $this->artisan('horizon:assets');
        } else {
            $this->artisan('horizon:install');
        }
    }

    private function installPrivatePackages()
    {
        $packages = [];
        foreach ($this->privatePackages() as $package) {
            $version = "";
            if ($this->is4016() && isset(self::VERSIONS_FOR_4_0_16[$package])) {
                $version = ':' . self::VERSIONS_FOR_4_0_16[$package];
            }
            $packages[] = "processmaker/${package}${version}";
        }
        
        $packages = join(" ", $packages);
        $this->repoCmd("composer require $packages");
        
        foreach ($this->privatePackages() as $package) {
            $this->artisan("${package}:install");
        }
    }

    private function artisan($cmd)
    {
        $this->repoCmd('php artisan ' . $cmd);
    }

    private function composerInstall()
    {
        if (!file_exists($this->newPath . '/vendor')) {
            $this->info("Running composer install");
            $this->repoCmd('composer install');
        } else {
            $this->info("Vendor folder exists. Not running composer install.");
        }
    }

    private function updateI18Next()
    {
        // Only 4.0.X
        if ($this->is4016()) {
            $this->repoCmd('composer update processmaker/laravel-i18next');
        }
    }

    private function is4016()
    {
        return $this->version === '4.0.16';
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

    private function endsWith( $haystack, $needle ) {
        $length = strlen( $needle );
        if( !$length ) {
            return true;
        }
        return substr( $haystack, -$length ) === $needle;
    }

    private function extractRepo()
    {
        $zipPath = $this->input->getArgument('archive');

        if (is_dir($zipPath)) {
            $this->newPath = $zipPath;
            $this->version = $this->getVersion($this->newPath);
            return;
        }

        if (!file_exists($zipPath)) {
            $this->error("Archive not found: $zipPath");
        }

        $extractPath = sys_get_temp_dir() . "/pm4_extracted";
        $this->cmd("rm -rf $extractPath");

        if ($this->endsWith($zipPath, '.zip')) {
            $this->unZip($zipPath, $extractPath);
        } elseif ($this->endsWith($zipPath, '.tar.gz')) {
            $this->unTar($zipPath, $extractPath);
        } else {
            $this->error("Unknown file extension $zipPath");
        }

        $this->version = $this->getVersion($extractPath);
        if (!$this->confirm("Upgrade $this->oldPath to $this->version?")) {
            return $this->exit();
        }

        $newDir = basename($this->oldPath) . '-' . $this->version;
        $this->newPath = realpath($this->oldPath . '/../') . '/' . $newDir;
        
        if (file_exists($this->newPath) && !$this->confirm($this->newPath . " already exists. It will be deleted. Continue?")) {
            return $this->exit(); 
        }
        $this->cmd("rm -rf $this->newPath");
        $this->cmd("cp -r $extractPath $this->newPath");
    }

    private function unZip($zipPath, $extractPath)
    {
        $tempDir = sys_get_temp_dir() . '/pm4_unzipped';
        if (file_exists($tempDir)) {
            $this->cmd("rm -rf $tempDir");
        }

        $zip = new \ZipArchive;
        $this->info("Unzipping $zipPath to $tempDir");
        $res = $zip->open($zipPath);
        if ($res === true) {
            $zip->extractTo($tempDir);
            $zip->close();
        } else {
            $this->error("Error unzipping $zipPath to $tempDir");
        }

        $this->moveToExtractPath($tempDir, $extractPath);
    }

    private function moveToExtractPath($tempDir, $extractPath)
    {
        $folders = array_filter(scandir($tempDir, 1), function($folder) {
            return substr($folder, 0, 1) !== '.';
        });
        if (count($folders) === 1) {
            // It's in a subfolder. Github archives do this
            $dirToMove = $tempDir . '/' . $folders[0];
        } else {
            $dirToMove = $tempDir;
        }

        $this->info("Moving $dirToMove to $extractPath");
        $this->cmd("mv $dirToMove $extractPath");

        if (file_exists($tempDir)) {
            $this->cmd("rm -rf $tempDir");
        }
    }

    private function unTar($tarPath, $extractPath)
    {
        $tempPath = sys_get_temp_dir() . '/pm4.tar.gz';
        $tempDir = sys_get_temp_dir() . '/pm4_untared';

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
        
        if (file_exists($tempDir)) {
            $this->cmd("rm -rf $tempDir");
        }

        copy($tarPath, $tempPath);
        $tar = new \PharData($tempPath);
        
        $tempPath = substr($tempPath, 0, -3);
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $tar->decompress();
        
        $tar = new \PharData($tempPath);
        $tar->extractTo($tempDir);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
        
        $this->moveToExtractPath($tempDir, $extractPath);
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
        } catch(CmdFailedException $e) {
            $this->error($e->getMessage());
        }
        return $allOutput;
    }

    private function privatePackages()
    {
        $packagesFile = __DIR__ . '/PACKAGES';
        $packages = [];
        if (file_exists($packagesFile)) {
            $packages = array_map(function($package) {
                return trim($package);
            }, file($packagesFile));
        } else {
            $this->info("PACKAGES file not found. Not installing any enterprise packages.");
        }
        return $packages;
    }

    private function error($message)
    {
        throw new \Exception($message);
    }

    private function exit()
    {
        $this->info("Quitting");
        throw new ExitException();
    }

    private function getVersion($path)
    {
        $composer = json_decode(file_get_contents($path . '/composer.json'));
        return $composer->version;
    }
}