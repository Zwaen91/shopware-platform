<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Update\Api;

use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\Exception\InvalidContextSourceException;
use Shopware\Core\Framework\Api\Context\Exception\InvalidContextSourceUserException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\StaticKernelPluginLoader;
use Shopware\Core\Framework\Store\Services\AbstractExtensionLifecycle;
use Shopware\Core\Framework\Update\Event\UpdatePostFinishEvent;
use Shopware\Core\Framework\Update\Event\UpdatePostPrepareEvent;
use Shopware\Core\Framework\Update\Event\UpdatePreFinishEvent;
use Shopware\Core\Framework\Update\Event\UpdatePrePrepareEvent;
use Shopware\Core\Framework\Update\Exception\UpdateFailedException;
use Shopware\Core\Framework\Update\Services\ApiClient;
use Shopware\Core\Framework\Update\Services\PluginCompatibility;
use Shopware\Core\Framework\Update\Services\RequirementsValidator;
use Shopware\Core\Framework\Update\Steps\DeactivateExtensionsStep;
use Shopware\Core\Framework\Update\Steps\DownloadStep;
use Shopware\Core\Framework\Update\Steps\FinishResult;
use Shopware\Core\Framework\Update\Steps\UnpackStep;
use Shopware\Core\Framework\Update\Steps\ValidResult;
use Shopware\Core\Framework\Update\Struct\Version;
use Shopware\Core\Framework\Update\VersionFactory;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Kernel;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\User\UserEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * @internal
 */
#[Route(defaults: ['_routeScope' => ['api']])]
#[Package('system-settings')]
class UpdateController extends AbstractController
{
    final public const UPDATE_TOKEN_KEY = 'core.update.token';
    final public const UPDATE_PREVIOUS_VERSION_KEY = 'core.update.previousVersion';

