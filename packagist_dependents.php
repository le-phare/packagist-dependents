<?php

require __DIR__.'/vendor/autoload.php';

use LePhare\PackagistDependents\Package;
use PrivatePackagist\ApiClient\Client;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Dotenv\Dotenv;

(new SingleCommandApplication())
    ->setName('packagist-dependents')
    //->setVersion('1.0.0')
    ->addArgument('package', InputArgument::REQUIRED, 'Package name with version: vendor/name:version')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $dotenv = new Dotenv();
        $dotenv->load(__DIR__.'/.env');
        $formatterHelper = new FormatterHelper();
        $validPackageName =
            static function (string $value): Package {
                if (!preg_match('{^\s*(?P<vendor>[\S]+)/(?P<name>[\S]+)(?::(?P<version>\S+))\s*$}', $value, $matches)) {
                    throw new InvalidArgumentException('The package name '.$value.' is invalid, it should be lowercase and have a vendor name, a forward slash, a package name, and a version matching: ([a-z0-9_.-]+)\/([a-z0-9_.-]+)(\:)\S+');
                }

                return new Package($matches['vendor'], $matches['name'], $matches['version']);
            };
        $inputPackageName = $input->getArgument('package');
        if (is_array($inputPackageName)) {
            $output->writeln($formatterHelper->formatSection(
            'Error',
            'Invalid package name, string expected'
        ));

            return 1;
        }
        $package = $validPackageName((string) $inputPackageName);
        $packagistClient = $packagistClient = new Client();
        $packagistClient->authenticate($_ENV['PACKAGIST_TOKEN'], $_ENV['PACKAGIST_SECRET']);
        $dependentsByName = $packagistClient->packages()->listDependents($package->vendor.'/'.$package->name);

        $output->writeln(count($dependentsByName)." dependents found for {$package->vendor}/{$package->name}");
        if ($output->isVerbose()) {
            $dependentFoundTable = new Table($output);
            $dependentFoundTable
                ->setHeaders(['name', 'config'])
                ->setRows(
                    array_map(static function (array $dependent) {
                        return [$dependent['name'], json_encode($dependent['config'], JSON_THROW_ON_ERROR)];
                    }, $dependentsByName)
                )
            ;
            $dependentFoundTable->render();
        }

        // Token authentication
        $gitlabComClient = new Gitlab\Client();
        $gitlabComClient->authenticate($_ENV['GITLAB_COM_TOKEN'], Gitlab\Client::AUTH_HTTP_TOKEN);

        $gitlabSelfHostClient = new Gitlab\Client();
        $gitlabSelfHostClient->setUrl($_ENV['GITLAB_SELF_HOST_URL']);
        $gitlabSelfHostClient->authenticate($_ENV['GITLAB_SELF_HOST_TOKEN'], Gitlab\Client::AUTH_HTTP_TOKEN);

        ProgressBar::setFormatDefinition('custom', ' %current%/%max% -- %message%');
        $progressBar = new ProgressBar($output, count($dependentsByName));
        $progressBar->setFormat('custom');
        $progressBar->setMessage('Filtering dependents by version '.$package->version);

        $progressBar->start();

        $dependentsByNameAndVersion = array_filter($dependentsByName, static function (array $dependent) use ($package, $gitlabComClient, $gitlabSelfHostClient, $progressBar) {
            $progressBar->advance();
            $gitlabClient = preg_match('/\bgitlab\.lephare\.io\b/m', $dependent['config']['url']) ? $gitlabSelfHostClient : $gitlabComClient;
            if ('gitlab' === $dependent['config']['type']) {
                $packageName = explode('/', $dependent['name'])[1];
                $gitlabProjects = $gitlabClient->projects()->all(['search' => $packageName, 'simple' => true, 'visibility' => 'private']);
                $progressBar->setMessage('Relative Gitlab projects found for '.$packageName.' : '.implode(', ', array_map(static function (array $project) {
                    return $project['path_with_namespace'];
                }, $gitlabProjects)));
                $normalizedRequires = [];
                foreach ($gitlabProjects as $gitlabProject) {
                    try {
                        $composerJsonFile = $gitlabComClient->repositoryFiles()->getFile($gitlabProject['id'], 'composer.json', 'master');
                        $content = json_decode(base64_decode($composerJsonFile['content']), true, 512, JSON_THROW_ON_ERROR);
                        $requires = $content['require'];
                        $normalizedRequires = [];
                        foreach ($requires as $vendorName => $version) {
                            $normalizedRequires[] = $vendorName.':'.$version;
                        }
                    } catch (\Gitlab\Exception\RuntimeException $e) {
                        $progressBar->setMessage('composer.json not found for '.$gitlabProject['path_with_namespace']);
                    }
                }

                return in_array((string) $package, $normalizedRequires, true);
            }

            return true;
        });
        $progressBar->finish();
        $output->writeln('');
        $output->writeln($formatterHelper->formatSection(
            'Result',
            count($dependentsByNameAndVersion).' dependents with '.$package.' found'
        ));
        $resultTable = new Table($output);
        $resultTable
            ->setHeaders(['name', 'url'])
            ->setRows(array_map(static function (array $dependent) {
                return [$dependent['name'], $dependent['config']['url']];
            }, $dependentsByNameAndVersion))
        ;
        $resultTable->render();

        return 0;
    })
    ->run();
