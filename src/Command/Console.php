<?php
namespace App\Command;

use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

trait Console {
    private function ask($ask, $default = null, $autocomplete = null)
    {
        $question = new Question($ask . ' ', $default);
        if ($autocomplete) {
            $question->setAutocompleterCallback($autocomplete);
        }
        return $this->question->ask($this->input, $this->output, $question);
    }
    
    private function confirm($ask)
    {
        $question = new ConfirmationQuestion($ask . ' [y/n] ', false);
        return $this->question->ask($this->input, $this->output, $question);
    }

    private function info($txt)
    {
        $this->output->writeLn("<info>$txt</info>");
    }
}