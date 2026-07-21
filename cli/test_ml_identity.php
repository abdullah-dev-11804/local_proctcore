<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'companyid' => 0,
    'reference' => '',
    'center' => '',
    'left' => '',
    'right' => '',
    'help' => false,
], [
    'c' => 'companyid',
    'h' => 'help',
]);

if ($options['help'] || !$options['reference'] || !$options['center'] || !$options['left'] || !$options['right']) {
    echo "Test identity verification using four local image files.\n\n";
    echo "php local/proctorcore/cli/test_ml_identity.php \\\n  --reference=/tmp/profile.jpg --center=/tmp/center.jpg \\\n  --left=/tmp/left.jpg --right=/tmp/right.jpg [--companyid=0]\n";
    exit($options['help'] ? 0 : 1);
}

$read = static function(string $path): string {
    if (!is_file($path) || !is_readable($path)) {
        cli_error('Image is not readable: ' . $path);
    }
    $bytes = file_get_contents($path);
    if ($bytes === false || strlen($bytes) < 256) {
        cli_error('Image is empty or invalid: ' . $path);
    }
    return $bytes;
};

try {
    $result = (new \local_proctorcore\local\ml_client(max(0, (int) $options['companyid'])))
        ->verify_identity(
            $read((string) $options['reference']),
            $read((string) $options['center']),
            $read((string) $options['left']),
            $read((string) $options['right']),
            'cli-' . bin2hex(random_bytes(8))
        );
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "IDENTITY TEST COMPLETED\n";
} catch (Throwable $exception) {
    cli_error(get_class($exception) . ': ' . $exception->getMessage());
}
