<?php

use Symfony\Component\Config\Definition\Exception\Exception;

exec('rm -rf ' . __DIR__ . '/dist/phar');

@mkdir(__DIR__ . '/dist');
@mkdir(__DIR__ . '/dist/phar');

$phar = new Phar(
    __DIR__ . '/dist/ringcentral.phar',
    FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME,
    'ringcentral.phar'
);

function listDir($root, $path, $phar)
{

    //print 'Entering ' . $root . $path . PHP_EOL;

    $it = new DirectoryIterator($root . $path);

    foreach ($it as $fileinfo) {

        $filename = $fileinfo->getFilename();

        if ($fileinfo->isDot() ||
            stristr($filename, 'Test.php') ||
            stristr($filename, '.git') ||
            stristr($filename, 'manual_tests') ||
            stristr($filename, 'tests') ||
            stristr($filename, 'demo') ||
            stristr($filename, 'dist') ||
            stristr($filename, 'docs')
        ) {

            continue;

        } elseif ($fileinfo->isDir()) {

            listDir($root, $path . '/' . $filename, $phar);

        } else {

            $key = ($path ? $path . '/' : '') . $filename;

            $phar[$key] = file_get_contents($root . $path . '/' . $fileinfo->getFilename());

            //print '  ' . $key . ' -> ' . $path . '/' . $filename . PHP_EOL;

        }
    }

}

$branch = shell_exec("git branch | grep \* | cut -d ' ' -f2");

print 'Branch:' . $branch . PHP_EOL;

$json = array(
    'type'              => 'project',
    'minimum-stability' => 'dev',
    'require'           => array(
        'ringcentral/ringcentral-php' => 'dev-' . $branch
    ),
    'repositories' => array(
        array(
            'url'  => __DIR__,
            'type' => 'vcs'
        )
    )
);

print 'Composer config:' . PHP_EOL;
print_r($json);
print PHP_EOL . PHP_EOL;

file_put_contents(__DIR__ . '/dist/phar/composer.json', json_encode($json));

exec('cd ' . __DIR__ . '/dist/phar && composer install --prefer-dist --no-interaction --no-dev');

listDir(__DIR__ . '/dist/phar/vendor', '', $phar);

$phar->setStub($phar->createDefaultStub("autoload.php"));

/////

require(__DIR__ . '/dist/ringcentral.phar');

try {

    if (!file_exists('demo/_credentials.php')) {
        print 'Connection check skipped.';
        exit;
    }

    $credentials = require('demo/_credentials.php');

    $sdk = new RingCentral\SDK\SDK($credentials['clientId'], $credentials['clientSecret'], $credentials['server']);

    $sdk->platform()->login($credentials['username'], $credentials['extension'], $credentials['password']);

    $t = $sdk->platform()->get('/restapi/v1.0');

    print 'Connected to API server ' . $t->json()->uri . ', version ' . $t->json()->versionString . PHP_EOL;

} catch (Exception $e) {
    print 'Error while connecting using PHAR: ' . $e->getMessage();
    exit(1);
}