    /**
     * @internal
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly ApiClient $apiClient,
        private readonly RequirementsValidator $requirementsValidator,
        private readonly PluginCompatibility $pluginCompatibility,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SystemConfigService $systemConfig,
        private readonly AbstractExtensionLifecycle $extensionLifecycleService,
        private readonly EntityRepository $userRepository,
        private readonly string $shopwareVersion,
        private readonly bool $isUpdateTest = false
    ) {
    }

    #[Route(path: '/api/_action/update/check', name: 'api.custom.updateapi.check', methods: ['GET'], defaults: ['_acl' => ['system:core:update']])]
    public function updateApiCheck(): JsonResponse
    {
        if ($this->isUpdateTest) {
            $version = VersionFactory::createTestVersion();

            return new JsonResponse($version);
        }

        try {
            $updates = $this->apiClient->checkForUpdates();

            if (!$updates->isNewer) {
                return new JsonResponse();
            }

            return new JsonResponse($updates);
        } catch (\Throwable $e) {
            return new JsonResponse([
                '__class' => $e::class,
                '__message' => $e->getMessage(),
            ]);
        }
    }

    #[Route(path: '/api/_action/update/check-requirements', name: 'api.custom.update.check_requirements', methods: ['GET'], defaults: ['_acl' => ['system:core:update']])]
    public function checkRequirements(): JsonResponse
    {
        $update = $this->apiClient->checkForUpdates($this->shopwareVersion === Kernel::SHOPWARE_FALLBACK_VERSION);

        return new JsonResponse($this->requirementsValidator->validate($update));
    }

    #[Route(path: '/api/_action/update/plugin-compatibility', name: 'api.custom.updateapi.plugin_compatibility', methods: ['GET'], defaults: ['_acl' => ['system:core:update', 'system_config:read']])]
    public function pluginCompatibility(Context $context): JsonResponse
    {
        $update = $this->apiClient->checkForUpdates($this->shopwareVersion === Kernel::SHOPWARE_FALLBACK_VERSION);

        return new JsonResponse($this->pluginCompatibility->getExtensionCompatibilities($update, $context));
    }

    #[Route(path: '/api/_action/update/download-latest-update', name: 'api.custom.updateapi.download_latest_update', methods: ['GET'], defaults: ['_acl' => ['system:core:update', 'system_config:read']])]
    public function downloadLatestUpdate(Request $request): JsonResponse
    {
        $update = $this->apiClient->checkForUpdates($this->shopwareVersion === Kernel::SHOPWARE_FALLBACK_VERSION);
        $offset = $request->query->getInt('offset');

        $destination = $this->createDestinationFromVersion($update);

        if ($offset === 0 && file_exists($destination)) {
            unlink($destination);
        }

        $result = (new DownloadStep(
            $update,
            $destination,
            $this->shopwareVersion === Kernel::SHOPWARE_FALLBACK_VERSION
        ))->run($offset);

        return $this->toJson($result);
    }

    #[Route(path: '/api/_action/update/unpack', name: 'api.custom.updateapi.unpack', methods: ['GET'], defaults: ['_acl' => ['system:core:update', 'system_config:read']])]
    public function unpack(Request $request, Context $context): JsonResponse
    {
        $update = $this->apiClient->checkForUpdates($this->shopwareVersion === Kernel::SHOPWARE_FALLBACK_VERSION);

        $source = $this->createDestinationFromVersion($update);
        $offset = $request->query->getInt('offset');

        $fs = new Filesystem();

        $updateDir = $this->rootDir . '/files/update/';
        $fileDir = $this->rootDir . '/files/update/files';

        $unpackStep = new UnpackStep($source, $fileDir, $this->shopwareVersion === Kernel::SHOPWARE_FALLBACK_VERSION);

        if ($offset === 0) {
            $fs->remove($updateDir);
        }

        $result = $unpackStep->run($offset);

        // Test Mode
        if ($result instanceof FinishResult && $this->shopwareVersion === Kernel::SHOPWARE_FALLBACK_VERSION) {
            $updateToken = Uuid::randomHex();
            $this->systemConfig->set(self::UPDATE_TOKEN_KEY, $updateToken);

            return new JsonResponse([
                'redirectTo' => $request->getBaseUrl() . '/api/_action/update/finish/' . $updateToken,
            ]);
        }

        if ($result instanceof FinishResult) {
            $fs->rename($fileDir . '/update-assets/', $updateDir . '/update-assets/');
            $this->replaceRecoveryFiles($fileDir);

            $payload = [
                'clientIp' => $request->getClientIp(),
                'version' => $update->version,
                'locale' => $this->getUpdateLocale($context),
            ];

            $updateFilePath = $this->rootDir . '/files/update/update.json';

            if (!file_put_contents($updateFilePath, json_encode($payload, \JSON_THROW_ON_ERROR))) {
                throw new UpdateFailedException(sprintf('Could not write file %s', $updateFilePath));
            }

            $this->systemConfig->set(self::UPDATE_PREVIOUS_VERSION_KEY, $update->version);

            return new JsonResponse([
                'redirectTo' => $request->getBaseUrl() . '/recovery/update/index.php',
            ]);
        }

        return $this->toJson($result);
    }

    #[Route(path: '/api/_action/update/deactivate-plugins', name: 'api.custom.updateapi.deactivate-plugins', methods: ['GET'], defaults: ['_acl' => ['system:core:update', 'system_config:read']])]
    public function deactivatePlugins(Request $request, Context $context): JsonResponse
    {
        $update = $this->apiClient->checkForUpdates($this->shopwareVersion === Kernel::SHOPWARE_FALLBACK_VERSION);

        $offset = $request->query->getInt('offset');

        if ($offset === 0) {
            // plugins can subscribe to this events, check compatibility and throw exceptions to prevent the update
            $this->eventDispatcher->dispatch(
                new UpdatePrePrepareEvent($context, $this->shopwareVersion, $update->version)
            );
        }

        // disable plugins - save active plugins
        $deactivationFilter = (string) $request->query->get(
            'deactivationFilter',
            PluginCompatibility::PLUGIN_DEACTIVATION_FILTER_NOT_COMPATIBLE
        );

        $deactivatePluginStep = new DeactivateExtensionsStep(
            $update,
            $deactivationFilter,
            $this->pluginCompatibility,
            $this->extensionLifecycleService,
            $this->systemConfig,
            $context
        );

        $result = $deactivatePluginStep->run($offset);

        if ($result instanceof FinishResult) {
            $containerWithoutPlugins = $this->rebootKernelWithoutPlugins();

            // @internal plugins are deactivated
            $containerWithoutPlugins->get('event_dispatcher')->dispatch(
                new UpdatePostPrepareEvent($context, $this->shopwareVersion, $update->version)
            );
        }

        return $this->toJson($result);
    }

    #[Route(path: '/api/_action/update/finish/{token}', defaults: ['auth_required' => false, '_acl' => ['system:core:update', 'system_config:read']], name: 'api.custom.updateapi.finish', methods: ['GET'])]
    public function finish(string $token, Request $request, Context $context): Response
    {
        $offset = $request->query->getInt('offset');
        $oldVersion = $this->systemConfig->getString(self::UPDATE_PREVIOUS_VERSION_KEY);
        if ($offset === 0) {
            if (!$token) {
                return $this->getFinishResponse();
            }

            $dbUpdateToken = $this->systemConfig->getString(self::UPDATE_TOKEN_KEY);
            if (!$dbUpdateToken || $token !== $dbUpdateToken) {
                return $this->getFinishResponse();
            }

            $_unusedPreviousSetting = ignore_user_abort(true);

            $this->eventDispatcher->dispatch(new UpdatePreFinishEvent($context, $oldVersion, $this->shopwareVersion));
        }

        // TODO: NEXT-8271 - The kernel should be rebooted with the plugins reactivated. This does not happen to save some time, because plugins were not reactivated anyway.
        $this->eventDispatcher->dispatch(
            new UpdatePostFinishEvent($context, $oldVersion, $this->shopwareVersion)
        );

        return $this->getFinishResponse();
    }

    private function getUpdateLocale(Context $context): string
    {
        $contextSource = $context->getSource();
        if (!($contextSource instanceof AdminApiSource)) {
            throw new InvalidContextSourceException(AdminApiSource::class, $contextSource::class);
        }

        $userId = $contextSource->getUserId();
        if ($userId === null) {
            throw new InvalidContextSourceUserException($contextSource::class);
        }

        $criteria = new Criteria([$userId]);
        $criteria->getAssociation('locale');

        /** @var UserEntity|null $user */
        $user = $this->userRepository->search($criteria, $context)->first();

