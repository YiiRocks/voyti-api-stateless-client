<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Support;

use Psr\SimpleCache\CacheInterface;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ConnectionProvider;
use Yiisoft\Db\Driver\Pdo\AbstractPdoConnection;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Db\Sqlite\Dsn;

/**
 * In-memory SQLite with the module's tables the API flows touch: `user` (and the profile/social/
 * session/token/history tables `User::delete()` cascades to, the standalone audit-log table, and
 * `voyti-2fa`/`voyti-2fa-webauthn`'s own tables). Schemas mirror each package's migration, inlined
 * here because this package does not run those migrations.
 */
trait DatabaseSetupTrait
{
    private ?ConnectionInterface $dbConnection = null;

    protected function setUpDatabase(): void
    {
        $dsn = new Dsn('sqlite', ':memory:');
        $driver = new Driver($dsn);
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('set')->willReturn(true);
        $cache->method('get')->willReturn(null);
        $schemaCache = new SchemaCache($cache);
        $schemaCache->setEnabled(false);
        $connection = new SqliteConnection($driver, $schemaCache);
        ConnectionProvider::set($connection);
        $this->dbConnection = $connection;

        $connection->createCommand('
            CREATE TABLE "user" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "username" VARCHAR(255) NOT NULL,
                "email" VARCHAR(255) NOT NULL,
                "password_hash" VARCHAR(255) NOT NULL,
                "auth_key" VARCHAR(32) NOT NULL,
                "blocked_at" INTEGER,
                "confirmed_at" INTEGER,
                "created_at" INTEGER NOT NULL,
                "flags" INTEGER NOT NULL DEFAULT 0,
                "data_processing_consent_date" INTEGER,
                "anonymized" INTEGER NOT NULL DEFAULT 0,
                "last_login_at" INTEGER,
                "last_login_ip" VARCHAR(45),
                "password_changed_at" INTEGER,
                "registration_ip" VARCHAR(45),
                "unconfirmed_email" VARCHAR(255),
                "updated_at" INTEGER NOT NULL
            )
        ')->execute();

        $connection->createCommand('
            CREATE TABLE "user_profile" (
                "user_id" INTEGER NOT NULL PRIMARY KEY,
                "bio" TEXT,
                "birthday" DATE,
                "gravatar_email" VARCHAR(255),
                "location" VARCHAR(255),
                "name" VARCHAR(255),
                "public_email" VARCHAR(255),
                "timezone" VARCHAR(40),
                "website" VARCHAR(255)
            )
        ')->execute();

        $connection->createCommand('
            CREATE TABLE "user_social_account" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "user_id" INTEGER,
                "provider" VARCHAR(255) NOT NULL,
                "client_id" VARCHAR(255) NOT NULL,
                "code" VARCHAR(32),
                "email" VARCHAR(255),
                "username" VARCHAR(255),
                "data" TEXT,
                "created_at" INTEGER NOT NULL
            )
        ')->execute();

        $connection->createCommand('
            CREATE TABLE "user_token" (
                "user_id" INTEGER NOT NULL,
                "code" VARCHAR(64) NOT NULL,
                "type" SMALLINT NOT NULL,
                "created_at" INTEGER NOT NULL,
                PRIMARY KEY ("user_id", "code", "type")
            )
        ')->execute();

        $connection->createCommand('
            CREATE TABLE "user_sessions" (
                "user_id" INTEGER NOT NULL,
                "session_id" VARCHAR(255) NOT NULL,
                "user_agent" TEXT,
                "ip" VARCHAR(45) NOT NULL,
                "created_at" INTEGER NOT NULL,
                "updated_at" INTEGER NOT NULL,
                "revoked_at" INTEGER,
                PRIMARY KEY ("user_id", "session_id")
            )
        ')->execute();

        $connection->createCommand('
            CREATE TABLE "user_password_history" (
                "user_id" INTEGER NOT NULL,
                "password_hash" VARCHAR(255) NOT NULL,
                "created_at" INTEGER NOT NULL,
                PRIMARY KEY ("user_id", "password_hash")
            )
        ')->execute();

        $connection->createCommand('
            CREATE TABLE "user_audit_log" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "actor_user_id" INTEGER,
                "target_user_id" INTEGER,
                "target_name" VARCHAR(255),
                "action" VARCHAR(64) NOT NULL,
                "context" TEXT,
                "actor_ip" VARCHAR(45) NOT NULL,
                "actor_user_agent" TEXT,
                "created_at" INTEGER NOT NULL
            )
        ')->execute();

        $connection->createCommand('
            CREATE TABLE "user_two_factor" (
                "user_id" INTEGER NOT NULL PRIMARY KEY,
                "enabled" boolean NOT NULL DEFAULT 0,
                "secret" VARCHAR(255),
                "method" VARCHAR(64),
                "secret_created_at" INTEGER,
                "secret_attempts" INTEGER NOT NULL DEFAULT 0
            )
        ')->execute();

        $connection->createCommand('
            CREATE TABLE "user_webauthn_credential" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "user_id" INTEGER NOT NULL,
                "credential_id" TEXT NOT NULL,
                "public_key" TEXT NOT NULL,
                "sign_count" INTEGER NOT NULL DEFAULT 0,
                "aaguid" VARCHAR(64),
                "backup_eligible" boolean NOT NULL DEFAULT 0,
                "backed_up" boolean NOT NULL DEFAULT 0,
                "created_at" INTEGER NOT NULL,
                "updated_at" INTEGER NOT NULL
            )
        ')->execute();

        $connection->createCommand('
            CREATE TABLE "user_backup_code" (
                "user_id" INTEGER NOT NULL,
                "code_hash" VARCHAR(255) NOT NULL,
                "used_at" INTEGER,
                "created_at" INTEGER NOT NULL,
                PRIMARY KEY ("user_id", "code_hash")
            )
        ')->execute();

        $connection->createCommand('CREATE UNIQUE INDEX "idx-user-email" ON "user" ("email")')->execute();
        $connection->createCommand('CREATE UNIQUE INDEX "idx-user-username" ON "user" ("username")')->execute();
        $connection->createCommand('CREATE UNIQUE INDEX "idx-user-profile-user-id" ON "user_profile" ("user_id")')->execute();
        $connection->createCommand('CREATE INDEX "idx-user-social-account-user-id" ON "user_social_account" ("user_id")')->execute();
        $connection->createCommand('CREATE UNIQUE INDEX "idx-user-social-account-provider-client-id" ON "user_social_account" ("provider", "client_id")')->execute();
        $connection->createCommand('CREATE UNIQUE INDEX "idx-user-social-account-code" ON "user_social_account" ("code")')->execute();
        $connection->createCommand('CREATE INDEX "idx-user-token-user-id" ON "user_token" ("user_id")')->execute();
        $connection->createCommand('CREATE INDEX "idx-user-sessions-user-id" ON "user_sessions" ("user_id")')->execute();
        $connection->createCommand('CREATE INDEX "idx-user-sessions-session-id" ON "user_sessions" ("session_id")')->execute();
        $connection->createCommand('CREATE INDEX "idx-user-sessions-updated-at" ON "user_sessions" ("updated_at")')->execute();
        $connection->createCommand('CREATE INDEX "idx-user-password-history-user-id" ON "user_password_history" ("user_id")')->execute();
        $connection->createCommand('CREATE INDEX "idx-user-audit-log-actor-user-id" ON "user_audit_log" ("actor_user_id")')->execute();
        $connection->createCommand('CREATE INDEX "idx-user-audit-log-target-user-id" ON "user_audit_log" ("target_user_id")')->execute();
        $connection->createCommand('CREATE INDEX "idx-user-audit-log-created-at" ON "user_audit_log" ("created_at")')->execute();
        $connection->createCommand('CREATE UNIQUE INDEX "idx-user-webauthn-credential-id" ON "user_webauthn_credential" ("credential_id")')->execute();
    }

    protected function tearDownDatabase(): void
    {
        if ($this->dbConnection !== null) {
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_backup_code"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_webauthn_credential"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_two_factor"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_audit_log"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_password_history"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_sessions"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_token"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_social_account"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user_profile"')->execute();
            $this->dbConnection->createCommand('DROP TABLE IF EXISTS "user"')->execute();
        }
        ConnectionProvider::clear();
        $this->dbConnection = null;
    }

    /** Execute a multi-statement SQLite fixture command, such as a trigger definition. */
    protected function executeRawDatabaseCommand(string $sql): void
    {
        if ($this->dbConnection instanceof AbstractPdoConnection) {
            $this->dbConnection->getActivePdo()->exec($sql);
        }
    }
}
