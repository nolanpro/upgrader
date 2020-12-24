<?php
namespace Tests;

use App\Command\RunCmd as RealRunCmd;

class MockRunCmd {
    private $cmds, $realRunCmd;
    
    public function __construct($cmds)
    {
        $this->cmds = $cmds;
        $this->realRunCmd = new RealRunCmd();
    }

    public function call($cmd, $callback)
    {
        foreach ($this->cmds as $match => $return) {
            if (preg_match($match, $cmd)) {
                $foundMatch = true;
                $output = $return . "\n";
                $callback($output);
                return $output;
            }
        }

        $this->realRunCmd->call($cmd, $callback);
    }
}