        if ($user && $user->getLocale()) {
            $code = $user->getLocale()->getCode();

            return mb_strtolower(explode('-', $code)[0]);
        }

        return 'en';
    }

    private function rebootKernelWithoutPlugins(): ContainerInterface
    {
        /** @var Kernel $kernel */
        $kernel = $this->container->get('kernel');

        $classLoad = $kernel->getPluginLoader()->getClassLoader();
        $kernel->reboot(null, new StaticKernelPluginLoader($classLoad));

        return $kernel->getContainer();
    }

    private function toJson(ValidResult|FinishResult $result): JsonResponse
    {
        if ($result instanceof FinishResult) {
            return new JsonResponse([
                'valid' => false,
                'offset' => $result->getOffset(),
                'total' => $result->getTotal(),
                'success' => true,
                '_class' => $result::class,
            ]);
        }

        return new JsonResponse([
            'valid' => true,
            'offset' => $result->getOffset(),
            'total' => $result->getTotal(),
            'success' => true,
            '_class' => $result::class,
        ]);
    }

    private function replaceRecoveryFiles(string $fileDir): void
    {
        $recoveryDir = $fileDir . '/vendor/shopware/recovery';
        if (!is_dir($recoveryDir)) {
            return;
        }

        $iterator = $this->createRecursiveFileIterator($recoveryDir);

        $fs = new Filesystem();

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $sourceFile = $file->getPathname();
            $destinationFile = $this->rootDir . '/' . str_replace($fileDir, '', $file->getPathname());

            $destinationDirectory = \dirname($destinationFile);
            $fs->mkdir($destinationDirectory);
            $fs->rename($sourceFile, $destinationFile, true);
        }
    }

    /**
     * @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator>
     */
    private function createRecursiveFileIterator(string $path): \RecursiveIteratorIterator
    {
        $directoryIterator = new \RecursiveDirectoryIterator(
            $path,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );

        return new \RecursiveIteratorIterator(
            $directoryIterator,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
    }

    private function createDestinationFromVersion(Version $version): string
    {
        $filename = 'update_' . $version->sha1 . '.zip';

        return $this->rootDir . '/' . $filename;
    }

    private function getFinishResponse(): Response
    {
        try {
            return $this->redirectToRoute('administration.index');
        } catch (RouteNotFoundException) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }
    }
}
