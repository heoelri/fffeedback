<?php
// Reads {"survey": ..., "inputs": [...]} from stdin, prints sanitize() for each input. Used by logic.test.mjs.
require __DIR__ . '/../public/lib.php';
$in = json_decode(stream_get_contents(STDIN), true);
echo '[' . implode(',', array_map(fn($i) => state_json(sanitize($in['survey'], $i)), $in['inputs'])) . ']';
