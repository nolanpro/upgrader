<?php

namespace App\Command;

class RunCmd {
    public function call($cmd, $outputCallback)
    {
        $handle = popen($cmd, 'r');
        $allOutput = '';
        while(!feof($handle)) {
            $output = fread($handle, 1024);
            $allOutput .= $output;
            $outputCallback($output);
        }
        $exitCode = pclose($handle);
        if ($exitCode) {
            throw new CmdFailedException("cmd failed with exit code $exitCode. Output is\n$allOutput");
        }
        return $allOutput;
    }
}