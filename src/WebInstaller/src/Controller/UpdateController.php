<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\FlexMigrator;
use App\Services\ProjectComposerJsonUpdater;
use App\Services\RecoveryManager;
use App\Services\ReleaseInfoProvider;
use App\Services\StreamedCommandResponseGenerator;
use Shopware\Core\Framework\Log\Package;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @internal
 */
#[Package('core')]
class UpdateController extends AbstractController
{
    public function __construct(
        private readonly RecoveryManager $recoveryManager,
        private readonly ReleaseInfoProvider $releaseInfoProvider,
        private readonly FlexMigrator $flexMigrator,
        private readonly StreamedCommandResponseGenerator $streamedCommandResponseGenerator
    ) {
    }

    #[Route('/update', name: 'update', defaults: ['step' => 2], methods: ['GET'])]
    public function index(Request $request): Response
    {
        $shopwarePath = $this->recoveryManager->getShopwareLocation();

        $currentShopwareVersion = $this->recoveryManager->getCurrentShopwareVersion($shopwarePath);
        $latestVersion = $this->getLatestVersion($request);

        if ($currentShopwareVersion === $latestVersion) {
            return $this->redirectToRoute('finish');
        }

        return $this->render('update.html.twig', [
            'shopwarePath' => $shopwarePath,
            'currentShopwareVersion' => $currentShopwareVersion,
            'isFlexProject' => $this->recoveryManager->isFlexProject($shopwarePath),
            'latestShopwareVersion' => $latestVersion,
        ]);
    }

    #[Route('/update/_migrate-template', name: 'migrate-template', methods: ['POST'])]
    public function migrateTemplate(): Response
    {
        $shopwarePath = $this->recoveryManager->getShopwareLocation();

        $this->flexMigrator->cleanup($shopwarePath);
        $this->flexMigrator->patchRootComposerJson($shopwarePath);
        $this->flexMigrator->copyNewTemplateFiles($shopwarePath);
        $this->flexMigrator->migrateEnvFile($shopwarePath);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/update/_run', name: 'update_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $shopwarePath = $this->recoveryManager->getShopwareLocation();

        ProjectComposerJsonUpdater::update(
            $shopwarePath . '/composer.json',
            $this->getLatestVersion($request),
            $request->getSession()->get('channel', 'stable')
        );

        return $this->streamedCommandResponseGenerator->runJSON([
            $this->recoveryManager->getPhpBinary($request),
            $this->recoveryManager->getBinary(),
            'composer',
            'update',
            '-d',
            $shopwarePath,
            '--no-interaction',
            '--no-ansi',
            '--no-scripts',
            '-v',
            '--with-all-dependencies', // update all packages
        ]);
    }

    #[Route('/update/_reset_config', name: 'update_reset_config', methods: ['POST'])]
    public function resetConfig(Request $request): Response
    {
        if (\function_exists('opcache_reset')) {
            opcache_reset();
        }

        $shopwarePath = $this->recoveryManager->getShopwareLocation();

        $this->patchSymfonyFlex($shopwarePath);

        return $this->streamedCommandResponseGenerator->runJSON([
            $this->recoveryManager->getPhpBinary($request),
            $this->recoveryManager->getBinary(),
            'composer',
            '-d',
            $shopwarePath,
            'symfony:recipes:install',
            '--force',
            '--reset',
            '--no-interaction',
            '--no-ansi',
            '-v',
        ]);
    }

    #[Route('/update/_prepare', name: 'update_prepare', methods: ['POST'])]
    public function prepare(Request $request): Response
    {
        $shopwarePath = $this->recoveryManager->getShopwareLocation();

        return $this->streamedCommandResponseGenerator->runJSON([
            $this->recoveryManager->getPhpBinary($request),
            $shopwarePath . '/bin/console',
            'system:update:prepare',
            '--no-interaction',
        ]);
    }

    #[Route('/update/_finish', name: 'update_finish', methods: ['POST'])]
    public function finish(Request $request): Response
    {
        $shopwarePath = $this->recoveryManager->getShopwareLocation();

        return $this->streamedCommandResponseGenerator->runJSON([
            $this->recoveryManager->getPhpBinary($request),
            $shopwarePath . '/bin/console',
            'system:update:finish',
            '--no-interaction',
        ]);
    }

    /**
     * @see https://github.com/symfony/flex/pull/963
     */
    public function patchSymfonyFlex(string $shopwarePath): void
    {
        $optionsPhp = (string) file_get_contents($shopwarePath . '/vendor/symfony/flex/src/Options.php');

        $optionsPhp = str_replace(
            'return $this->io && $this->io->askConfirmation(sprintf(\'Cannot determine the state of the "%s" file, overwrite anyway? [y/N] \', $file), false);',
            'return $this->io && $this->io->askConfirmation(sprintf(\'Cannot determine the state of the "%s" file, overwrite anyway? [y/N] \', $file));',
            $optionsPhp
        );

        $optionsPhp = str_replace(
            'return $this->io && $this->io->askConfirmation(sprintf(\'File "%s" has uncommitted changes, overwrite? [y/N] \', $name), false);',
            'return $this->io && $this->io->askConfirmation(sprintf(\'File "%s" has uncommitted changes, overwrite? [y/N] \', $name));',
            $optionsPhp
        );

        file_put_contents($shopwarePath . '/vendor/symfony/flex/src/Options.php', $optionsPhp);
    }

    private function getLatestVersion(Request $request): string
    {
        if ($request->getSession()->has('latestVersion')) {
            $sessionValue = $request->getSession()->get('latestVersion');
            \assert(\is_string($sessionValue));

            return $sessionValue;
        }

        $channel = $request->getSession()->get('channel', 'stable');

        $latestVersions = $this->releaseInfoProvider->fetchLatestReleaseForUpdate($channel === 'rc');

        $shopwarePath = $this->recoveryManager->getShopwareLocation();
        \assert(\is_string($shopwarePath));

        $currentVersion = $this->recoveryManager->getCurrentShopwareVersion($shopwarePath);
        $latestVersion = $latestVersions[substr($currentVersion, 0, 3)];

        $first = (int) substr($currentVersion, 0, 1);
        $second = (int) substr($currentVersion, 2, 1);
        ++$second;

        if (isset($latestVersions[$first . '.' . $second])) {
            $latestVersion = $latestVersions[$first . '.' . $second];
        }

        $request->getSession()->set('latestVersion', $latestVersion);

        return $latestVersion;
    }
}
