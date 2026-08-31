<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Db\Sqlite\Dsn;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\Message\Php\MessageSource;
use Yiisoft\Translator\SimpleMessageFormatter;
use Yiisoft\Translator\Translator;
use Yiisoft\Translator\TranslatorInterface;

abstract class TestCase extends BaseTestCase
{
    protected function createSqliteConnection(): ConnectionInterface
    {
        $dsn = new Dsn('sqlite', ':memory:');
        $driver = new SqliteDriver($dsn);
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('set')->willReturn(true);
        $cache->method('get')->willReturn(null);
        $schemaCache = new SchemaCache($cache);
        $schemaCache->setEnabled(false);
        return new SqliteConnection($driver, $schemaCache);
    }

    protected function createTranslator(string $locale = 'en'): TranslatorInterface
    {
        $translator = new Translator($locale, null, 'voyti');
        $categorySources = [
            new CategorySource(
                'voyti',
                new MessageSource(InstalledVersions::getInstallPath('yiirocks/voyti') . '/resources/messages'),
                new SimpleMessageFormatter(),
            ),
            new CategorySource(
                'voyti-api-stateless-client',
                new MessageSource(dirname(__DIR__) . '/resources/messages'),
                new SimpleMessageFormatter(),
            ),
        ];

        foreach (['yiirocks/voyti-2fa' => 'voyti-2fa', 'yiirocks/voyti-gdpr' => 'voyti-gdpr'] as $package => $category) {
            $categorySources[] = new CategorySource(
                $category,
                new MessageSource(InstalledVersions::getInstallPath($package) . '/resources/messages'),
                new SimpleMessageFormatter(),
            );
        }

        $translator->addCategorySources(...$categorySources);
        return $translator;
    }
}
