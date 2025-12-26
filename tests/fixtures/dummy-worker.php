<?php

while (($line = fgets(STDIN)) !== false) {
    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['id'], $msg['type'])) {
        fwrite(STDERR, "bad request\n");
        exit(1);
    }

    if ($msg['type'] === 'dsn') {
        echo json_encode(['id' => $msg['id'], 'status' => 'ok']) . "\n";
        continue;
    }

    echo json_encode(['id' => $msg['id'], 'status' => 'ok']) . "\n";
}