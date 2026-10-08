<?php

declare(strict_types=1);

// A worker that prints a line periodically forever, so a supervisor has
// something to drain and observe. Killed slots are replaced in place.

while (true) {
    echo 'beat ' . date('H:i:s') . "\n";
    usleep(200_000);
}