<?php

namespace OCA\HelpLinks\Service;

use OCA\HelpLinks\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * The IntroVox tours a user may get, kept per user and refreshed at most once
 * a day, and only when that user opens the help page. IntroVox has no public
 * API for this, so its TourRepository is used as is, behind guards: if
 * anything about it changes, the help page falls back to the plain link.
 *
 * The list is a user preference (helplinks / introvox_tours), so Nextcloud
 * removes it with the account, whether this app is enabled then or not.
 */
class TourService {
    private const INTROVOX = 'introvox';
    private const REPOSITORY = 'OCA\IntroVox\Service\TourRepository';
    private const WELCOME = 'welcome';
    private const ANYWHERE_PAGE = '/apps/files/';
    private const MAX_AGE = 86400;
    private const KEY = 'introvox_tours';
    // Raise when the stored fields change, so every list is collected anew
    private const FORMAT = 2;

    private $appManager;
    private $appConfig;
    private $config;
    private $groupManager;
    private $navigationManager;
    private $l10nFactory;
    private $logger;

    public function __construct(
        IAppManager $appManager,
        IAppConfig $appConfig,
        IConfig $config,
        IGroupManager $groupManager,
        INavigationManager $navigationManager,
        IFactory $l10nFactory,
        LoggerInterface $logger,
    ) {
        $this->appManager = $appManager;
        $this->appConfig = $appConfig;
        $this->config = $config;
        $this->groupManager = $groupManager;
        $this->navigationManager = $navigationManager;
        $this->l10nFactory = $l10nFactory;
        $this->logger = $logger;
    }

    public function isAvailable(IUser $user): bool {
        return $this->appManager->isEnabledForUser(self::INTROVOX, $user)
            && class_exists(self::REPOSITORY);
    }

    /**
     * Tours as [{id, name, page, app: {name, icon}|null}], the welcome tour first.
     * Null when IntroVox is not there for this user or could not be read.
     */
    public function getForUser(IUser $user): ?array {
        if (!$this->isAvailable($user)) {
            return null;
        }
        try {
            $repository = Server::get(self::REPOSITORY);
            $hash = $this->sourceHash($repository, $user);
            $stored = json_decode($this->read($user->getUID()), true);

            if (is_array($stored)
                && ($stored['hash'] ?? null) === $hash
                && ($stored['fetchedAt'] ?? 0) > time() - self::MAX_AGE
                && is_array($stored['tours'] ?? null)) {
                return $stored['tours'];
            }

            $tours = $this->collect($repository, $user);
            $this->write($user->getUID(), json_encode(['hash' => $hash, 'fetchedAt' => time(), 'tours' => $tours]));
            return $tours;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read IntroVox tours', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Everything that decides the list without computing it: the stored format, a tour added or
     * changed by an admin, the main switch, and this user's language, groups
     * and apps. A change in any of these refreshes the list on the next visit.
     */
    private function sourceHash($repository, IUser $user): string {
        $groups = $this->groupManager->getUserGroupIds($user);
        $apps = $this->appManager->getEnabledAppsForUser($user);
        sort($groups);
        sort($apps);
        return sha1(json_encode([
            self::FORMAT,
            $this->appConfig->getValueString(self::INTROVOX, 'tours', '[]'),
            $repository->toursEnabled(),
            $this->l10nFactory->getUserLanguage($user),
            $groups,
            $apps,
        ]));
    }

    /**
     * The same list IntroVox shows in its personal settings, plus the
     * welcome tour when this user gets it.
     */
    private function collect($repository, IUser $user): array {
        $apps = [];
        foreach ($this->navigationManager->getAll(INavigationManager::TYPE_APPS) as $entry) {
            $id = $entry['app'] ?? $entry['id'] ?? null;
            if (is_string($id)) {
                $apps[$id] = ['name' => (string)($entry['name'] ?? $id), 'icon' => (string)($entry['icon'] ?? '')];
            }
        }

        $tours = [];
        if ($repository->forUser(self::WELCOME, $user) !== null) {
            $tours[] = ['id' => self::WELCOME, 'name' => '', 'page' => self::ANYWHERE_PAGE, 'app' => null];
        }
        foreach ($repository->all() as $tour) {
            if (!is_array($tour) || !is_string($tour['id'] ?? null)
                || $tour['id'] === self::WELCOME || !$repository->availableTo($tour, $user)) {
                continue;
            }
            $trigger = $tour['trigger'] ?? [];
            $app = ($trigger['type'] ?? '') === 'app' ? ($trigger['app'] ?? null) : null;
            $tours[] = [
                'id' => $tour['id'],
                'name' => (string)($tour['name'] ?? $tour['id']),
                'page' => $this->startPage($trigger),
                'app' => is_string($app) ? ($apps[$app] ?? null) : null,
            ];
        }
        return $tours;
    }

    /**
     * Where the tour starts, as IntroVox's TourValidator::triggerPage() has
     * it: the tour's app or page, and the Files app for a tour that starts
     * anywhere.
     */
    private function startPage(array $trigger): string {
        $page = match ($trigger['type'] ?? '') {
            'app' => is_string($trigger['app'] ?? null) ? '/apps/' . $trigger['app'] . '/' : '',
            'page' => is_string($trigger['page'] ?? null) ? $trigger['page'] : '',
            default => '',
        };
        return str_starts_with($page, '/') ? $page : self::ANYWHERE_PAGE;
    }

    /**
     * Lazy from Nextcloud 32 (IUserConfig): read only on the help page, not
     * with every request of this user. Before that, a plain user value.
     */
    private function read(string $userId): string {
        if (interface_exists(IUserConfig::class)) {
            return Server::get(IUserConfig::class)->getValueString($userId, Application::APP_ID, self::KEY, '', true);
        }
        return $this->config->getUserValue($userId, Application::APP_ID, self::KEY, '');
    }

    private function write(string $userId, string $value): void {
        if (interface_exists(IUserConfig::class)) {
            Server::get(IUserConfig::class)->setValueString($userId, Application::APP_ID, self::KEY, $value, true);
            return;
        }
        $this->config->setUserValue($userId, Application::APP_ID, self::KEY, $value);
    }
}
