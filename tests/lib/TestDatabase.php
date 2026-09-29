<?php
/**
 * Builds and resets the throwaway test database from database/ecotrack.sql,
 * so the tests always run against the same schema a real install gets.
 */
final class TestDatabase
{
    /**
     * Drop and recreate the test database from the schema file.
     */
    public static function create(): void
    {
        $server = new PDO(
            sprintf('mysql:host=%s;charset=%s', DB_HOST, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $server->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
        $server->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $server->exec('USE `' . DB_NAME . '`');

        foreach (schemaDefinition()['tables'] as $createTable) {
            $server->exec($createTable);
        }
    }

    /**
     * Empty every table and reload the seed rows. Runs before each test.
     */
    public static function reset(): void
    {
        $pdo = getPDO();
        $schema = schemaDefinition();

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_keys($schema['tables']) as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        foreach ($schema['seeds'] as $statement) {
            $pdo->exec($statement);
        }
    }
}
