<?php

declare(strict_types=1);

namespace BashBox\Commands;

use BashBox\ExecResult;

final class Echo_ extends AbstractCommand
{
    public function getName(): string
    {
        return 'echo';
    }

    public function execute(array $args, CommandContext $commandContext): ExecResult
    {
        $newline = "\n";
        $interpretEscapes = false;
        $i = 0;

        // Options end at the first word that isn't made up only of n, e and E (so "--" or "-x" are printed).
        for (; $i < count($args) && preg_match('/^-[neE]+$/', $args[$i]) === 1; $i++) {
            foreach (str_split(substr($args[$i], 1)) as $flag) {
                if ($flag === 'n') {
                    $newline = '';
                } else {
                    $interpretEscapes = $flag === 'e';
                }
            }
        }

        $output = implode(' ', array_slice($args, $i));

        if ($interpretEscapes) {
            [$output, $stopped] = $this->expandEscapes($output, '0[0-7]{0,3}');
            $newline = $stopped ? '' : $newline;
        }

        return $this->success($output.$newline);
    }
}
