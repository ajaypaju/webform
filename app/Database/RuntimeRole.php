<?php

namespace App\Database;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use RuntimeException;

// I11: the runtime is kept off the owner role by credentials, not by FORCE ROW LEVEL SECURITY. This is the check
// that the credentials are what they should be: at boot for api and the consumer, on the first connection for ingest.
final class RuntimeRole
{
    public const EXPECTED = ['api' => 'webform_api', 'ingest' => 'webform_ingest', 'consumer' => 'webform_writer'];

    public static function assertForAppRole(ConnectionInterface $db, ?string $appRole): void
    {
        self::assert($db, self::EXPECTED[$appRole] ?? throw new RuntimeException(
            sprintf('APP_ROLE "%s" has no database role; expected one of %s.', $appRole, implode(', ', array_keys(self::EXPECTED))),
        ));
    }

    /**
     * @throws RuntimeException when the connection is not $expectedRole, or is a role RLS would not bind
     */
    public static function assert(ConnectionInterface $db, string $expectedRole): void
    {
        $role = $db->selectOne(<<<'SQL'
            select current_user as name, rolsuper as superuser, rolbypassrls as bypassrls,
                   exists (select 1 from pg_tables where schemaname = 'public' and tableowner = current_user) as owns_tables
            from pg_roles where rolname = current_user
        SQL);

        if ($role->name !== $expectedRole) {
            throw new RuntimeException("Database connection is {$role->name}; this process must connect as {$expectedRole} (I11).");
        }

        foreach (['superuser', 'bypassrls', 'owns_tables'] as $flag) {
            if ($role->{$flag}) {
                throw new RuntimeException("Database role {$role->name} is {$flag}: row-level security would not apply to it (I11).");
            }
        }
    }

    /** @var array<string, true> connection names currently being checked */
    private static array $checking = [];

    /**
     * Check every new connection before its first query. ConnectionEstablished fires inside
     * DatabaseManager::connection() after the object is registered and before it is returned, so the caller's
     * query cannot run first.
     *
     * WHY: ingest is the data plane and must start while PostgreSQL is down (I12); it can't check at boot.
     */
    public static function checkOnFirstConnection(Dispatcher $events, DatabaseManager $db, string $appRole): void
    {
        $events->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($db, $appRole): void {
            $name = $event->connectionName;

            // WHY: a lost-connection retry inside our own query re-dispatches this event; don't check recursively.
            if (isset(self::$checking[$name])) {
                return;
            }

            self::$checking[$name] = true;

            try {
                self::assertForAppRole($event->connection, $appRole);
            } catch (RuntimeException $e) {
                $db->purge($name);
                self::refuse($e);
            } catch (\Throwable $e) {
                // WHY: the manager already holds this connection; without a purge the next request on this
                // worker would find it registered, skip the check, and query as soon as the database is back.
                $db->purge($name);

                throw $e;
            } finally {
                unset(self::$checking[$name]);
            }
        });
    }

    public static function refuse(RuntimeException $e): never
    {
        // WHY: a worker that dies during boot is reported by FrankenPHP as a generic "worker failed", and
        // error_log() is swallowed at info level; the process's own stderr is what reaches the container log.
        file_put_contents('php://stderr', 'REFUSING TO SERVE: '.$e->getMessage().PHP_EOL);

        throw $e;
    }
}
