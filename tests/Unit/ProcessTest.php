<?php

use Strukt\Process;
use Strukt\ProcessResult;

beforeEach(function (): void {
    Process::switchChannels(false);
});

test('runs commands, streams complete lines, and returns resources', function (): void {
    $lines = [];
    $result = process(
        "printf 'alpha\\nbeta\\n'",
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );

    expect($result)->toBeInstanceOf(ProcessResult::class)
        ->and($result->count())->toBe(1)
        ->and($result->outputs())->toBe("alpha\nbeta\n")
        ->and($result->resource()->output())->toBe("alpha\nbeta\n")
        ->and($lines)->toBe(["alpha\n", "beta\n"])
        ->and($result->resource()->isRunning())->toBeFalse();
});

test('drains standard error and retains the error after cleanup', function (): void {
    $result = process("sh -c 'printf \"failure\\n\" >&2; exit 7'");

    expect($result->outputs())->toBe('')
        ->and($result->errors())->toBe("failure\n")
        ->and($result->resource()->error())->toBe("failure\n")
        ->and($result->resource()->close())->toBe(7);
});

test('supports callbacks that do not declare an argument', function (): void {
    $calls = 0;
    process("printf 'done\\n'", static function () use (&$calls): void {
        $calls++;
    });

    expect($calls)->toBe(1);
});

test('runs command batches in order and captures each result', function (): void {
    $result = process([
        "printf 'first\\n'",
        "printf 'second\\n'",
    ]);

    expect($result->outputs(0))->toBe("first\n")
        ->and($result->outputs(1))->toBe("second\n")
        ->and($result->resource(1))->toBeInstanceOf(Process::class)
        ->and($result->outputs(2))->toBeNull();
});

test('switches logical output and error channels', function (): void {
    switchChannels(true);
    $result = process("sh -c 'printf output; printf error >&2'");

    expect($result->outputs())->toBe('error')
        ->and($result->errors())->toBe('output');
});

test('supports interactive prompt input', function (): void {
    $prompt = pprocess('read password; printf "%s\\n" "$password"');

    expect($prompt->write('secret'))->toBeGreaterThan(0);
    $prompt->closeInput();

    expect($prompt->read())->toBe("secret\n");
    $prompt->close();
});

test('registers every process helper', function (): void {
    expect(helper('process'))->toContain('switchChannels', 'process', 'pprocess');
});
